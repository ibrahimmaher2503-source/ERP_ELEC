<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CustomerAdvances
{
    public function remaining(int $advance, ?string $to = null): string
    {
        $source = DB::table('rp_customer_advances')->find($advance);
        abort_unless($source, 404);
        if (DB::table('rp_cash_reversals')->where('original_movement_id', $source->movement_id)->when($to, fn ($q) => $q->where('effective_date', '<=', $to))->exists()) {
            return '0.00';
        }
        $original = $source->original_amount;
        $applied = (string) DB::table('rp_customer_advance_applications')->where('advance_id', $advance)->when($to, fn ($q) => $q->where('effective_date', '<=', $to))->sum('amount');
        $refunded = (string) DB::table('rp_customer_advance_refunds')->where('advance_id', $advance)->when($to, fn ($q) => $q->where('refund_date', '<=', $to))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rp_cash_reversals')->whereColumn('original_movement_id', 'rp_customer_advance_refunds.movement_id')->when($to, fn ($q) => $q->where('effective_date', '<=', $to)))->sum('amount');

        return bcsub(bcsub($original, $applied, 2), $refunded, 2);
    }

    private function advance(int $actor, int $id, string $date, string $permission): object
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(AccountingApi::class)->lockPosting();
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        $advance = DB::table('rp_customer_advances')->lockForUpdate()->find($id);
        abort_unless($advance, 404);
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $permission, (int) $advance->branch_id));
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية المقدم أو نطاق الفرع غير متاح.');
        }
        app(ReceivablesPayablesAccess::class)->authorize($actor, 'ar_view', (int) $advance->branch_id);
        $movement = app(TreasuryApi::class)->advanceMovementEvidence($actor, (int) $advance->movement_id, (int) $advance->branch_id, (int) $advance->customer_id);
        abort_unless($movement['kind'] === 'customer_collection' && $date >= $movement['date'], 422, 'تطبيق أو رد المقدم لا يسبق تحصيله.');

        return $advance;
    }

    public function apply(int $actor, int $advanceId, string $creationKey, string $date, array $allocations): array
    {
        Validator::make(compact('creationKey'), ['creationKey' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $advanceId, $creationKey, $date, $allocations) {
            $a = $this->advance($actor, $advanceId, $date, 'M12.advance_apply');
            $canonical = [];
            foreach ($allocations as $line) {
                Validator::make($line, ['open_item_id' => 'required|integer|min:1', 'amount' => 'required|string'])->validate();
                $canonical[] = ['open_item_id' => (int) $line['open_item_id'], 'amount' => bcadd(Decimal::value($line['amount'], 2), '0', 2)];
            }
            usort($canonical, fn ($left, $right) => $left['open_item_id'] <=> $right['open_item_id']);
            $hash = hash('sha256', json_encode([$advanceId, $date, $canonical], JSON_THROW_ON_ERROR));
            $old = DB::table('rp_customer_advance_applications')->where('creation_key', $creationKey)->first();
            if ($old) {
                abort_unless((int) $old->advance_id === $advanceId && (int) $old->created_by === $actor && hash_equals($old->payload_hash, $hash), 409);

                return ['application_id' => (int) $old->id, 'journal_id' => (int) $old->journal_id, 'amount' => $old->amount];
            }
            $prepared = app(Settlements::class)->prepare($actor, 'customer', (int) $a->customer_id, $canonical);
            abort_unless($prepared['branch_id'] === (int) $a->branch_id && bccomp($prepared['amount'], $this->remaining($advanceId), 2) <= 0, 422, 'التطبيق يتجاوز رصيد المقدم أو يخص فرعاً آخر.');
            foreach ($prepared['items'] as $item) {
                abort_unless($date >= $item['document_date'], 422, 'لا يسبق التطبيق تاريخ الذمة.');
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $actor, (int) $a->branch_id, $date, 'customer-advance-application:'.$creationKey,
                'تطبيق مقدم عميل #'.$advanceId, [['purpose' => 'customer_advance', 'debit' => $prepared['amount'], 'credit' => '0.00'], ['purpose' => 'receivable', 'debit' => '0.00', 'credit' => $prepared['amount']]]);
            $id = DB::table('rp_customer_advance_applications')->insertGetId(['advance_id' => $advanceId, 'creation_key' => $creationKey, 'effective_date' => $date,
                'amount' => $prepared['amount'], 'journal_id' => $journal, 'payload_hash' => $hash, 'created_by' => $actor]);
            foreach ($canonical as $line) {
                $item = (object) $prepared['items'][$line['open_item_id']];
                DB::table('rp_customer_advance_application_lines')->insert(['application_id' => $id] + $line);
                DB::table('rp_open_items')->where('id', $item->id)->update(['settled_amount' => bcadd($item->settled_amount, $line['amount'], 2)]);
                if ($item->source_type === 'customer_invoice') {
                    DB::table('rp_customer_invoices')->where('id', $item->source_id)->update(['status' => bccomp($item->remaining_amount, $line['amount'], 2) === 0 ? 'collected' : 'partially_collected', 'updated_at' => now()]);
                }
            }
            app(RecordAudit::class)->execute($actor, 'customer_advance', $advanceId, 'advance.applied', null, ['application_id' => $id, 'journal_id' => $journal, 'amount' => $prepared['amount']], 'succeeded', 'ReceivablesPayables');

            return ['application_id' => $id, 'journal_id' => $journal, 'amount' => $prepared['amount']];
        }, 3);
    }

    public function prepareRefund(int $actor, int $advance, string $amount, string $date): array
    {
        $a = $this->advance($actor, $advance, $date, 'M13.advance_refund');
        Decimal::value($amount, 2);
        abort_unless(bccomp($amount, '0', 2) > 0 && bccomp($amount, $this->remaining($advance), 2) <= 0, 422, 'الرد يتجاوز المتبقي من المقدم.');

        return ['advance_id' => $advance, 'branch_id' => (int) $a->branch_id, 'customer_id' => (int) $a->customer_id];
    }

    public function completeRefund(int $actor, int $advance, int $movement, string $amount, string $date, string $key): void
    {
        $a = $this->prepareRefund($actor, $advance, $amount, $date);
        $proof = app(TreasuryApi::class)->advanceMovementEvidence($actor, $movement, $a['branch_id'], $a['customer_id']);
        abort_unless($proof['kind'] === 'customer_advance_refund' && $proof['advance_id'] === $advance && $proof['date'] === $date && $proof['key'] === $key && bccomp($proof['amount'], $amount, 2) === 0, 409);
        DB::table('rp_customer_advance_refunds')->insert(['advance_id' => $advance, 'movement_id' => $movement, 'amount' => $amount, 'refund_date' => $date, 'creation_key' => $key, 'created_by' => $actor]);
        app(RecordAudit::class)->execute($actor, 'customer_advance', $advance, 'advance.refunded', null, ['movement_id' => $movement, 'amount' => $amount], 'succeeded', 'ReceivablesPayables');
    }
}

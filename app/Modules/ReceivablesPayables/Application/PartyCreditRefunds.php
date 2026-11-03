<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PartyCreditRefunds
{
    public function prepare(int $actor, int $credit, string $amount, string $date): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(AccountingApi::class)->lockPosting();
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        Decimal::value($amount, 2);
        $c = DB::table('rp_party_credits')->lockForUpdate()->find($credit);
        abort_unless($c, 404);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M13.credit_refund', (int) $c->branch_id));
        app(ReceivablesPayablesAccess::class)->authorize($actor, $c->party_type === 'customer' ? 'ar_view' : 'ap_view', (int) $c->branch_id);
        $note = DB::table('rp_credit_notes')->find($c->credit_note_id);
        abort_unless($note && $note->status === 'posted' && $date >= $note->note_date, 422, 'رد الرصيد لا يسبق تاريخ الإشعار الدائن المرحّل.');
        abort_unless(bccomp($amount, '0', 2) > 0 && bccomp($amount, $c->remaining_amount, 2) <= 0, 422, 'الرد يتجاوز الرصيد الدائن المتبقي.');

        return ['credit_id' => $credit, 'branch_id' => (int) $c->branch_id, 'party_type' => $c->party_type, 'party_id' => (int) $c->party_id];
    }

    public function complete(int $actor, int $credit, int $movement, string $amount, string $date, string $key): void
    {
        $c = $this->prepare($actor, $credit, $amount, $date);
        $proof = app(TreasuryApi::class)->partyCreditMovementEvidence($actor, $movement, $c['branch_id'], $c['party_type'], $c['party_id']);
        abort_unless($proof['credit_id'] === $credit && $proof['date'] === $date && $proof['key'] === $key && bccomp($proof['amount'], $amount, 2) === 0, 409);
        DB::table('rp_party_credit_refunds')->insert(['credit_id' => $credit, 'movement_id' => $movement, 'amount' => $amount, 'refund_date' => $date, 'creation_key' => $key, 'created_by' => $actor]);
        DB::table('rp_party_credits')->where('id', $credit)->increment('allocated_amount', $amount);
        app(RecordAudit::class)->execute($actor, 'party_credit', $credit, 'credit.refunded', null, ['movement_id' => $movement, 'amount' => $amount], 'succeeded', 'ReceivablesPayables');
    }
}

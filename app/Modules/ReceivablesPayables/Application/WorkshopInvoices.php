<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\System\Application\RecordAudit;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkshopInvoices
{
    public function handoff(int $actor, int $work, int $branch): ?array
    {
        app(WorkshopApi::class)->authorizeReconciliation($actor, $work, $branch);
        if (app(ReceivablesPayablesAccess::class)->allows($actor, 'ar_view', $branch)) {
            return $this->status($actor, $work, $branch);
        }
        $i = DB::table('rp_customer_invoices')->where('workshop_job_id', $work)->where('branch_id', $branch)->first();

        return $i ? ['id' => $i->id, 'status' => $i->status, 'number' => $i->official_number, 'version' => $i->version, 'net' => null, 'tax' => null, 'total' => null, 'remaining' => null, 'balance_available' => false, 'can_view' => app(ReceivablesPayablesAccess::class)->allows($actor, 'customer_invoice_view', $branch)] : null;
    }

    public function validateSource(int $actor, object $invoice): void
    {
        $source = json_decode($invoice->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        app(WorkshopApi::class)->validateBillingEvidence(
            $actor,
            (int) $invoice->workshop_job_id,
            (int) $invoice->branch_id,
            (int) $invoice->customer_id,
            isset($source['approval_id']) ? (int) $source['approval_id'] : null,
            isset($source['cancellation_id']) ? (int) $source['cancellation_id'] : null
        );
    }

    public function create(int $actor, array $input): int
    {
        $d = Validator::make($input, ['job_id' => 'required|integer|min:1', 'creation_key' => 'required|uuid', 'invoice_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:invoice_date'])->validate();

        return DB::transaction(function () use ($actor, $d) {
            $source = app(WorkshopApi::class)->billingSource($actor, (int) $d['job_id']);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'customer_invoice_manage', $source['branch_id']);
            $old = DB::table('rp_customer_invoices')->where('creation_key', $d['creation_key'])->first();
            if ($old) {
                abort_unless($old->workshop_job_id === (int) $d['job_id'] && $old->created_by === $actor && $old->invoice_date === $d['invoice_date'] && $old->due_date === $d['due_date'], 409);

                return $old->id;
            }
            abort_if(DB::table('rp_customer_invoices')->where('workshop_job_id', $d['job_id'])->exists(), 409, 'الخدمة محالة للفوترة بالفعل.');
            $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $source['customer_id']));
            app(AccountingApi::class)->requirePostingDate($d['invoice_date']);
            // A repair may finish after the offer date. Resolve the genuine fiscal-date
            // rule, while preserving the exact gross amount accepted by the customer.
            $tax = app(AccountingApi::class)->calculateTax(
                $actor,
                'sale',
                $d['invoice_date'],
                'exclusive',
                [['product_id' => $source['product_id'], 'quantity' => '1', 'unit_price' => $source['net_amount'], 'discount_amount' => '0.00']],
                '0',
                ['treatment' => $source['tax_treatment'] ?? 'rule', 'reason' => $source['tax_exempt_reason'] ?? null]
            );
            abort_unless(bccomp($tax['lines'][0]['tax'], $source['tax_amount'], 2) === 0, 409, 'تغيرت الضريبة عن العرض المقبول؛ يلزم قرار تجاري وضريبي موثق قبل الفوترة.');
            $source['quoted_tax'] = $source['tax'];
            $source['tax'] = $tax['lines'][0] + ['approved_tax_date' => $d['invoice_date']];
            $total = bcadd($source['net_amount'], $source['tax_amount'], 2);
            $id = DB::table('rp_customer_invoices')->insertGetId([
                'creation_key' => $d['creation_key'], 'branch_id' => $source['branch_id'], 'customer_id' => $customer->id,
                'customer_name' => $customer->name, 'project_id' => $source['project_id'], 'workshop_job_id' => $d['job_id'],
                'invoice_date' => $d['invoice_date'], 'due_date' => $d['due_date'],
                'tax_treatment' => $source['tax_treatment'] ?? 'rule', 'tax_exempt_reason' => $source['tax_exempt_reason'] ?? null,
                'net_amount' => $source['net_amount'], 'tax_amount' => $source['tax_amount'], 'total_amount' => $total,
                'created_by' => $actor, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR),
            ]);
            DB::table('rp_customer_invoice_lines')->insert([
                'invoice_id' => $id,
                'workshop_approval_id' => $source['approval_id'] ?? null,
                'workshop_cancellation_id' => $source['cancellation_id'] ?? null,
                'product_id' => $source['product_id'],
                'description' => mb_substr($source['description'], 0, 500), 'quantity' => '1',
                'net_amount' => $source['net_amount'], 'tax_amount' => $source['tax_amount'], 'total_amount' => $total,
                'tax_rule_id' => $source['tax']['tax_rule_id'] ?? null, 'tax_snapshot' => json_encode($source['tax'], JSON_THROW_ON_ERROR),
            ]);
            app(RecordAudit::class)->execute($actor, 'customer_invoice', $id, 'workshop_invoice.created', null, [
                'job_id' => $d['job_id'], 'approval_id' => $source['approval_id'] ?? null, 'cancellation_id' => $source['cancellation_id'] ?? null,
                'tax_treatment' => $source['tax_treatment'] ?? 'rule', 'tax_exempt_reason' => $source['tax_exempt_reason'] ?? null,
            ], 'succeeded', 'ReceivablesPayables');

            return $id;
        }, 5);
    }

    public function status(int $actor, int $work, int $branch): ?array
    {
        app(ReceivablesPayablesAccess::class)->authorize($actor, 'ar_view', $branch);
        $i = DB::table('rp_customer_invoices')->where('workshop_job_id', $work)->where('branch_id', $branch)->first();
        if (! $i) {
            return null;
        }
        $open = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $i->id)->first();

        return ['id' => $i->id, 'status' => $i->status, 'version' => $i->version, 'number' => $i->official_number, 'net' => $i->net_amount, 'tax' => $i->tax_amount, 'total' => $i->total_amount, 'remaining' => $open?->remaining_amount, 'open_item_id' => $open?->id, 'journal_id' => $i->journal_id, 'balance_available' => true, 'can_view' => app(ReceivablesPayablesAccess::class)->allows($actor, 'customer_invoice_view', $branch)];
    }

    public function ledger(int $actor, int $job, int $branch): array
    {
        app(WorkshopApi::class)->authorizeReconciliation($actor, $job, $branch);
        if (! app(ReceivablesPayablesAccess::class)->allows($actor, 'ar_view', $branch)) {
            return ['available' => false, 'advances' => [], 'collections' => [], 'totals' => null];
        }

        $advanceRows = DB::table('rp_customer_advances as a')
            ->join('treasury_movements as m', 'm.id', '=', 'a.movement_id')
            ->where('a.workshop_job_id', $job)
            ->where('a.branch_id', $branch)
            ->orderBy('a.id')
            ->get([
                'a.id',
                'a.movement_id',
                'a.original_amount',
                'a.created_at',
                'm.movement_date',
                'm.reference',
                'm.journal_id',
                'm.official_document_id',
            ]);

        $movementIds = $advanceRows->pluck('movement_id')->all();
        $reversedMovements = $movementIds === [] ? [] : DB::table('rp_cash_reversals')
            ->whereIn('original_movement_id', $movementIds)
            ->pluck('original_movement_id')
            ->flip()
            ->all();

        $advancesHelper = app(CustomerAdvances::class);
        $advancesCollected = '0.00';
        $advancesApplied = '0.00';
        $advancesRefunded = '0.00';
        $advancesRemaining = '0.00';

        $advances = [];
        foreach ($advanceRows as $adv) {
            $isReversed = isset($reversedMovements[(int) $adv->movement_id]);
            $remaining = $advancesHelper->remaining((int) $adv->id);

            $applications = DB::table('rp_customer_advance_applications')
                ->where('advance_id', $adv->id)
                ->orderBy('id')
                ->get(['id', 'effective_date', 'amount', 'journal_id'])
                ->map(fn ($r) => (array) $r)
                ->all();

            $refundRows = DB::table('rp_customer_advance_refunds as f')
                ->join('treasury_movements as rm', 'rm.id', '=', 'f.movement_id')
                ->where('f.advance_id', $adv->id)
                ->orderBy('f.id')
                ->get(['f.id', 'f.movement_id', 'f.amount', 'f.refund_date', 'rm.reference', 'rm.journal_id', 'rm.official_document_id']);

            $refundMoveIds = $refundRows->pluck('movement_id')->all();
            $reversedRefundIds = $refundMoveIds === [] ? [] : DB::table('rp_cash_reversals')
                ->whereIn('original_movement_id', $refundMoveIds)
                ->pluck('original_movement_id')
                ->flip()
                ->all();

            $refunds = [];
            $thisAdvApplied = '0.00';
            $thisAdvRefunded = '0.00';

            foreach ($applications as $appRow) {
                $thisAdvApplied = bcadd($thisAdvApplied, (string) $appRow['amount'], 2);
            }
            foreach ($refundRows as $rf) {
                $rfReversed = isset($reversedRefundIds[(int) $rf->movement_id]);
                if (! $rfReversed) {
                    $thisAdvRefunded = bcadd($thisAdvRefunded, (string) $rf->amount, 2);
                }
                $refunds[] = [
                    'id' => (int) $rf->id,
                    'movement_id' => (int) $rf->movement_id,
                    'amount' => $rf->amount,
                    'refund_date' => $rf->refund_date,
                    'reference' => $rf->reference,
                    'journal_id' => (int) $rf->journal_id,
                    'official_document_id' => $rf->official_document_id,
                    'reversed' => $rfReversed,
                ];
            }

            if (! $isReversed) {
                $advancesCollected = bcadd($advancesCollected, (string) $adv->original_amount, 2);
                $advancesApplied = bcadd($advancesApplied, $thisAdvApplied, 2);
                $advancesRefunded = bcadd($advancesRefunded, $thisAdvRefunded, 2);
                $advancesRemaining = bcadd($advancesRemaining, $remaining, 2);
            }

            $advances[] = [
                'id' => (int) $adv->id,
                'movement_id' => (int) $adv->movement_id,
                'original_amount' => $adv->original_amount,
                'applied_amount' => $thisAdvApplied,
                'refunded_amount' => $thisAdvRefunded,
                'remaining' => $remaining,
                'movement_date' => $adv->movement_date,
                'reference' => $adv->reference,
                'journal_id' => (int) $adv->journal_id,
                'official_document_id' => $adv->official_document_id,
                'reversed' => $isReversed,
                'applications' => $applications,
                'refunds' => $refunds,
            ];
        }

        $collections = [];
        $directCollected = '0.00';
        $invoice = DB::table('rp_customer_invoices')->where('workshop_job_id', $job)->where('branch_id', $branch)->first();
        if ($invoice) {
            $openItem = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $invoice->id)->first();
            if ($openItem) {
                $allocs = DB::table('rp_settlement_allocations as s')
                    ->join('treasury_movements as m', 'm.id', '=', 's.movement_id')
                    ->where('s.open_item_id', $openItem->id)
                    ->orderBy('s.id')
                    ->get(['s.id', 's.movement_id', 's.amount', 'm.movement_date', 'm.reference', 'm.journal_id', 'm.official_document_id']);

                $collMoveIds = $allocs->pluck('movement_id')->all();
                $reversedColl = $collMoveIds === [] ? [] : DB::table('rp_cash_reversals')
                    ->whereIn('original_movement_id', $collMoveIds)
                    ->pluck('original_movement_id')
                    ->flip()
                    ->all();

                foreach ($allocs as $al) {
                    $isRev = isset($reversedColl[(int) $al->movement_id]);
                    if (! $isRev) {
                        $directCollected = bcadd($directCollected, (string) $al->amount, 2);
                    }
                    $collections[] = [
                        'id' => (int) $al->id,
                        'movement_id' => (int) $al->movement_id,
                        'amount' => $al->amount,
                        'movement_date' => $al->movement_date,
                        'reference' => $al->reference,
                        'journal_id' => (int) $al->journal_id,
                        'official_document_id' => $al->official_document_id,
                        'reversed' => $isRev,
                    ];
                }
            }
        }

        $totalCollected = bcadd($advancesCollected, $directCollected, 2);
        $totalRefunded = $advancesRefunded;
        $netCashReceived = bcsub($totalCollected, $totalRefunded, 2);

        return [
            'available' => true,
            'advances' => $advances,
            'collections' => $collections,
            'totals' => [
                'advances_collected' => $advancesCollected,
                'advances_applied' => $advancesApplied,
                'advances_refunded' => $advancesRefunded,
                'advances_remaining' => $advancesRemaining,
                'direct_collected' => $directCollected,
                'total_collected' => $totalCollected,
                'total_refunded' => $totalRefunded,
                'net_cash_received' => $netCashReceived,
            ],
        ];
    }
}

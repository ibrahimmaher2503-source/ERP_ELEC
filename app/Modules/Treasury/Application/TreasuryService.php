<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\Validator;

final class TreasuryService implements TreasuryApi
{
    public function collectionAccounts(int $actor, int $branch): array
    {
        app(TreasuryAccess::class)->authorize($actor, 'collection_execute', $branch);

        return \DB::table('treasury_accounts')->where('active', true)->orderBy('name')
            ->get(['id', 'name', 'kind'])->map(fn ($row) => (array) $row)->all();
    }

    public function correctMovement(int $actor, int $movementId, string $date, string $key, string $reason): array
    {
        return app(TreasuryOperations::class)->correct($actor, $movementId, $date, $key, $reason);
    }

    public function assertCashCorrection(int $actor, int $original, int $movement, string $date): void
    {
        $source = $this->cashCorrectionSource($actor, $original, $date);
        $m = \DB::table('treasury_movements')->where('original_movement_id', $original)->whereIn('kind', ['cash_correction_in', 'cash_correction_out'])->find($movement);
        abort_unless($m && (int) $m->executed_by === $actor && $m->movement_date === $date && bccomp($m->amount, $source['amount'], 2) === 0, 409);
    }

    public function cashCorrectionSource(int $actor, int $movementId, string $date): array
    {
        abort_unless(\DB::transactionLevel() > 0, 500);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        $m = \DB::table('treasury_movements')->lockForUpdate()->find($movementId);
        abort_unless($m && in_array($m->kind, ['customer_collection', 'supplier_payment', 'transfer_in', 'transfer_out', 'customer_advance_refund', 'customer_credit_refund', 'supplier_credit_refund'], true), 409);
        app(TreasuryAccess::class)->authorize($actor, 'cash_correct', (int) $m->branch_id);
        abort_unless((int) $m->executed_by !== $actor, 403, 'تصحيح حركة الخزينة مستقل عن منفذها.');
        abort_unless($date >= $m->movement_date, 422, 'التصحيح لا يسبق الحركة الأصلية.');
        $partyType = match ($m->kind) {
            'customer_collection', 'customer_advance_refund', 'customer_credit_refund' => 'customer',
            'supplier_payment', 'supplier_credit_refund' => 'supplier', default => null,
        };
        if ($partyType !== null) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $partyType === 'customer' ? 'M12.ar_view' : 'M12.ap_view', (int) $m->branch_id));
        }
        $canonical = $m->kind === 'transfer_in' ? \DB::table('treasury_movements')->find($m->paired_movement_id) : $m;
        abort_unless($canonical, 409);
        $key = match ($canonical->kind) {
            'customer_collection' => 'customer-collection:'.$canonical->idempotency_key,
            'supplier_payment' => 'supplier-payment:'.$canonical->payment_request_id,
            'transfer_out' => 'treasury-transfer:'.$canonical->idempotency_key,
            'customer_advance_refund' => 'customer-advance-refund:'.$canonical->idempotency_key,
            'customer_credit_refund', 'supplier_credit_refund' => 'party-credit-refund:'.$canonical->idempotency_key,
            default => abort(409),
        };

        return ['movement_id' => (int) $m->id, 'canonical_movement_id' => (int) $canonical->id, 'branch_id' => (int) $m->branch_id,
            'kind' => $m->kind, 'date' => $m->movement_date, 'amount' => $m->amount, 'account_id' => (int) $m->account_id,
            'party_type' => $partyType, 'party_id' => $m->party_id === null ? null : (int) $m->party_id, 'journal_id' => (int) $m->journal_id,
            'source_key' => $key, 'advance_id' => $m->customer_advance_id === null ? null : (int) $m->customer_advance_id,
            'credit_id' => $m->party_credit_id === null ? null : (int) $m->party_credit_id];
    }

    public function refundPartyCredit(int $actor, int $creditId, int $accountId, string $amount, string $date, string $idempotencyKey, string $reference): array
    {
        return app(TreasuryOperations::class)->refundCredit($actor, $creditId, $accountId, $amount, $date, $idempotencyKey, $reference);
    }

    public function partyCreditMovementEvidence(int $actor, int $movement, int $branch, string $partyType, int $party): array
    {
        abort_unless(in_array($partyType, ['supplier', 'customer'], true), 422);
        app(TreasuryAccess::class)->authorize($actor, 'credit_refund', $branch);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $partyType === 'customer' ? 'M12.ar_view' : 'M12.ap_view', $branch));
        $m = \DB::table('treasury_movements')->where('branch_id', $branch)->where('party_id', $party)->where('kind', $partyType.'_credit_refund')->find($movement);
        abort_unless($m, 409);

        return ['credit_id' => (int) $m->party_credit_id, 'date' => $m->movement_date, 'amount' => $m->amount, 'key' => $m->idempotency_key];
    }

    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        app(TreasuryAccess::class)->authorize($actor, 'account_view');
        $ids = array_column(app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M11.report_view')), 'id');
        abort_unless($ids && ($branch === null || in_array($branch, $ids, true)), 403);

        return ['scope' => 'authorized_branches', 'metrics' => $this->reconciliationValues($actor, $branch === null ? $ids : [$branch], $to), 'basis' => 'EGP رصيد البنك والنقدية حتى نهاية الفترة من الافتتاح والحركات الفعلية؛ ليس إيرادًا.'];
    }

    public function refundCustomerAdvance(int $actor, int $advanceId, int $accountId, string $amount, string $date, string $idempotencyKey, string $reference): array
    {
        return app(TreasuryOperations::class)->refundAdvance($actor, $advanceId, $accountId, $amount, $date, $idempotencyKey, $reference);
    }

    public function advanceRefundAccounts(int $actor, int $branch): array
    {
        app(TreasuryAccess::class)->authorize($actor, 'advance_refund', $branch);

        return \DB::table('treasury_accounts')->where('active', true)->orderBy('name')->get(['id', 'name', 'kind'])->map(fn ($row) => (array) $row)->all();
    }

    public function creditRefundAccounts(int $actor, int $branch): array
    {
        app(TreasuryAccess::class)->authorize($actor, 'credit_refund', $branch);

        return \DB::table('treasury_accounts')->where('active', true)->orderBy('name')->get(['id', 'name', 'kind'])->map(fn ($row) => (array) $row)->all();
    }

    public function advanceMovementEvidence(int $actor, int $movement, int $branch, int $customer): array
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M12.ar_view', $branch));
        $m = \DB::table('treasury_movements')->where('branch_id', $branch)->where('party_id', $customer)->whereIn('kind', ['customer_collection', 'customer_advance_refund'])->find($movement);
        abort_unless($m, 409);

        return ['kind' => $m->kind, 'date' => $m->movement_date, 'amount' => $m->amount, 'advance_id' => $m->customer_advance_id === null ? null : (int) $m->customer_advance_id, 'key' => $m->idempotency_key];
    }

    public function settlementMovementIds(int $actor, string $partyType, int $party, int $branch, string $to): array
    {
        abort_unless(in_array($partyType, ['customer', 'supplier']) && $party > 0, 422);
        Validator::make(compact('to'), ['to' => 'required|date_format:Y-m-d'])->validate();
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $partyType === 'customer' ? 'M12.ar_view' : 'M12.ap_view', $branch));

        return \DB::table('treasury_movements as original')->where('branch_id', $branch)->where('party_id', $party)->where('kind', $partyType === 'customer' ? 'customer_collection' : 'supplier_payment')->where('movement_date', '<=', $to)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('treasury_movements as correction')->whereColumn('correction.original_movement_id', 'original.id')->where('correction.movement_date', '<=', $to))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function partyMovements(int $actor, string $partyType, int $partyId, array $branchIds, int $page = 1): array
    {
        return app(PartyMovements::class)->read($actor, $partyType, $partyId, $branchIds, $page);
    }

    public function saveOperationalAccount(int $actor, array $data): int
    {
        return app(TreasuryOperations::class)->saveAccount($actor, $data);
    }

    public function prepareSupplierPayment(int $actor, array $data): int
    {
        return app(TreasuryOperations::class)->preparePayment($actor, $data);
    }

    public function approveSupplierPayment(int $actor, int $payment, int $version): void
    {
        app(TreasuryOperations::class)->approvePayment($actor, $payment, $version);
    }

    public function executeSupplierPayment(int $actor, int $payment, int $version, string $idempotencyKey, array $allocations): array
    {
        return app(TreasuryOperations::class)->executePayment($actor, $payment, $version, $idempotencyKey, $allocations);
    }

    public function executeCustomerCollection(int $actor, array $data, array $allocations): array
    {
        return app(TreasuryOperations::class)->collect($actor, $data, $allocations);
    }

    public function transfer(int $actor, array $data): array
    {
        return app(TreasuryOperations::class)->transfer($actor, $data);
    }

    public function reconciliationValues(int $actor, array $branchIds, string $to): array
    {
        $this->authorizeReconciliation($actor, $branchIds, $to);
        $ids = array_map('intval', $branchIds);
        $out = [];
        foreach (['bank', 'cash'] as $kind) {
            $opening = (string) \DB::table('treasury_opening_balances')->where('kind', $kind)->whereIn('branch_id', $ids)->where('opening_date', '<=', $to)->sum('amount');
            $movements = \DB::table('treasury_movements as m')->join('treasury_accounts as a', 'a.id', '=', 'm.account_id')
                ->where('a.kind', $kind)->whereIn('m.branch_id', $ids)->where('m.movement_date', '<=', $to)
                ->selectRaw("coalesce(sum(case when m.kind in ('customer_collection','transfer_in','supplier_credit_refund','cash_correction_in') then m.amount else -m.amount end),0)::numeric(20,2) amount")->value('amount');
            $out[$kind] = bcadd($opening, (string) $movements, 2);
        }

        return $out;
    }

    public function reconciliationMovements(int $actor, array $branchIds, string $to): array
    {
        $this->authorizeReconciliation($actor, $branchIds, $to);

        return \DB::table('treasury_movements')->whereIn('branch_id', $branchIds)->where('movement_date', '<=', $to)->orderBy('id')
            ->get(['id', 'branch_id', 'movement_date', 'kind', 'amount'])->map(fn ($row) => (array) $row)->all();
    }

    private function authorizeReconciliation(int $actor, array $branches, string $to): void
    {
        abort_if($branches === [], 403);
        Validator::make(['to' => $to], ['to' => 'required|date_format:Y-m-d'])->validate();
        foreach ($branches as $branch) {
            app(OrganizationApi::class)->authorize(
                new AuthorizeRequest($actor, 'M11.report_view', (int) $branch));
        }
    }
}

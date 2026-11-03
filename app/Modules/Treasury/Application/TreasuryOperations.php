<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TreasuryOperations
{
    public function correct(int $actor, int $movementId, string $date, string $key, string $reason): array
    {
        Validator::make(compact('date', 'key', 'reason'), ['date' => 'required|date_format:Y-m-d', 'key' => 'required|uuid', 'reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $movementId, $date, $key, $reason) {
            app(AccountingApi::class)->lockPosting();
            $api = app(TreasuryApi::class);
            $source = $api->cashCorrectionSource($actor, $movementId, $date);
            $canonical = DB::table('treasury_movements')->lockForUpdate()->find($source['canonical_movement_id']);
            $old = DB::table('treasury_movements')->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->original_movement_id === (int) $canonical->id && (int) $old->executed_by === $actor && $old->movement_date === $date && $old->reference === $reason, 409);

                return ['movement_id' => (int) $old->id, 'journal_id' => (int) $old->journal_id, 'paired_movement_id' => $old->paired_movement_id === null ? null : (int) $old->paired_movement_id];
            }
            $originals = [$canonical];
            if ($canonical->kind === 'transfer_out') {
                $pair = DB::table('treasury_movements')->lockForUpdate()->find($canonical->paired_movement_id);
                abort_unless($pair && $pair->kind === 'transfer_in' && (int) $pair->paired_movement_id === (int) $canonical->id && (int) $pair->journal_id === (int) $canonical->journal_id, 409);
                $originals[] = $pair;
            }
            foreach ($originals as $original) {
                $proof = $api->cashCorrectionSource($actor, (int) $original->id, $date);
                abort_if(DB::table('treasury_movements')->where('original_movement_id', $original->id)->exists(), 409, 'حركة الخزينة مصححة بالفعل.');
                if ($proof['party_type'] !== null) {
                    app(ReceivablesPayablesApi::class)->prepareCashReversal($actor, (int) $original->id, $date);
                }
                if (in_array($original->kind, ['customer_collection', 'transfer_in', 'supplier_credit_refund'], true)) {
                    $this->requireFunds($this->account((int) $original->account_id), (int) $original->branch_id, $date, $original->amount);
                }
            }
            $journal = app(AccountingApi::class)->reverseTreasurySource($actor, (int) $canonical->id, $date, $key, $reason);
            $document = app(OfficialDocuments::class)->issue($actor, 'treasury_correction', $key, 1, $date,
                ['title' => 'تصحيح مستقل لحركة خزينة', 'fields' => ['الحركة الأصلية' => (string) $canonical->id, 'السبب' => $reason], 'columns' => ['المبلغ EGP'], 'rows' => [[$canonical->amount]], 'totals' => ['الإجمالي' => $canonical->amount]]);
            $ids = [];
            foreach ($originals as $original) {
                $ids[(int) $original->id] = (int) DB::selectOne("select nextval(pg_get_serial_sequence('treasury_movements','id')) id")->id;
            }
            foreach ($originals as $index => $original) {
                $in = ! in_array($original->kind, ['customer_collection', 'transfer_in', 'supplier_credit_refund'], true);
                DB::table('treasury_movements')->insert(['id' => $ids[(int) $original->id], 'idempotency_key' => $index === 0 ? $key : $this->pairedKey($key),
                    'branch_id' => $original->branch_id, 'account_id' => $original->account_id, 'kind' => $in ? 'cash_correction_in' : 'cash_correction_out',
                    'original_movement_id' => $original->id, 'movement_date' => $date, 'amount' => $original->amount, 'party_id' => $original->party_id,
                    'reference' => $reason, 'journal_id' => $journal, 'official_document_id' => $index === 0 ? $document['id'] : null, 'executed_by' => $actor,
                    'paired_movement_id' => count($originals) === 2 ? $ids[(int) $originals[1 - $index]->id] : null]);
                if ($source['party_type'] !== null) {
                    app(ReceivablesPayablesApi::class)->completeCashReversal($actor, (int) $original->id, $ids[(int) $original->id], $date);
                }
            }
            app(RecordAudit::class)->execute($actor, 'treasury_movement', $ids[(int) $canonical->id], 'cash.corrected', null,
                ['original_movement_id' => (int) $canonical->id, 'journal_id' => $journal, 'reason' => $reason], 'succeeded', 'Treasury');

            return ['movement_id' => $ids[(int) $canonical->id], 'journal_id' => $journal,
                'paired_movement_id' => count($originals) === 2 ? $ids[(int) $originals[1]->id] : null, 'number' => $document['number']];
        }, 3);
    }

    public function refundCredit(int $actor, int $creditId, int $accountId, string $amount, string $date, string $idempotencyKey, string $reference): array
    {
        Validator::make(compact('idempotencyKey', 'date', 'reference'), ['idempotencyKey' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|min:5|max:200'])->validate();
        $amount = bcadd(Decimal::value($amount, 2), '0', 2);

        return DB::transaction(function () use ($actor, $creditId, $accountId, $amount, $date, $idempotencyKey, $reference) {
            app(AccountingApi::class)->lockPosting();
            $old = DB::table('treasury_movements')->where('idempotency_key', $idempotencyKey)->first();
            if ($old) {
                app(TreasuryAccess::class)->authorize($actor, 'credit_refund', (int) $old->branch_id);
                abort_unless(in_array($old->kind, ['customer_credit_refund', 'supplier_credit_refund'], true), 409);
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $old->kind === 'customer_credit_refund' ? 'M12.ar_view' : 'M12.ap_view', (int) $old->branch_id));
                abort_unless((int) $old->party_credit_id === $creditId && (int) $old->account_id === $accountId && (int) $old->executed_by === $actor
                    && $old->movement_date === $date && $old->reference === $reference && bccomp($old->amount, $amount, 2) === 0, 409);

                return ['movement_id' => (int) $old->id, 'journal_id' => (int) $old->journal_id];
            }
            $source = app(ReceivablesPayablesApi::class)->preparePartyCreditRefund($actor, $creditId, $amount, $date);
            app(TreasuryAccess::class)->authorize($actor, 'credit_refund', $source['branch_id']);
            $account = $this->account($accountId);
            $customer = $source['party_type'] === 'customer';
            if ($customer) {
                $this->requireFunds($account, $source['branch_id'], $date, $amount);
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $actor, $source['branch_id'], $date, 'party-credit-refund:'.$idempotencyKey, $reference,
                $customer ? [['purpose' => 'receivable', 'debit' => $amount, 'credit' => '0.00'], ['purpose' => $account->kind, 'debit' => '0.00', 'credit' => $amount]]
                    : [['purpose' => $account->kind, 'debit' => $amount, 'credit' => '0.00'], ['purpose' => 'ap', 'debit' => '0.00', 'credit' => $amount]]);
            $kind = $source['party_type'].'_credit_refund';
            $document = app(OfficialDocuments::class)->issue($actor, $kind, $idempotencyKey, 1, $date,
                ['title' => $customer ? 'رد رصيد دائن للعميل' : 'استرداد رصيد دائن من المورد', 'fields' => ['الرصيد الدائن' => (string) $creditId, 'المرجع' => $reference],
                    'columns' => ['المبلغ EGP'], 'rows' => [[$amount]], 'totals' => ['الإجمالي' => $amount]]);
            $movement = DB::table('treasury_movements')->insertGetId(['idempotency_key' => $idempotencyKey, 'branch_id' => $source['branch_id'], 'party_id' => $source['party_id'],
                'account_id' => $accountId, 'kind' => $kind, 'party_credit_id' => $creditId, 'movement_date' => $date, 'amount' => $amount, 'reference' => $reference,
                'journal_id' => $journal, 'official_document_id' => $document['id'], 'executed_by' => $actor]);
            app(ReceivablesPayablesApi::class)->completePartyCreditRefund($actor, $creditId, $movement, $amount, $date, $idempotencyKey);
            app(RecordAudit::class)->execute($actor, 'treasury_movement', $movement, 'credit_refund.executed', null,
                ['credit_id' => $creditId, 'journal_id' => $journal, 'amount' => $amount], 'succeeded', 'Treasury');

            return ['movement_id' => $movement, 'journal_id' => $journal, 'number' => $document['number']];
        }, 3);
    }

    public function refundAdvance(int $actor, int $advanceId, int $accountId, string $amount, string $date, string $idempotencyKey, string $reference): array
    {
        Validator::make(compact('idempotencyKey', 'date', 'reference'), ['idempotencyKey' => 'required|uuid', 'date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|min:5|max:200'])->validate();
        Decimal::value($amount, 2);

        return DB::transaction(function () use ($actor, $advanceId, $accountId, $amount, $date, $idempotencyKey, $reference) {
            app(AccountingApi::class)->lockPosting();
            $old = DB::table('treasury_movements')->where('idempotency_key', $idempotencyKey)->first();
            if ($old) {
                app(TreasuryAccess::class)->authorize($actor, 'advance_refund', (int) $old->branch_id);
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M12.ar_view', (int) $old->branch_id));
                abort_unless($old->kind === 'customer_advance_refund' && (int) $old->customer_advance_id === $advanceId && (int) $old->account_id === $accountId
                    && (int) $old->executed_by === $actor && $old->movement_date === $date && $old->reference === $reference && bccomp($old->amount, $amount, 2) === 0, 409);

                return ['movement_id' => (int) $old->id, 'journal_id' => (int) $old->journal_id];
            }
            $source = app(ReceivablesPayablesApi::class)->prepareAdvanceRefund($actor, $advanceId, $amount, $date);
            app(TreasuryAccess::class)->authorize($actor, 'advance_refund', $source['branch_id']);
            $account = $this->account($accountId);
            $this->requireFunds($account, $source['branch_id'], $date, $amount);
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $actor, $source['branch_id'], $date, 'customer-advance-refund:'.$idempotencyKey, $reference,
                [['purpose' => 'customer_advance', 'debit' => $amount, 'credit' => '0.00'], ['purpose' => $account->kind, 'debit' => '0.00', 'credit' => $amount]]);
            $document = app(OfficialDocuments::class)->issue($actor, 'customer_advance_refund', $idempotencyKey, 1, $date,
                ['title' => 'رد مقدم عميل', 'fields' => ['المقدم' => (string) $advanceId, 'المرجع' => $reference], 'columns' => ['المبلغ EGP'], 'rows' => [[$amount]], 'totals' => ['الإجمالي' => $amount]]);
            $movement = DB::table('treasury_movements')->insertGetId(['idempotency_key' => $idempotencyKey, 'branch_id' => $source['branch_id'], 'party_id' => $source['customer_id'],
                'account_id' => $accountId, 'kind' => 'customer_advance_refund', 'customer_advance_id' => $advanceId, 'movement_date' => $date, 'amount' => $amount, 'reference' => $reference,
                'journal_id' => $journal, 'official_document_id' => $document['id'], 'executed_by' => $actor]);
            app(ReceivablesPayablesApi::class)->completeAdvanceRefund($actor, $advanceId, $movement, $amount, $date, $idempotencyKey);

            return ['movement_id' => $movement, 'journal_id' => $journal, 'number' => $document['number']];
        }, 3);
    }

    public function saveAccount(int $actor, array $data): int
    {
        Validator::make($data, ['code' => 'required|string|max:40', 'name' => 'required|string|max:200', 'kind' => 'required|in:bank,cash',
            'gl_account_id' => 'required|integer|min:1'])->validate();
        app(TreasuryAccess::class)->authorize($actor, 'account_manage');
        $account = DB::table('accounting_accounts')->where('id', $data['gl_account_id'])->where('active', true)->where('postable', true)->first();
        if (! $account || $account->kind !== 'asset') {
            $this->fail('حساب الخزينة يجب أن يكون حساب أصول نشطاً وقابلاً للترحيل.');
        }
        $mapped = DB::table('accounting_account_mappings')->where('purpose', $data['kind'])->value('account_id');
        if ((int) $mapped !== (int) $account->id) {
            $this->fail('حساب الخزينة يجب أن يطابق الربط المحاسبي المعتمد لنوعه.');
        }

        DB::table('treasury_accounts')->updateOrInsert(['kind' => $data['kind'], 'active' => true],
            ['code' => $data['code'], 'name' => $data['name'], 'gl_account_id' => $account->id, 'updated_at' => now(), 'created_at' => now()]);

        return (int) DB::table('treasury_accounts')->where('kind', $data['kind'])->where('active', true)->value('id');
    }

    public function preparePayment(int $actor, array $data): int
    {
        Validator::make($data, ['creation_key' => 'required|uuid', 'branch_id' => 'required|integer|min:1', 'supplier_id' => 'required|integer|min:1',
            'account_id' => 'required|integer|min:1', 'request_date' => 'required|date_format:Y-m-d', 'amount' => 'required|string', 'reference' => 'required|string|max:200'])->validate();
        Decimal::value($data['amount'], 2);
        app(TreasuryAccess::class)->authorize($actor, 'payment_prepare', (int) $data['branch_id']);
        app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $data['supplier_id']));
        $this->account((int) $data['account_id']);

        return DB::transaction(function () use ($actor, $data) {
            $existing = DB::table('treasury_payment_requests')->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                return (int) $existing->id;
            }

            return DB::table('treasury_payment_requests')->insertGetId(['creation_key' => $data['creation_key'], 'branch_id' => $data['branch_id'],
                'supplier_id' => $data['supplier_id'], 'account_id' => $data['account_id'], 'request_date' => $data['request_date'],
                'amount' => $data['amount'], 'reference' => $data['reference'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function approvePayment(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            $row = DB::table('treasury_payment_requests')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(TreasuryAccess::class)->authorize($actor, 'payment_approve', $row->branch_id);
            if ($row->status !== 'prepared' || (int) $row->version !== $version || (int) $row->created_by === $actor) {
                abort(409, 'اعتماد الدفع يجب أن يكون مستقلاً وعلى الإصدار الحالي.');
            }
            DB::table('treasury_payment_requests')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor,
                'approved_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
        });
    }

    public function executePayment(int $actor, int $id, int $version, string $idempotencyKey, array $allocations): array
    {
        Validator::make(compact('idempotencyKey'), ['idempotencyKey' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $id, $version, $idempotencyKey, $allocations) {
            app(AccountingApi::class)->lockPosting();
            $existing = DB::table('treasury_movements')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->kind !== 'supplier_payment' || (int) $existing->payment_request_id !== $id) {
                    $this->fail('مفتاح إعادة المحاولة مستخدم لحركة دفع مختلفة.');
                }

                return ['movement_id' => (int) $existing->id, 'journal_id' => (int) $existing->journal_id];
            }
            $request = DB::table('treasury_payment_requests')->lockForUpdate()->find($id);
            abort_unless($request, 404);
            $existing = DB::table('treasury_movements')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->kind !== 'supplier_payment' || (int) $existing->payment_request_id !== $id) {
                    $this->fail('مفتاح إعادة المحاولة مستخدم لحركة دفع مختلفة.');
                }

                return ['movement_id' => (int) $existing->id, 'journal_id' => (int) $existing->journal_id];
            }
            app(TreasuryAccess::class)->authorize($actor, 'payment_execute', $request->branch_id);
            if ($request->status !== 'approved' || (int) $request->version !== $version) {
                abort(409, 'طلب الدفع غير معتمد أو تغير إصداره.');
            }
            $account = $this->account((int) $request->account_id);
            $this->requireFunds($account, (int) $request->branch_id, $request->request_date, $request->amount);
            $settlement = app(ReceivablesPayablesApi::class)->prepareSettlement($actor, 'supplier', (int) $request->supplier_id, $allocations);
            if ((int) $settlement['branch_id'] !== (int) $request->branch_id || bccomp($settlement['amount'], $request->amount, 2) !== 0) {
                $this->fail('تخصيصات الدفع يجب أن تساوي الطلب المعتمد ولنفس الفرع.');
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, (int) $request->created_by, (int) $request->branch_id, $request->request_date,
                'supplier-payment:'.$id, 'دفع مورد '.$request->reference, [['purpose' => 'ap', 'debit' => $request->amount, 'credit' => '0.00'],
                    ['purpose' => $account->kind, 'debit' => '0.00', 'credit' => $request->amount]]);
            $document = app(OfficialDocuments::class)->issue($actor, 'supplier_payment', (string) $id, 1, $request->request_date,
                ['title' => 'سند دفع مورد', 'fields' => ['المرجع' => $request->reference], 'columns' => ['المبلغ'], 'rows' => [[$request->amount]], 'totals' => ['الإجمالي' => $request->amount]]);
            $movement = DB::table('treasury_movements')->insertGetId(['idempotency_key' => $idempotencyKey, 'branch_id' => $request->branch_id,
                'account_id' => $account->id, 'kind' => 'supplier_payment', 'movement_date' => $request->request_date, 'party_id' => $request->supplier_id,
                'amount' => $request->amount, 'reference' => $request->reference, 'payment_request_id' => $id, 'journal_id' => $journal,
                'official_document_id' => $document['id'], 'executed_by' => $actor, 'executed_at' => now()]);
            app(ReceivablesPayablesApi::class)->completeSettlement($actor, $movement, 'supplier', $allocations);
            DB::table('treasury_payment_requests')->where('id', $id)->update(['status' => 'executed', 'executed_by' => $actor, 'executed_at' => now(),
                'version' => $version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'treasury_movement', $movement, 'supplier_payment.executed', null,
                ['journal_id' => $journal, 'document_id' => $document['id'], 'amount' => $request->amount], 'succeeded', 'Treasury');

            return ['movement_id' => $movement, 'journal_id' => $journal, 'number' => $document['number']];
        }, 5);
    }

    public function collect(int $actor, array $data, array $allocations): array
    {
        Validator::make($data, ['idempotency_key' => 'required|uuid', 'branch_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1',
            'account_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d', 'amount' => 'required|string', 'reference' => 'required|string|max:200',
            'allow_advance' => 'sometimes|boolean'])->validate();
        Decimal::value($data['amount'], 2);

        return DB::transaction(function () use ($actor, $data, $allocations) {
            app(AccountingApi::class)->lockPosting();
            $existing = DB::table('treasury_movements')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->kind !== 'customer_collection' || (int) $existing->branch_id !== (int) $data['branch_id']
                    || (int) $existing->account_id !== (int) $data['account_id'] || (int) $existing->party_id !== (int) $data['customer_id']
                    || $existing->movement_date !== $data['date'] || bccomp($existing->amount, $data['amount'], 2) !== 0
                    || $existing->reference !== $data['reference']) {
                    $this->fail('مفتاح إعادة المحاولة مستخدم لتحصيل مختلف.');
                }

                return ['movement_id' => (int) $existing->id, 'journal_id' => (int) $existing->journal_id];
            }
            app(TreasuryAccess::class)->authorize($actor, 'collection_execute', (int) $data['branch_id']);
            app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $data['customer_id']));
            $account = $this->account((int) $data['account_id']);
            $allocated = '0.00';
            if ($allocations !== []) {
                $settlement = app(ReceivablesPayablesApi::class)->prepareSettlement($actor, 'customer', (int) $data['customer_id'], $allocations);
                if ((int) $settlement['branch_id'] !== (int) $data['branch_id']) {
                    $this->fail('تخصيصات التحصيل يجب أن تكون لنفس الفرع.');
                }
                $allocated = $settlement['amount'];
            }
            $advance = bcsub($data['amount'], $allocated, 2);
            if (bccomp($advance, '0', 2) < 0 || (bccomp($advance, '0', 2) > 0 && ! ($data['allow_advance'] ?? false))) {
                $this->fail('يجب أن تساوي التخصيصات مبلغ التحصيل، أو يصرح صراحة بإثبات الفرق كدفعة عميل مقدمة.');
            }
            $lines = [['purpose' => $account->kind, 'debit' => $data['amount'], 'credit' => '0.00']];
            if (bccomp($allocated, '0', 2) > 0) {
                $lines[] = ['purpose' => 'receivable', 'debit' => '0.00', 'credit' => $allocated];
            }
            if (bccomp($advance, '0', 2) > 0) {
                $lines[] = ['purpose' => 'customer_advance', 'debit' => '0.00', 'credit' => $advance];
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $actor, (int) $data['branch_id'], $data['date'],
                'customer-collection:'.$data['idempotency_key'], 'تحصيل عميل '.$data['reference'], $lines);
            $document = app(OfficialDocuments::class)->issue($actor, 'customer_collection', $data['idempotency_key'], 1, $data['date'],
                ['title' => 'سند تحصيل عميل', 'fields' => ['المرجع' => $data['reference']], 'columns' => ['المبلغ'], 'rows' => [[$data['amount']]], 'totals' => ['الإجمالي' => $data['amount']]]);
            $movement = DB::table('treasury_movements')->insertGetId(['idempotency_key' => $data['idempotency_key'], 'branch_id' => $data['branch_id'],
                'account_id' => $account->id, 'kind' => 'customer_collection', 'movement_date' => $data['date'], 'party_id' => $data['customer_id'],
                'amount' => $data['amount'], 'reference' => $data['reference'], 'journal_id' => $journal, 'official_document_id' => $document['id'],
                'executed_by' => $actor, 'executed_at' => now()]);
            if ($allocations !== []) {
                app(ReceivablesPayablesApi::class)->completeSettlement($actor, $movement, 'customer', $allocations);
            }
            if (bccomp($advance, '0', 2) > 0) {
                app(ReceivablesPayablesApi::class)->recordCustomerAdvance($actor, $movement, (int) $data['branch_id'], (int) $data['customer_id'], $advance);
            }

            return ['movement_id' => $movement, 'journal_id' => $journal, 'number' => $document['number']];
        }, 5);
    }

    public function transfer(int $actor, array $data): array
    {
        Validator::make($data, ['idempotency_key' => 'required|uuid', 'branch_id' => 'required|integer|min:1', 'from_account_id' => 'required|integer|min:1|different:to_account_id',
            'to_account_id' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d', 'amount' => 'required|string', 'reference' => 'required|string|max:200'])->validate();
        Decimal::value($data['amount'], 2);

        return DB::transaction(function () use ($actor, $data) {
            app(AccountingApi::class)->lockPosting();
            $existing = DB::table('treasury_movements')->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $pair = $existing->paired_movement_id ? DB::table('treasury_movements')->find($existing->paired_movement_id) : null;
                if ($existing->kind !== 'transfer_out' || (int) $existing->branch_id !== (int) $data['branch_id']
                    || (int) $existing->account_id !== (int) $data['from_account_id'] || ! $pair
                    || (int) $pair->account_id !== (int) $data['to_account_id'] || $existing->movement_date !== $data['date']
                    || bccomp($existing->amount, $data['amount'], 2) !== 0 || $existing->reference !== $data['reference']) {
                    $this->fail('مفتاح إعادة المحاولة مستخدم لتحويل مختلف.');
                }

                return ['out_movement_id' => (int) $existing->id, 'journal_id' => (int) $existing->journal_id];
            }
            app(TreasuryAccess::class)->authorize($actor, 'transfer_execute', (int) $data['branch_id']);
            $from = $this->account((int) $data['from_account_id']);
            $to = $this->account((int) $data['to_account_id']);
            $this->requireFunds($from, (int) $data['branch_id'], $data['date'], $data['amount']);
            $journal = app(AccountingApi::class)->postSourceDocument($actor, $actor, (int) $data['branch_id'], $data['date'],
                'treasury-transfer:'.$data['idempotency_key'], 'تحويل خزينة '.$data['reference'], [['purpose' => $to->kind, 'debit' => $data['amount'], 'credit' => '0.00'],
                    ['purpose' => $from->kind, 'debit' => '0.00', 'credit' => $data['amount']]]);
            $document = app(OfficialDocuments::class)->issue($actor, 'treasury_transfer', $data['idempotency_key'], 1, $data['date'],
                ['title' => 'تحويل بين البنك والخزينة', 'fields' => ['المرجع' => $data['reference']], 'columns' => ['المبلغ'], 'rows' => [[$data['amount']]], 'totals' => ['الإجمالي' => $data['amount']]]);
            $outId = DB::selectOne("select nextval(pg_get_serial_sequence('treasury_movements','id')) id")->id;
            $inId = DB::selectOne("select nextval(pg_get_serial_sequence('treasury_movements','id')) id")->id;
            DB::table('treasury_movements')->insert(['id' => $outId, 'idempotency_key' => $data['idempotency_key'], 'branch_id' => $data['branch_id'], 'account_id' => $from->id,
                'kind' => 'transfer_out', 'movement_date' => $data['date'], 'amount' => $data['amount'], 'reference' => $data['reference'], 'paired_movement_id' => $inId,
                'journal_id' => $journal, 'official_document_id' => $document['id'], 'executed_by' => $actor, 'executed_at' => now()]);
            DB::table('treasury_movements')->insert(['id' => $inId, 'idempotency_key' => $this->pairedKey($data['idempotency_key']), 'branch_id' => $data['branch_id'], 'account_id' => $to->id,
                'kind' => 'transfer_in', 'movement_date' => $data['date'], 'amount' => $data['amount'], 'reference' => $data['reference'], 'paired_movement_id' => $outId,
                'journal_id' => $journal, 'executed_by' => $actor, 'executed_at' => now()]);

            return ['out_movement_id' => (int) $outId, 'in_movement_id' => (int) $inId, 'journal_id' => $journal, 'number' => $document['number']];
        }, 5);
    }

    private function account(int $id): object
    {
        $account = DB::table('treasury_accounts')->where('active', true)->lockForUpdate()->find($id);
        abort_unless($account, 409, 'حساب الخزينة غير نشط.');

        return $account;
    }

    private function pairedKey(string $key): string
    {
        $bytes = hash('sha256', $key.':paired', true);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex(substr($bytes, 0, 16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    private function requireFunds(object $account, int $branch, string $date, string $amount): void
    {
        $opening = (string) DB::table('treasury_opening_balances')->where('kind', $account->kind)->where('branch_id', $branch)
            ->where('opening_date', '<=', $date)->sum('amount');
        $movement = (string) DB::table('treasury_movements')->where('account_id', $account->id)->where('branch_id', $branch)
            ->where('movement_date', '<=', $date)->selectRaw("coalesce(sum(case when kind in ('customer_collection','transfer_in','supplier_credit_refund','cash_correction_in') then amount else -amount end),0)::numeric(20,2) balance")->value('balance');
        if (bccomp(bcadd($opening, $movement, 2), $amount, 2) < 0) {
            $this->fail('الرصيد الفعلي للحساب لا يكفي لتنفيذ الحركة.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['treasury' => $message]);
    }
}

<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FinancialReports
{
    public function ledger(int $actor, ?int $branch, ?string $from, ?string $to): array
    {
        $branches = $this->branches($actor, $branch);
        $rows = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->whereIn('j.branch_id', $branches)
            ->when($from, fn ($q) => $q->where('j.journal_date', '>=', $from))->when($to, fn ($q) => $q->where('j.journal_date', '<=', $to))
            ->orderBy('j.journal_date')->orderBy('j.id')->orderBy('l.id')
            ->get(['j.id as journal_id', 'j.journal_date', 'j.official_number', 'j.branch_id', 'j.source_key', 'l.account_code', 'l.account_name', 'l.description', 'l.debit', 'l.credit']);

        return ['rows' => $rows, 'debit' => $this->sum($rows, 'debit'), 'credit' => $this->sum($rows, 'credit')];
    }

    public function trialBalance(int $actor, ?int $branch, ?string $from, ?string $to): array
    {
        $branches = $this->branches($actor, $branch);
        $rows = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->whereIn('j.branch_id', $branches)
            ->when($from, fn ($q) => $q->where('j.journal_date', '>=', $from))->when($to, fn ($q) => $q->where('j.journal_date', '<=', $to))
            ->groupBy('l.account_id', 'l.account_code', 'l.account_name')->orderBy('l.account_code')
            ->get(['l.account_id', 'l.account_code', 'l.account_name', DB::raw('sum(l.debit)::numeric(20,2) debit'), DB::raw('sum(l.credit)::numeric(20,2) credit'),
                DB::raw('(sum(l.debit)-sum(l.credit))::numeric(20,2) balance')]);

        return ['rows' => $rows, 'debit' => $this->sum($rows, 'debit'), 'credit' => $this->sum($rows, 'credit')];
    }

    public function statement(int $actor, string $statement, ?int $branch, ?string $from, ?string $to): array
    {
        abort_unless(in_array($statement, ['income', 'balance'], true), 422);
        $kinds = $statement === 'income' ? ['income', 'expense'] : ['asset', 'liability', 'equity'];
        $trial = collect($this->trialBalance($actor, $branch, $from, $to)['rows']);
        $kindsByAccount = DB::table('accounting_accounts')->whereIn('kind', $kinds)->pluck('kind', 'id');
        $rows = $trial->filter(fn ($row) => isset($kindsByAccount[$row->account_id]))->map(function ($row) use ($kindsByAccount) {
            $row->kind = $kindsByAccount[$row->account_id];

            return $row;
        })->values();

        return ['rows' => $rows, 'net' => $rows->reduce(fn ($sum, $row) => bcadd($sum, $row->balance, 2), '0.00')];
    }

    public function reconciliations(int $actor, ?int $branch, ?string $to = null): array
    {
        return DB::transaction(function () use ($actor, $branch, $to) {
            app(AccountingActions::class)->lock();

            return $this->reconciliationRows($actor, $branch, $to);
        });
    }

    private function reconciliationRows(int $actor, ?int $branch, ?string $to): array
    {
        $branches = $this->branches($actor, $branch);
        $to ??= now('Africa/Cairo')->toDateString();
        Validator::make(['to' => $to], ['to' => 'required|date_format:Y-m-d'])->validate();
        $mapping = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose');
        $gl = fn (string $purpose): string => isset($mapping[$purpose]) ? (string) DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')->where('j.status', 'posted')->whereIn('j.branch_id', $branches)
            ->where('j.journal_date', '<=', $to)->where('l.account_id', $mapping[$purpose])->selectRaw('coalesce(sum(l.debit-l.credit),0)::numeric(20,2) balance')->value('balance') : '0.00';
        $subledger = app(ReceivablesPayablesApi::class)->reconciliationValues($actor, $branches, $to);
        $ap = $subledger['ap'];
        $ar = $subledger['ar'];
        $grni = app(PurchasingApi::class)->grniValue($actor, $branches, $to);
        $cleared = $subledger['grni_cleared'];
        $fifo = app(InventoryApi::class)->financialValue($actor, $branches, $to);
        $transit = app(InventoryApi::class)->transitValue($actor, $branches, $to);
        $treasury = app(TreasuryApi::class)->reconciliationValues($actor, $branches, $to);
        $rows = [
            ['key' => 'ap', 'label' => 'مراقبة الموردين مقابل الذمم الدائنة', 'gl' => bcsub('0', $gl('ap'), 2), 'source' => bcadd($ap, '0', 2)],
            ['key' => 'ar', 'label' => 'مراقبة العملاء مقابل الذمم المدينة', 'gl' => bcadd($gl('receivable'), '0', 2), 'source' => bcadd($ar, '0', 2)],
            ['key' => 'grni', 'label' => 'GRNI مقابل الاستلامات غير المفوترة', 'gl' => bcsub('0', $gl('grni'), 2), 'source' => bcsub($grni, $cleared, 2)],
            ['key' => 'inventory', 'label' => 'المخزون العام مقابل طبقات FIFO', 'gl' => bcadd($gl('inventory'), '0', 2), 'source' => bcadd($fifo, '0', 2)],
            ['key' => 'inventory_transit', 'label' => 'المخزون بالطريق مقابل طبقات النقل', 'gl' => bcadd($gl('inventory_transit'), '0', 2), 'source' => $transit],
            ['key' => 'bank', 'label' => 'حساب البنك مقابل حركات الخزينة', 'gl' => bcadd($gl('bank'), '0', 2), 'source' => $treasury['bank']],
            ['key' => 'cash', 'label' => 'حساب النقدية مقابل حركات الخزينة', 'gl' => bcadd($gl('cash'), '0', 2), 'source' => $treasury['cash']],
        ];
        if (isset($mapping['customer_advance'])) {
            $rows[] = ['key' => 'customer_advance', 'label' => 'دفعات العملاء المقدمة مقابل سجل الذمم',
                'gl' => bcsub('0', $gl('customer_advance'), 2), 'source' => $subledger['customer_advances']];
        }
        if (isset($mapping['service_contract_deferred'])) {
            $billed = app(ReceivablesPayablesApi::class)->contractDeferredValue($actor, $branches, $to);
            $earned = app(ServiceContractsApi::class)->recognizedValue($actor, $branches, $to);
            $rows[] = ['key' => 'service_contract_deferred', 'label' => 'إيراد العقود المؤجل مقابل الفواتير والأعمال المكتسبة',
                'gl' => bcsub('0', $gl('service_contract_deferred'), 2), 'source' => bcsub($billed, $earned, 2)];
        }
        foreach ($rows as &$row) {
            $row['difference'] = bcsub($row['gl'], $row['source'], 2);
            $row['ok'] = bccomp($row['difference'], '0', 2) === 0;
        }

        return $rows;
    }

    public function ppv(int $actor, ?int $branch, ?string $from, ?string $to): array
    {
        $branches = $this->branches($actor, $branch);

        return app(ReceivablesPayablesApi::class)->ppvRows($actor, $branches, $from, $to);
    }

    public function closePeriod(int $actor, int $period): void
    {
        app(AccountingAccess::class)->authorize($actor, 'period_close');
        DB::transaction(function () use ($actor, $period) {
            app(AccountingActions::class)->lock();
            $branches = app(AccountingAccess::class)->closingBranches($actor);
            $row = DB::table('accounting_periods')->lockForUpdate()->find($period);
            abort_unless($row && $row->status === 'open', 409);
            if (DB::table('accounting_journals')->whereBetween('journal_date', [$row->starts_on, $row->ends_on])->where('status', '<>', 'posted')->exists()) {
                $this->fail('لا يمكن الإقفال مع قيود غير مرحّلة داخل الفترة.');
            }
            $results = [];
            foreach ($branches as $branch) {
                $results[$branch['id']] = $this->reconciliationRows($actor, $branch['id'], $row->ends_on);
                if (collect($results[$branch['id']])->contains(fn ($item) => ! $item['ok'])) {
                    $this->fail('لا يمكن الإقفال قبل نجاح مصالحات المخزون وGRNI والذمم لكل الفروع.');
                }
            }
            $evidence = ['contract_version' => 1, 'date_basis' => 'inclusive_business_date', 'period_id' => $period,
                'period_version' => $row->version, 'starts_on' => $row->starts_on, 'cutoff' => $row->ends_on,
                'branches' => $branches, 'mappings' => DB::table('accounting_account_mappings')->orderBy('purpose')->pluck('account_id', 'purpose')->all(),
                'reconciliations' => $results];
            $json = json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $closure = DB::table('accounting_period_closures')->insertGetId(['period_id' => $period, 'period_version' => $row->version,
                'cutoff' => $row->ends_on, 'closed_by' => $actor, 'evidence' => $json, 'sha256' => hash('sha256', $json), 'created_at' => now()]);
            DB::table('accounting_periods')->where('id', $period)->update(['status' => 'closed', 'version' => $row->version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'accounting_period', $period, 'period.closed', (array) $row,
                ['status' => 'closed', 'version' => $row->version + 1, 'closure_id' => $closure, 'sha256' => hash('sha256', $json)], 'succeeded', 'Accounting');
        });
    }

    private function branches(int $actor, ?int $branch): array
    {
        $allowed = array_column(app(AccountingAccess::class)->branches($actor, 'report_view'), 'id');
        abort_if($allowed === [], 403);
        if ($branch !== null) {
            abort_unless(in_array($branch, $allowed, true), 403);

            return [$branch];
        }

        return $allowed;
    }

    private function sum(iterable $rows, string $field): string
    {
        $sum = '0.00';
        foreach ($rows as $row) {
            $sum = bcadd($sum, $row->$field, 2);
        }

        return $sum;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['closing' => $message]);
    }
}

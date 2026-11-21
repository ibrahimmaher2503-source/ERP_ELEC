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
    public const KIND_LABELS = [
        'asset' => 'أصول',
        'liability' => 'التزامات',
        'equity' => 'حقوق ملكية',
        'income' => 'إيرادات',
        'expense' => 'مصروفات',
    ];

    public static function kindLabel(?string $kind): string
    {
        return self::KIND_LABELS[(string) $kind] ?? (string) $kind;
    }

    public static function sourceLabel(?string $sourceKey): array
    {
        $raw = trim((string) $sourceKey);
        if ($raw === '') {
            return ['label' => 'قيد يومية يدوي', 'tone' => 'slate'];
        }
        $prefix = explode(':', $raw)[0];

        return match ($prefix) {
            'opening' => ['label' => 'رصيد افتتاحي', 'tone' => 'indigo'],
            'receipt' => ['label' => 'استلام مشتريات', 'tone' => 'blue'],
            'supplier-invoice', 'supplier_invoice' => ['label' => 'فاتورة مورد', 'tone' => 'amber'],
            'customer-invoice', 'customer_invoice' => ['label' => 'فاتورة عميل', 'tone' => 'emerald'],
            'credit-note', 'credit_note' => ['label' => 'إشعار دائن/مدين', 'tone' => 'rose'],
            'treasury', 'treasury-reversal', 'payment', 'collection', 'transfer' => ['label' => 'حركة خزينة/بنك', 'tone' => 'teal'],
            'inventory', 'inventory-return' => ['label' => 'حركة مخزون', 'tone' => 'cyan'],
            'maintenance' => ['label' => 'صيانة ميدانية', 'tone' => 'orange'],
            'workshop' => ['label' => 'ورشة إصلاح', 'tone' => 'purple'],
            'contract', 'service-contract' => ['label' => 'عقد خدمة', 'tone' => 'violet'],
            'partner' => ['label' => 'جاري الشركاء', 'tone' => 'blue'],
            'reversal' => ['label' => 'قيد عكسي', 'tone' => 'red'],
            default => ['label' => 'مستند مصدري ('.$prefix.')', 'tone' => 'slate'],
        };
    }

    public function ledger(int $actor, ?int $branch, ?string $from, ?string $to, ?int $accountId = null, ?string $search = null, ?string $source = null): array
    {
        $branches = $this->branches($actor, $branch);
        $needle = trim((string) $search);
        $openingBalance = null;
        $selectedAccount = null;

        if ($accountId !== null) {
            $selectedAccount = DB::table('accounting_accounts')->find($accountId);
            if ($from !== null && $from !== '') {
                $openingBalance = (string) DB::table('accounting_journal_lines as l')
                    ->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
                    ->where('j.status', 'posted')
                    ->whereIn('j.branch_id', $branches)
                    ->where('l.account_id', $accountId)
                    ->where('j.journal_date', '<', $from)
                    ->selectRaw('coalesce(sum(l.debit - l.credit), 0)::numeric(20,2) as balance')
                    ->value('balance');
            } else {
                $openingBalance = '0.00';
            }
        }

        $rows = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->whereIn('j.branch_id', $branches)
            ->when($from, fn ($q) => $q->where('j.journal_date', '>=', $from))->when($to, fn ($q) => $q->where('j.journal_date', '<=', $to))
            ->when($accountId, fn ($q) => $q->where('l.account_id', $accountId))
            ->when($source === 'manual', fn ($q) => $q->whereNull('j.source_key'))
            ->when($source === 'system', fn ($q) => $q->whereNotNull('j.source_key'))
            ->when($needle !== '', fn ($q) => $q->where(function ($sub) use ($needle) {
                $like = '%'.$needle.'%';
                $sub->where('j.official_number', 'ilike', $like)
                    ->orWhere('l.account_code', 'ilike', $like)
                    ->orWhere('l.account_name', 'ilike', $like)
                    ->orWhere('l.description', 'ilike', $like)
                    ->orWhere('j.description', 'ilike', $like);
            }))
            ->orderBy('j.journal_date')->orderBy('j.id')->orderBy('l.id')
            ->get(['j.id as journal_id', 'j.journal_date', 'j.official_number', 'j.branch_id', 'j.source_key', 'j.description as journal_description', 'l.account_id', 'l.account_code', 'l.account_name', 'l.description', 'l.debit', 'l.credit']);

        $running = $openingBalance ?? '0.00';
        foreach ($rows as $row) {
            $netMove = bcsub((string) $row->debit, (string) $row->credit, 2);
            $running = bcadd($running, $netMove, 2);
            $row->running_balance = $running;
            $sourceMeta = self::sourceLabel($row->source_key);
            $row->source_label = $sourceMeta['label'];
            $row->source_tone = $sourceMeta['tone'];
        }

        $debit = $this->sum($rows, 'debit');
        $credit = $this->sum($rows, 'credit');
        $net = bcsub($debit, $credit, 2);

        return [
            'rows' => $rows,
            'debit' => $debit,
            'credit' => $credit,
            'net' => $net,
            'opening_balance' => $openingBalance,
            'closing_balance' => $openingBalance !== null ? bcadd($openingBalance, $net, 2) : $net,
            'selected_account' => $selectedAccount,
        ];
    }

    public function trialBalance(int $actor, ?int $branch, ?string $from, ?string $to, ?string $kind = null, ?string $search = null): array
    {
        $branches = $this->branches($actor, $branch);
        $needle = trim((string) $search);

        $openingByAccount = [];
        if ($from !== null && $from !== '') {
            $openingByAccount = DB::table('accounting_journal_lines as l')
                ->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
                ->where('j.status', 'posted')
                ->whereIn('j.branch_id', $branches)
                ->where('j.journal_date', '<', $from)
                ->groupBy('l.account_id')
                ->selectRaw('l.account_id, coalesce(sum(l.debit - l.credit), 0)::numeric(20,2) as opening_net')
                ->pluck('opening_net', 'account_id')
                ->all();
        }

        $rows = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->leftJoin('accounting_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.status', 'posted')->whereIn('j.branch_id', $branches)
            ->when($from, fn ($q) => $q->where('j.journal_date', '>=', $from))->when($to, fn ($q) => $q->where('j.journal_date', '<=', $to))
            ->when($kind, fn ($q) => $q->where('a.kind', $kind))
            ->when($needle !== '', fn ($q) => $q->where(function ($sub) use ($needle) {
                $like = '%'.$needle.'%';
                $sub->where('l.account_code', 'ilike', $like)->orWhere('l.account_name', 'ilike', $like);
            }))
            ->groupBy('l.account_id', 'l.account_code', 'l.account_name', 'a.kind')->orderBy('l.account_code')
            ->get(['l.account_id', 'l.account_code', 'l.account_name', 'a.kind', DB::raw('sum(l.debit)::numeric(20,2) debit'), DB::raw('sum(l.credit)::numeric(20,2) credit'),
                DB::raw('(sum(l.debit)-sum(l.credit))::numeric(20,2) balance')]);

        $openingDebitTotal = '0.00';
        $openingCreditTotal = '0.00';
        $closingDebitTotal = '0.00';
        $closingCreditTotal = '0.00';

        foreach ($rows as $row) {
            $row->kind_label = self::kindLabel($row->kind);
            $openNet = (string) ($openingByAccount[$row->account_id] ?? '0.00');
            $row->opening_debit = bccomp($openNet, '0.00', 2) > 0 ? $openNet : '0.00';
            $row->opening_credit = bccomp($openNet, '0.00', 2) < 0 ? bcsub('0.00', $openNet, 2) : '0.00';
            $closingNet = bcadd($openNet, (string) $row->balance, 2);
            $row->closing_net = $closingNet;
            $row->closing_debit = bccomp($closingNet, '0.00', 2) > 0 ? $closingNet : '0.00';
            $row->closing_credit = bccomp($closingNet, '0.00', 2) < 0 ? bcsub('0.00', $closingNet, 2) : '0.00';

            $openingDebitTotal = bcadd($openingDebitTotal, $row->opening_debit, 2);
            $openingCreditTotal = bcadd($openingCreditTotal, $row->opening_credit, 2);
            $closingDebitTotal = bcadd($closingDebitTotal, $row->closing_debit, 2);
            $closingCreditTotal = bcadd($closingCreditTotal, $row->closing_credit, 2);
        }

        $debit = $this->sum($rows, 'debit');
        $credit = $this->sum($rows, 'credit');
        $difference = bcsub($debit, $credit, 2);

        return [
            'rows' => $rows,
            'debit' => $debit,
            'credit' => $credit,
            'difference' => $difference,
            'balanced' => bccomp($difference, '0.00', 2) === 0,
            'opening_debit' => $openingDebitTotal,
            'opening_credit' => $openingCreditTotal,
            'closing_debit' => $closingDebitTotal,
            'closing_credit' => $closingCreditTotal,
        ];
    }

    public function statement(int $actor, string $statement, ?int $branch, ?string $from, ?string $to, ?string $kind = null, ?string $search = null): array
    {
        abort_unless(in_array($statement, ['income', 'balance'], true), 422);
        $allowedKinds = $statement === 'income' ? ['income', 'expense'] : ['asset', 'liability', 'equity'];
        $activeKinds = ($kind !== null && in_array($kind, $allowedKinds, true)) ? [$kind] : $allowedKinds;
        $trial = collect($this->trialBalance($actor, $branch, $from, $to, null, $search)['rows']);
        $kindsByAccount = DB::table('accounting_accounts')->whereIn('kind', $activeKinds)->pluck('kind', 'id');

        $rows = $trial->filter(fn ($row) => isset($kindsByAccount[$row->account_id]))->map(function ($row) use ($kindsByAccount) {
            $row->kind = $kindsByAccount[$row->account_id];
            $row->kind_label = self::kindLabel($row->kind);
            $natural = in_array($row->kind, ['liability', 'equity', 'income'], true)
                ? bcsub('0.00', (string) $row->balance, 2)
                : bcadd((string) $row->balance, '0.00', 2);
            $row->natural_balance = $natural;

            return $row;
        })->values();

        $debit = $this->sum($rows, 'debit');
        $credit = $this->sum($rows, 'credit');
        $net = $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->balance, 2), '0.00');

        $byKind = [];
        foreach ($allowedKinds as $k) {
            $groupRows = $rows->where('kind', $k)->values();
            $groupNatural = $groupRows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->natural_balance, 2), '0.00');
            foreach ($groupRows as $r) {
                $r->share_percent = bccomp($groupNatural, '0.00', 2) > 0 && bccomp((string) $r->natural_balance, '0.00', 2) > 0
                    ? bcdiv(bcmul((string) $r->natural_balance, '100', 4), $groupNatural, 1)
                    : '0.0';
            }
            $byKind[$k] = [
                'label' => self::kindLabel($k),
                'count' => $groupRows->count(),
                'debit' => $this->sum($groupRows, 'debit'),
                'credit' => $this->sum($groupRows, 'credit'),
                'balance' => $groupRows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->balance, 2), '0.00'),
                'natural' => $groupNatural,
                'rows' => $groupRows,
            ];
        }

        $summary = ['by_kind' => $byKind];
        if ($statement === 'income') {
            $totalIncome = $byKind['income']['natural'] ?? '0.00';
            $totalExpense = $byKind['expense']['natural'] ?? '0.00';
            $netProfit = bcsub($totalIncome, $totalExpense, 2);
            $summary['total_income'] = $totalIncome;
            $summary['total_expense'] = $totalExpense;
            $summary['net_profit'] = $netProfit;
            $summary['profitable'] = bccomp($netProfit, '0.00', 2) >= 0;
            $summary['profit_margin'] = bccomp($totalIncome, '0.00', 2) > 0
                ? bcdiv(bcmul($netProfit, '100', 4), $totalIncome, 1)
                : null;
        } else {
            $totalAssets = $byKind['asset']['natural'] ?? '0.00';
            $totalLiabilities = $byKind['liability']['natural'] ?? '0.00';
            $totalEquity = $byKind['equity']['natural'] ?? '0.00';
            $plTrial = collect($this->trialBalance($actor, $branch, null, $to, null, null)['rows']);
            $plKinds = DB::table('accounting_accounts')->whereIn('kind', ['income', 'expense'])->pluck('kind', 'id');
            $retainedPeriod = $plTrial->filter(fn ($r) => isset($plKinds[$r->account_id]))
                ->reduce(fn (string $sum, $r) => bcadd($sum, bcsub('0.00', (string) $r->balance, 2), 2), '0.00');
            $totalEquityWithRetained = bcadd($totalEquity, $retainedPeriod, 2);
            $liabilitiesPlusEquity = bcadd($totalLiabilities, $totalEquityWithRetained, 2);
            $equationDiff = bcsub($totalAssets, $liabilitiesPlusEquity, 2);

            $summary['total_assets'] = $totalAssets;
            $summary['total_liabilities'] = $totalLiabilities;
            $summary['total_equity'] = $totalEquity;
            $summary['current_earnings'] = $retainedPeriod;
            $summary['total_equity_with_earnings'] = $totalEquityWithRetained;
            $summary['liabilities_plus_equity'] = $liabilitiesPlusEquity;
            $summary['equation_difference'] = $equationDiff;
            $summary['equation_balanced'] = bccomp($equationDiff, '0.00', 2) === 0;
        }

        return [
            'rows' => $rows,
            'debit' => $debit,
            'credit' => $credit,
            'net' => $net,
            'summary' => $summary,
        ];
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
            ['key' => 'grni', 'label' => 'استلامات بانتظار الفوترة مقابل الاستلامات غير المفوترة', 'gl' => bcsub('0', $gl('grni'), 2), 'source' => bcsub($grni, $cleared, 2)],
            ['key' => 'inventory', 'label' => 'المخزون العام مقابل طبقات الصرف من الأقدم أولًا', 'gl' => bcadd($gl('inventory'), '0', 2), 'source' => bcadd($fifo, '0', 2)],
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
                    $this->fail('لا يمكن الإقفال قبل نجاح مصالحات المخزون واستلامات بانتظار الفوترة والذمم لكل الفروع.');
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

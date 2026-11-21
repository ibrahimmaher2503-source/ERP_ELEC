<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

final class FinancialReportsPage extends Component
{
    #[Locked]
    public string $screen;

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $account = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $source = '';

    public bool $showReopenModal = false;

    public ?int $reopenPeriodId = null;

    public int $reopenPeriodVersion = 0;

    public string $reopenPeriodName = '';

    public string $reopenReason = '';

    public bool $showEvidenceModal = false;

    public ?array $inspectedClosure = null;

    public function mount(string $screen): void
    {
        $this->screen = $screen;
        if ($this->from === '') {
            $this->from = now('Africa/Cairo')->startOfYear()->toDateString();
        }
        if ($this->to === '') {
            $this->to = now('Africa/Cairo')->toDateString();
        }
    }

    public function applyDatePreset(string $preset): void
    {
        $now = now('Africa/Cairo');
        [$from, $to] = match ($preset) {
            'today' => [$now->toDateString(), $now->toDateString()],
            'month' => [$now->startOfMonth()->toDateString(), $now->toDateString()],
            'quarter' => [$now->startOfQuarter()->toDateString(), $now->toDateString()],
            'year' => [$now->startOfYear()->toDateString(), $now->toDateString()],
            'all' => ['', $now->toDateString()],
            default => [$this->from, $this->to],
        };
        if (! in_array($this->screen, ['balance-sheet', 'grni', 'reconciliation'], true)) {
            $this->from = $from;
        }
        $this->to = $to;
    }

    public function applyPeriodRange(int $periodId): void
    {
        $period = DB::table('accounting_periods')->find($periodId);
        if (! $period) {
            return;
        }
        if (! in_array($this->screen, ['balance-sheet', 'grni', 'reconciliation'], true)) {
            $this->from = (string) $period->starts_on;
        }
        $this->to = (string) $period->ends_on;
    }

    public function close(int $period): void
    {
        app(FinancialReports::class)->closePeriod(auth()->id(), $period);
        session()->flash('accounting_message', 'تم إقفال الفترة بعد نجاح المصالحات الإلزامية.');
    }

    public function openReopenModal(int $periodId): void
    {
        $row = DB::table('accounting_periods')->find($periodId);
        if (! $row || $row->status !== 'closed') {
            return;
        }
        $this->resetValidation();
        $this->reopenPeriodId = (int) $row->id;
        $this->reopenPeriodVersion = (int) $row->version;
        $this->reopenPeriodName = (string) $row->name;
        $this->reopenReason = '';
        $this->showReopenModal = true;
    }

    public function confirmReopenPeriod(): void
    {
        if ($this->reopenPeriodId === null) {
            return;
        }
        app(AccountingActions::class)->reopen(auth()->id(), $this->reopenPeriodId, $this->reopenPeriodVersion, $this->reopenReason);
        $this->showReopenModal = false;
        $this->reopenPeriodId = null;
        $this->reopenReason = '';
        session()->flash('accounting_message', 'تمت إعادة فتح الفترة المالية وتوثيق السبب في سجل المراجعة.');
    }

    public function inspectClosure(int $periodId): void
    {
        $closure = DB::table('accounting_period_closures')->where('period_id', $periodId)->orderByDesc('id')->first();
        $period = DB::table('accounting_periods')->find($periodId);
        if (! $closure || ! $period) {
            return;
        }
        $evidence = json_decode((string) $closure->evidence, true) ?: [];
        $this->inspectedClosure = [
            'period_name' => (string) $period->name,
            'starts_on' => (string) $period->starts_on,
            'cutoff' => (string) $closure->cutoff,
            'closed_by' => (int) $closure->closed_by,
            'closed_at' => (string) $closure->created_at,
            'sha256' => (string) $closure->sha256,
            'period_version' => (int) $closure->period_version,
            'branches' => $evidence['branches'] ?? [],
            'reconciliations' => $evidence['reconciliations'] ?? [],
        ];
        $this->showEvidenceModal = true;
    }

    public function render()
    {
        $access = app(AccountingAccess::class);
        $nav = $access->navigation(auth()->id());
        abort_unless(isset($nav[$this->screen]), 403);
        $branches = $access->branches(auth()->id(), 'report_view');
        abort_if($this->screen !== 'closing' && $branches === [], 403);
        $branch = $this->branch === '' ? null : (int) $this->branch;
        $accountId = $this->account === '' ? null : (int) $this->account;
        $kind = $this->kind === '' ? null : $this->kind;
        $source = $this->source === '' ? null : $this->source;
        $search = trim($this->search);
        $reports = app(FinancialReports::class);
        $validator = Validator::make($this->only(['branch', 'from', 'to', 'account', 'kind', 'source']), [
            'branch' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($this->from !== '' && ! in_array($this->screen, ['balance-sheet', 'grni', 'reconciliation'], true) ? '|after_or_equal:from' : ''),
            'account' => 'nullable|integer|min:1',
            'kind' => 'nullable|string|in:asset,liability,equity,income,expense',
            'source' => 'nullable|string|in:manual,system',
        ]);
        $this->resetValidation(['branch', 'from', 'to', 'account', 'kind', 'source']);
        $filtersValid = $this->screen === 'closing' || ! $validator->fails();
        if (! $filtersValid) {
            $this->setErrorBag($validator->errors());
        }

        $accounts = in_array($this->screen, ['ledger', 'trial-balance'], true)
            ? DB::table('accounting_accounts')->where('postable', true)->orderBy('code')->get(['id', 'code', 'name', 'kind'])
            : collect();
        $periods = $this->screen !== 'closing'
            ? DB::table('accounting_periods')->orderByDesc('starts_on')->limit(6)->get(['id', 'name', 'starts_on', 'ends_on', 'status'])
            : collect();

        $data = $filtersValid ? match ($this->screen) {
            'ledger' => $reports->ledger(auth()->id(), $branch, $this->from ?: null, $this->to ?: null, $accountId, $search ?: null, $source),
            'trial-balance' => $reports->trialBalance(auth()->id(), $branch, $this->from ?: null, $this->to ?: null, $kind, $search ?: null),
            'income-statement' => $reports->statement(auth()->id(), 'income', $branch, $this->from ?: null, $this->to ?: null, $kind, $search ?: null),
            'balance-sheet' => $reports->statement(auth()->id(), 'balance', $branch, null, $this->to ?: null, $kind, $search ?: null),
            'ppv' => $this->buildPpvData($reports->ppv(auth()->id(), $branch, $this->from ?: null, $this->to ?: null), $search),
            'grni', 'reconciliation' => $this->buildReconciliationData($reports->reconciliations(auth()->id(), $branch, $this->to ?: null), $this->screen, $this->status, $search),
            'closing' => $this->buildClosingData(),
            default => abort(404),
        } : null;

        return view('accounting.reports', compact('access', 'nav', 'branches', 'accounts', 'periods', 'data', 'filtersValid'))
            ->layout('layouts::app', ['title' => $nav[$this->screen]]);
    }

    private function buildPpvData(array $rawRows, string $search): array
    {
        $needle = mb_strtolower($search);
        $rows = collect($rawRows)->filter(function (array $row) use ($needle): bool {
            if ($needle === '') {
                return true;
            }

            return str_contains(mb_strtolower((string) ($row['official_number'] ?? '')), $needle)
                || str_contains(mb_strtolower((string) ($row['supplier_name'] ?? '')), $needle);
        })->values();

        $grniTotal = $rows->reduce(fn (string $sum, array $r): string => bcadd($sum, (string) ($r['grni_amount'] ?? '0.00'), 2), '0.00');
        $ppvTotal = $rows->reduce(fn (string $sum, array $r): string => bcadd($sum, (string) ($r['ppv_amount'] ?? '0.00'), 2), '0.00');
        $invoiceTotal = $rows->reduce(fn (string $sum, array $r): string => bcadd($sum, (string) ($r['total_amount'] ?? '0.00'), 2), '0.00');

        return [
            'rows' => $rows,
            'grni_total' => $grniTotal,
            'ppv_total' => $ppvTotal,
            'invoice_total' => $invoiceTotal,
        ];
    }

    private function buildReconciliationData(array $rawRows, string $screen, string $status, string $search): array
    {
        $needle = mb_strtolower($search);
        $scoped = collect($rawRows)
            ->when($screen === 'grni', fn ($c) => $c->where('key', 'grni'))
            ->values();

        $matchedCount = $scoped->where('ok', true)->count();
        $mismatchedCount = $scoped->where('ok', false)->count();
        $glTotal = $scoped->reduce(fn (string $sum, array $r): string => bcadd($sum, (string) $r['gl'], 2), '0.00');
        $sourceTotal = $scoped->reduce(fn (string $sum, array $r): string => bcadd($sum, (string) $r['source'], 2), '0.00');
        $diffTotal = $scoped->reduce(fn (string $sum, array $r): string => bcadd($sum, (string) $r['difference'], 2), '0.00');

        $rows = $scoped
            ->when($status === 'matched', fn ($c) => $c->where('ok', true))
            ->when($status === 'mismatch', fn ($c) => $c->where('ok', false))
            ->when($needle !== '', fn ($c) => $c->filter(fn (array $r) => str_contains(mb_strtolower((string) $r['label']), $needle) || str_contains(mb_strtolower((string) $r['key']), $needle)))
            ->values();

        return [
            'rows' => $rows,
            'total_count' => $scoped->count(),
            'matched_count' => $matchedCount,
            'mismatched_count' => $mismatchedCount,
            'gl_total' => $glTotal,
            'source_total' => $sourceTotal,
            'diff_total' => $diffTotal,
            'all_ok' => $mismatchedCount === 0,
        ];
    }

    private function buildClosingData(): array
    {
        $periods = DB::table('accounting_periods')->orderByDesc('starts_on')->get();
        $closures = DB::table('accounting_period_closures')->get()->keyBy('period_id');

        foreach ($periods as $p) {
            $p->unposted_count = DB::table('accounting_journals')
                ->whereBetween('journal_date', [$p->starts_on, $p->ends_on])
                ->where('status', '<>', 'posted')
                ->count();
            $p->posted_count = DB::table('accounting_journals')
                ->whereBetween('journal_date', [$p->starts_on, $p->ends_on])
                ->where('status', 'posted')
                ->count();
            $closure = $closures->get($p->id);
            $p->closed_at = $closure?->created_at;
            $p->closed_by = $closure?->closed_by;
            $p->closure_sha256 = $closure?->sha256;
        }

        return [
            'rows' => $periods,
            'open_count' => $periods->where('status', 'open')->count(),
            'closed_count' => $periods->where('status', 'closed')->count(),
            'unposted_total' => $periods->where('status', 'open')->sum('unposted_count'),
        ];
    }
}

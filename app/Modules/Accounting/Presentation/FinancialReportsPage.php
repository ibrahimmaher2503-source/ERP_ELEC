<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\FinancialReports;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class FinancialReportsPage extends Component
{
    #[Locked]
    public string $screen;

    public string $branch = '';

    public string $from = '';

    public string $to = '';

    public function mount(string $screen): void
    {
        $this->screen = $screen;
        $this->from = now('Africa/Cairo')->startOfYear()->toDateString();
        $this->to = now('Africa/Cairo')->toDateString();
    }

    public function close(int $period): void
    {
        app(FinancialReports::class)->closePeriod(auth()->id(), $period);
        session()->flash('accounting_message', 'تم إقفال الفترة بعد نجاح المصالحات الإلزامية.');
    }

    public function render()
    {
        $access = app(AccountingAccess::class);
        $nav = $access->navigation(auth()->id());
        abort_unless(isset($nav[$this->screen]), 403);
        $branches = $access->branches(auth()->id(), 'report_view');
        abort_if($this->screen !== 'closing' && $branches === [], 403);
        $branch = $this->branch === '' ? null : (int) $this->branch;
        $reports = app(FinancialReports::class);
        $validator = Validator::make($this->only(['branch', 'from', 'to']), [
            'branch' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($this->from !== '' && ! in_array($this->screen, ['balance-sheet', 'grni', 'reconciliation'], true) ? '|after_or_equal:from' : ''),
        ]);
        $this->resetValidation(['branch', 'from', 'to']);
        $filtersValid = $this->screen === 'closing' || ! $validator->fails();
        if (! $filtersValid) {
            $this->setErrorBag($validator->errors());
        }
        $data = $filtersValid ? match ($this->screen) {
            'ledger' => $reports->ledger(auth()->id(), $branch, $this->from ?: null, $this->to ?: null),
            'trial-balance' => $reports->trialBalance(auth()->id(), $branch, $this->from ?: null, $this->to ?: null),
            'income-statement' => $reports->statement(auth()->id(), 'income', $branch, $this->from ?: null, $this->to ?: null),
            'balance-sheet' => $reports->statement(auth()->id(), 'balance', $branch, null, $this->to ?: null),
            'ppv' => ['rows' => $reports->ppv(auth()->id(), $branch, $this->from ?: null, $this->to ?: null)],
            'grni', 'reconciliation' => ['rows' => $reports->reconciliations(auth()->id(), $branch, $this->to ?: null)],
            'closing' => ['rows' => \DB::table('accounting_periods')->orderByDesc('starts_on')->get()],
            default => abort(404),
        } : null;

        return view('accounting.reports', compact('access', 'nav', 'branches', 'data', 'filtersValid'))->layout('layouts::app', ['title' => $nav[$this->screen]]);
    }
}

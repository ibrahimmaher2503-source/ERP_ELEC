<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\Contracts\AccountingApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class AccountingPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $screen = 'dashboard';

    #[Locked]
    public ?int $selected = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    public string $search = '';

    public array $form = [];

    public string $reversalReason = '';

    public function mount(string $screen = 'dashboard'): void
    {
        $this->screen = $screen;
        $this->freshForm();
    }

    private function freshForm(): void
    {
        $this->selected = null;
        $this->version = 0;
        $this->creationKey = (string) Str::uuid();
        $this->form = match ($this->screen) {
            'accounts' => ['code' => '', 'name' => '', 'kind' => 'asset', 'parent_id' => '', 'postable' => true, 'active' => true],
            'periods' => ['name' => '', 'starts_on' => '', 'ends_on' => ''],
            default => ['project_id' => '', 'branch_id' => '', 'journal_date' => now('Africa/Cairo')->toDateString(), 'description' => '', 'lines' => [['account_id' => '', 'debit' => '0.00', 'credit' => '0.00', 'description' => ''], ['account_id' => '', 'debit' => '0.00', 'credit' => '0.00', 'description' => '']]]
        };
    }

    public function newRecord(): void
    {
        $this->resetValidation();
        $this->freshForm();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function edit(int $id): void
    {
        $a = app(AccountingAccess::class);
        $actor = auth()->id();
        $this->resetValidation();
        if ($this->screen === 'accounts') {
            $a->authorize($actor, 'account_view');
            $r = DB::table('accounting_accounts')->find($id);
            abort_unless($r, 404);
            $this->form = array_intersect_key((array) $r, array_flip(['code', 'name', 'kind', 'parent_id', 'postable', 'active']));
            $this->form['parent_id'] = $r->parent_id ?? '';
        } elseif ($this->screen === 'journals') {
            $r = DB::table('accounting_journals')->find($id);
            abort_unless($r, 404);
            $a->authorize($actor, 'journal_view', $r->branch_id);
            $this->creationKey = $r->creation_key;
            $this->form = ['branch_id' => $r->branch_id, 'journal_date' => $r->journal_date, 'description' => $r->description, 'project_id' => DB::table('accounting_project_dimensions')->where('journal_id', $id)->value('project_id') ?? '', 'lines' => DB::table('accounting_journal_lines')->where('journal_id', $id)->orderBy('id')->get(['account_id', 'debit', 'credit', 'description'])->map(fn ($l) => (array) $l)->all()];
        } else {
            abort(404);
        }
        $this->selected = $id;
        $this->version = $r->version;
    }

    public function addLine(): void
    {
        abort_unless($this->screen === 'journals', 404);
        if (count($this->form['lines']) < 100) {
            $this->form['lines'][] = ['account_id' => '', 'debit' => '0.00', 'credit' => '0.00', 'description' => ''];
        }
    }

    public function removeLine(int $index): void
    {
        abort_unless($this->screen === 'journals', 404);
        unset($this->form['lines'][$index]);
        $this->form['lines'] = array_values($this->form['lines']);
    }

    public function save(): void
    {
        $a = app(AccountingActions::class);
        $actor = auth()->id();
        if ($this->screen === 'accounts') {
            $input = $this->form;
            $input['parent_id'] = ($input['parent_id'] ?? '') ?: null;
            $id = $a->account($actor, $this->selected, $this->version, $input);
            $this->edit($id);
        } elseif ($this->screen === 'periods') {
            $a->period($actor, $this->form);
            $this->freshForm();
        } elseif ($this->screen === 'journals') {
            $id = $a->journal($actor, $this->selected, $this->version, $this->creationKey, $this->form);
            $this->edit($id);
        } else {
            abort(404);
        }
        session()->flash('accounting_message', 'تم الحفظ.');
    }

    public function advance(int $id, int $version, string $step): void
    {
        app(AccountingActions::class)->advance(auth()->id(), $id, $version, $step);
        if ($this->selected === $id) {
            $this->edit($id);
        }session()->flash('accounting_message', 'تم تحديث حالة القيد.');
    }

    public function reverse(int $id): void
    {
        $reversal = app(AccountingApi::class)->reversePostedJournal(auth()->id(), $id, now('Africa/Cairo')->toDateString(), $this->reversalReason);
        $this->reversalReason = '';
        $this->edit($reversal);
        session()->flash('accounting_message', 'تم إنشاء قيد عكسي مرتبط مع بقاء القيد الأصلي ثابتاً.');
    }

    public function render()
    {
        $a = app(AccountingAccess::class);
        $actor = auth()->id();
        $nav = $a->navigation($actor);
        abort_unless(isset($nav[$this->screen]), 403);
        $branches = $a->branches($actor);
        $branchIds = array_column($branches, 'id');
        $accounts = ($a->allows($actor, 'account_view') || $branchIds !== []) ? DB::table('accounting_accounts')->orderBy('code')->get() : collect();
        $q = match ($this->screen) {
            'accounts' => DB::table('accounting_accounts')->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$this->search.'%')->orWhere('code', 'ilike', '%'.$this->search.'%')))->orderBy('code'),'periods' => DB::table('accounting_periods')->orderByDesc('starts_on'),default => DB::table('accounting_journals')->whereIn('branch_id', $branchIds)->when($this->search !== '', fn ($q) => $q->where('description', 'ilike', '%'.$this->search.'%'))->orderByDesc('id')
        };
        $rows = $q->paginate(20);
        $stats = DB::table('accounting_journals')->whereIn('branch_id', $branchIds)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $journalRecord = $this->screen === 'journals' && $this->selected ? DB::table('accounting_journals')->whereIn('branch_id', $branchIds)->find($this->selected) : null;

        return view('accounting.index', compact('a', 'nav', 'accounts', 'branches', 'rows', 'stats', 'journalRecord'))->layout('layouts::app', ['title' => $nav[$this->screen]]);
    }
}

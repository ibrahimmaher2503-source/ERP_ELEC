<?php

namespace App\Modules\Treasury\Presentation;

use App\Modules\Treasury\Application\TreasuryAccess;
use App\Modules\Treasury\Application\TreasuryStatementActions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

final class TreasuryStatementsPage extends Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public ?int $statementId = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey;

    public string $branch = '';

    public array $form = ['branch_id' => '', 'account_id' => '', 'external_reference' => '', 'starts_on' => '', 'ends_on' => '', 'opening_balance' => '', 'closing_balance' => ''];

    public $file;

    public string $lineId = '';

    public string $movementId = '';

    public string $matchAmount = '';

    public string $reason = '';

    public function mount(?int $statement = null): void
    {
        $this->statementId = $statement;
        $this->creationKey = (string) Str::uuid();
        if ($statement) {
            $this->refreshVersion();
        }
    }

    private function refreshVersion(): void
    {
        $this->version = (int) app(TreasuryStatementActions::class)->view(auth()->id(), $this->statementId)['statement']['version'];
    }

    public function updatedBranch(): void
    {
        $this->resetPage();
    }

    public function import(): void
    {
        $this->validate(['file' => 'required|file|max:2048']);
        $id = app(TreasuryStatementActions::class)->import(auth()->id(), $this->creationKey, $this->file, $this->form);
        $this->redirectRoute('treasury.statements.show', ['statement' => $id], navigate: true);
    }

    public function matchLine(): void
    {
        app(TreasuryStatementActions::class)->match(auth()->id(), $this->statementId, (int) $this->lineId, (int) $this->movementId, $this->version, $this->reason, $this->matchAmount === '' ? null : $this->matchAmount);
        $this->refreshVersion();
        session()->flash('status', 'تم ربط سطر البنك بالحركة الفعلية.');
    }

    public function unmatch(int $match): void
    {
        app(TreasuryStatementActions::class)->unmatch(auth()->id(), $this->statementId, $match, $this->version, $this->reason);
        $this->refreshVersion();
        session()->flash('status', 'تم رفع المطابقة مع الاحتفاظ بسجلها.');
    }

    public function review(): void
    {
        app(TreasuryStatementActions::class)->review(auth()->id(), $this->statementId, $this->version, $this->reason);
        $this->refreshVersion();
        session()->flash('status', 'تم حفظ نتيجة المراجعة كما هي، بما فيها الفروق إن وجدت.');
    }

    public function reopen(): void
    {
        app(TreasuryStatementActions::class)->reopen(auth()->id(), $this->statementId, $this->version, $this->reason);
        $this->refreshVersion();
        session()->flash('status', 'أعيد فتح المطابقة؛ المراجعة السابقة محفوظة.');
    }

    public function render()
    {
        $access = app(TreasuryAccess::class);
        $branches = $access->branches(auth()->id(), 'statement_view');
        abort_unless($branches, 403);
        $ids = array_column($branches, 'id');
        $manageBranches = array_values(array_filter($access->branches(auth()->id(), 'statement_manage'), fn ($b) => in_array($b['id'], $ids, true)));
        $rows = DB::table('treasury_statements')->whereIn('branch_id', $ids)->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch))->orderByDesc('id')->paginate(25);
        $detail = $this->statementId ? app(TreasuryStatementActions::class)->view(auth()->id(), $this->statementId) : null;
        $accounts = $manageBranches ? DB::table('treasury_accounts')->where('kind', 'bank')->orderBy('name')->get(['id', 'name', 'active']) : collect();

        return view('treasury.statements', compact('rows', 'detail', 'branches', 'manageBranches', 'accounts', 'access'));
    }
}

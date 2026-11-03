<?php

namespace App\Modules\ServiceContracts\Presentation;

use App\Modules\ServiceContracts\Application\ServiceContractAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

final class ServiceContractsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $branch = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedBranch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $access = app(ServiceContractAccess::class);
        $branches = $access->branches(auth()->id());
        abort_unless($branches, 403);
        $rows = DB::table('service_contracts as c')->join('service_contract_revisions as r', 'r.id', '=', 'c.current_revision_id')
            ->whereIn('c.branch_id', array_column($branches, 'id'))->when($this->branch !== '', fn ($q) => $q->where('c.branch_id', (int) $this->branch))
            ->when($this->status !== '', fn ($q) => $q->where('c.status', $this->status))
            ->when($this->search !== '', fn ($q) => $q->whereRaw("(r.data->>'title' ILIKE ? OR r.data->>'customer_name' ILIKE ?)", ['%'.mb_substr($this->search, 0, 200).'%', '%'.mb_substr($this->search, 0, 200).'%']))
            ->select(['c.*', 'r.data', 'r.revision'])->orderByDesc('c.id')->paginate(25);

        return view('service-contracts.index', compact('rows', 'branches', 'access'));
    }
}

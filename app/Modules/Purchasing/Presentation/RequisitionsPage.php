<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use Livewire\Component;
use Livewire\WithPagination;

final class RequisitionsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $branch = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'branch'])) {
            $this->resetPage();
        }
    }

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $branches = $access->branches(auth()->id());
        abort_unless($branches, 403, 'امنح صلاحية طلبات الاحتياج ونطاق الفرع من تفاصيل المستخدم.');
        $q = PurchaseRequisition::whereIn('branch_id', array_column($branches, 'id'));
        abort_if($this->branch !== '' && ! ctype_digit($this->branch), 422);
        $stats = (clone $q)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $q->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->branch !== '', fn ($q) => $q->where('branch_id', $this->branch))
            ->when($this->search !== '', fn ($q) => $q->where('purpose', 'ilike', '%'.mb_substr($this->search, 0, 200).'%'));

        return view('purchasing.requisitions', ['rows' => $q->withCount('lines')->orderByDesc('id')->paginate(15), 'branches' => $branches, 'stats' => $stats, 'canCreate' => $access->branches(auth()->id(), 'requisition_create') !== []])->layout('layouts::app', ['title' => 'طلبات الاحتياج']);
    }
}

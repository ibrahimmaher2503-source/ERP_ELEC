<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Domain\PurchaseRfq;
use Livewire\Component;
use Livewire\WithPagination;

final class RfqsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $branches = app(PurchasingAccess::class)->branches(auth()->id(), 'rfq_view');
        abort_unless($branches, 403, 'صلاحية طلبات عروض الموردين ونطاق الفرع مطلوبان.');
        $q = PurchaseRfq::whereIn('branch_id', array_column($branches, 'id'))
            ->when($this->search !== '', fn ($q) => $q->where('subject', 'ilike', '%'.mb_substr($this->search, 0, 200).'%'))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status));

        return view('purchasing.rfqs', ['rows' => $q->withCount(['lines', 'suppliers'])->orderByDesc('id')->paginate(15), 'canCreate' => app(PurchasingAccess::class)->branches(auth()->id(), 'rfq_create') !== []])->layout('layouts::app', ['title' => 'طلبات عروض الموردين']);
    }
}

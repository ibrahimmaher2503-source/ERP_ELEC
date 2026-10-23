<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

final class OffersPage extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $branches = $access->branches(auth()->id(), 'offer_view');
        abort_unless($branches, 403);
        $rows = DB::table('purchasing_offers')->whereIn('branch_id', array_column($branches, 'id'))->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('supplier_name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')->orWhere('supplier_reference', 'ilike', '%'.mb_substr($this->search, 0, 200).'%')))->orderByDesc('id')->paginate(15);

        return view('purchasing.offers', ['rows' => $rows, 'canCreate' => $access->branches(auth()->id(), 'offer_create') !== []])->layout('layouts::app', ['title' => 'عروض الموردين']);
    }
}

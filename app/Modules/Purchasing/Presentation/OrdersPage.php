<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

final class OrdersPage extends Component
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
        $access = app(PurchasingAccess::class);
        $ids = array_column($access->branches(auth()->id(), 'order_view'), 'id');
        abort_unless($ids, 403);
        $q = DB::table('purchasing_orders')->whereIn('branch_id', $ids)->when($this->search !== '', fn ($q) => $q->where('supplier_name', 'ilike', '%'.mb_substr($this->search, 0, 200).'%'))->when($this->status !== '', fn ($q) => $q->where('status', $this->status));
        $stats = DB::table('purchasing_orders')->whereIn('branch_id', $ids)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('purchasing.orders', ['rows' => $q->orderByDesc('id')->paginate(15), 'stats' => $stats, 'canCreate' => $access->branches(auth()->id(), 'order_create') !== []])->layout('layouts::app', ['title' => 'أوامر الشراء']);
    }
}

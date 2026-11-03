<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchaseOrderProjection;
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
        $projection = app(PurchaseOrderProjection::class);
        $q = $projection->query()->whereIn('branch_id', $ids);
        $projection->filter($q, $this->status, $this->search);
        $stats = DB::table('purchasing_orders')->whereIn('branch_id', $ids)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('purchasing.orders', ['rows' => $q->orderByDesc('id')->paginate(15), 'stats' => $stats, 'canCreate' => $access->branches(auth()->id(), 'order_create') !== []])->layout('layouts::app', ['title' => 'أوامر الشراء']);
    }
}

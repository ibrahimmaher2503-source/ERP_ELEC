<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

final class ReceiptsPage extends Component
{
    use WithPagination;

    public function render()
    {
        $access = app(PurchasingAccess::class);
        $ids = array_column($access->branches(auth()->id(), 'receipt_view'), 'id');
        abort_unless($ids, 403);
        $warehouseIds = array_column(app(PurchaseReceiving::class)->warehouses(auth()->id()), 'id');
        $rows = DB::table('purchasing_receipts')->whereIn('branch_id', $ids)->whereIn('warehouse_id', $warehouseIds)->orderByDesc('id')->paginate(15);

        return view('purchasing.receipts', ['rows' => $rows, 'canCreate' => $access->branches(auth()->id(), 'receipt_create') !== []])->layout('layouts::app', ['title' => 'استلام المشتريات']);
    }
}

<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class InventoryExportController
{
    public function __invoke(string $entity)
    {
        request()->validate(['warehouse' => 'nullable|integer|min:1', 'branch' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:100', 'status' => 'nullable|string|max:30', 'category' => 'nullable|integer|min:1', 'operation' => 'nullable|string|max:30']);
        $actor = Auth::id();
        $query = app(InventoryQuery::class);
        $access = app(InventoryAccess::class);
        $ids = $access->ids($actor, 'export');
        abort_unless($ids !== [], 403);
        [$q,$columns] = match ($entity) {
            'balances' => [$query->balances($actor), ['sku' => 'SKU', 'product_name' => 'الصنف', 'warehouse_id' => 'المستودع', 'unit_name' => 'الوحدة', 'on_hand' => 'الرصيد الفعلي', 'unavailable' => 'Unavailable', 'reserved' => 'المحجوز', 'available' => 'المتاح', 'minimum' => 'الحد الأدنى']],
            'movements' => [$query->movements($actor), ['id' => 'الحركة', 'sku' => 'SKU', 'product_name' => 'الصنف', 'warehouse_id' => 'المستودع', 'quantity' => 'الكمية', 'operation' => 'العملية', 'source_module' => 'المصدر', 'source_id' => 'المرجع', 'posted_at' => 'وقت الترحيل UTC']],
            'reservations' => [$query->reservations($actor), ['id' => 'الحجز', 'product_id' => 'الصنف', 'warehouse_id' => 'المستودع', 'requested' => 'المطلوب', 'reserved' => 'المحجوز أصلًا', 'fulfilled' => 'المنفذ', 'released' => 'المحرر', 'source_id' => 'المصدر']],
            'warehouses' => [$query->warehouses($actor), ['code' => 'الكود', 'name' => 'المستودع', 'branch_id' => 'الفرع', 'address' => 'العنوان', 'active' => 'نشط']],
            default => isset(InventoryDocuments::KINDS[$entity]) ? [$query->documents($actor, $entity), ['id' => 'المرجع التقني', 'kind' => 'نوع المستند', 'warehouse_id' => 'المستودع', 'destination_id' => 'الوجهة', 'status' => 'الحالة', 'reason' => 'السبب', 'created_at' => 'تاريخ الإنشاء UTC']] : abort(404),
        };
        $q->whereIn($entity === 'warehouses' ? 'id' : 'warehouse_id', $ids);
        if ($entity === 'transfer') {
            $q->whereIn('destination_id', $ids);
        }
        if (ctype_digit((string) request('branch'))) {
            $warehouseIds = DB::table('inventory_warehouses')->where('branch_id', (int) request('branch'))->pluck('id');
            $q->whereIn($entity === 'warehouses' ? 'id' : 'warehouse_id', $warehouseIds);
        }
        if (in_array($entity, ['balances', 'movements', 'reservations'], true)) {
            $query->filter($q, 'inventory_'.$entity, request()->only(['search', 'status', 'operation', 'category']));
        }
        if (request('warehouse') && ctype_digit((string) request('warehouse'))) {
            $q->where($entity === 'warehouses' ? 'id' : 'warehouse_id', (int) request('warehouse'));
        }
        $path = app(WorkbookCodec::class)->write('inventory-'.$entity, $columns, (function () use ($q, $columns) {
            foreach ($q->orderBy('id')->limit(10001)->get(array_keys($columns)) as $row) {
                yield (array) $row;
            }
        })());

        return response()->download($path, 'inventory-'.$entity.'.xlsx', ['Cache-Control' => 'private, no-store'])->setPrivate()->deleteFileAfterSend();
    }
}

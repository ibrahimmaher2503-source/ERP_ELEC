<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use Illuminate\Support\Facades\DB;

final class PurchasingService implements PurchasingApi
{
    public function projectDocument(int $actor, string $type, int $id): array
    {
        [$table,$permission,$date] = match ($type) {
            'requisition' => ['purchase_requisitions', 'requisition_view', 'request_date'],'rfq' => ['purchasing_rfqs', 'rfq_view', 'request_date'],'supplier_offer' => ['purchasing_offers', 'offer_view', 'document_date'],'purchase_order' => ['purchasing_orders', 'order_view', 'order_date'],'purchase_receipt' => ['purchasing_receipts', 'receipt_view', 'receipt_date'],default => abort(422)
        };
        $row = DB::table($table)->where('id', $id)->firstOrFail();
        app(PurchasingAccess::class)->authorize($actor, $permission, $row->branch_id);
        if ($type === 'purchase_receipt') {
            abort_unless(in_array($row->warehouse_id, array_column(app(OrganizationApi::class)->inventoryScopes($actor), 'warehouse_id'), true), 403);
        }

        return ['id' => $row->id, 'branchId' => $row->branch_id, 'status' => $row->status, 'date' => $row->$date, 'number' => $row->official_number ?? null];
    }
}

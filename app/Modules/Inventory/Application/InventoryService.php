<?php

namespace App\Modules\Inventory\Application;

use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Support\Facades\DB;

final class InventoryService implements InventoryApi
{
    public function servicePartSerials(int $actor, string $owner, int $work, int $requirement, string $quantity, array $serials, string $purpose, ?int $movement = null): array
    {
        return app(ServicePartSerials::class)->selections($actor, $owner, $work, $requirement, $quantity, $serials, $purpose, $movement);
    }

    public function normalizeSerialSelections(mixed $serials, string $quantity): array
    {
        return array_values(app(InventorySerials::class)->selections($serials, $quantity));
    }

    public function normalizedSerials(mixed $serials, string $quantity): array
    {
        return array_keys(app(InventorySerials::class)->selections($serials, $quantity));
    }

    public function salesIssueEvidence(int $actor, string $issueKey): array
    {
        return app(TradeReturnStock::class)->salesIssueEvidence($actor, $issueKey);
    }

    public function returnSalesIssue(int $actor, string $issueKey, string $quantity, string $key, string $reason, array $serials = []): array
    {
        return app(TradeReturnStock::class)->restoreSalesIssue($actor, $issueKey, $quantity, $key, $reason, $serials);
    }

    public function returnToSupplier(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials = []): array
    {
        return app(TradeReturnStock::class)->returnToSupplier($actor, $movement, $quantity, $key, $reason, $serials);
    }

    public function repairIsolation(int $actor, int $isolation): array
    {
        $i = DB::table('inventory_isolations')->find($isolation);
        abort_unless($i, 404);
        app(InventoryAccess::class)->authorize($actor, 'isolation_manage', $i->warehouse_id);

        return ['id' => $i->id, 'product_id' => $i->product_id, 'warehouse_id' => $i->warehouse_id, 'remaining' => bcsub($i->quantity, $i->released, 8)];
    }

    public function repairEquipmentFromReceipt(int $actor, int $movement): array
    {
        $m = DB::table('inventory_movements')->find($movement);
        abort_unless($m && bccomp($m->quantity, '0', 8) > 0, 422, 'اختر حركة استلام مخزون حقيقية.');
        app(InventoryAccess::class)->authorize($actor, 'isolation_manage', $m->warehouse_id);
        $p = app(ProductsApi::class)->inventoryProduct($actor, $m->product_id);
        $w = DB::table('inventory_warehouses')->find($m->warehouse_id);
        abort_unless($w && $w->active && $p['active'] && $p['kind'] !== 'service' && $p['tracking'] === 'quantity', 422);

        return ['product_id' => $m->product_id, 'warehouse_id' => $m->warehouse_id, 'branch_id' => $w->branch_id, 'source_movement_id' => $m->id];
    }

    public function repairEquipment(int $actor, int $serial): array
    {
        // Isolation acquires Inventory's lock and revalidates the serial atomically.
        // This identity lookup must not invert that lock order.
        $s = DB::table('inventory_serials')->find($serial);
        abort_unless($s, 404);
        app(InventoryAccess::class)->authorize($actor, 'isolation_manage', $s->warehouse_id);
        $w = DB::table('inventory_warehouses')->find($s->warehouse_id);
        abort_unless($w && $w->active && in_array($s->state, ['available', 'unavailable'], true), 409);

        return ['id' => $s->id, 'product_id' => $s->product_id, 'serial' => $s->entered, 'state' => $s->state,
            'warehouse_id' => $s->warehouse_id, 'branch_id' => $w->branch_id, 'source_movement_id' => $s->last_movement_id];
    }

    public function repairEquipmentOptions(int $actor, int $branch, string $search = '', string $selected = ''): array
    {
        $warehouses = DB::table('inventory_warehouses')->whereIn('id', app(InventoryAccess::class)->ids($actor, 'isolation_manage'))
            ->where('branch_id', $branch)->where('active', true)->pluck('name', 'id');
        abort_unless($warehouses->isNotEmpty(), 403, 'لا يوجد مستودع مصرح بعزل معداته للصيانة في هذا الفرع.');
        $products = collect(app(ProductsApi::class)->inventoryProducts($actor, $search))->keyBy('id');
        $serialProducts = $products->where('tracking', 'serial')->keys();
        $quantityProducts = $products->where('tracking', 'quantity')->keys();
        $serials = DB::table('inventory_serials')->whereIn('warehouse_id', $warehouses->keys())->whereIn('product_id', $serialProducts)
            ->where('state', 'available')->orderByDesc('id')->limit(50)->get()->map(fn ($row) => [
                'value' => 'serial:'.$row->id, 'label' => $products[$row->product_id]['name'].' · '.$row->entered.' · '.$warehouses[$row->warehouse_id],
            ])->all();
        $receipts = DB::table('inventory_movements')->whereIn('warehouse_id', $warehouses->keys())->whereIn('product_id', $quantityProducts)
            ->where('quantity', '>', '0')->orderByDesc('id')->limit(50)->get()->map(fn ($row) => [
                'value' => 'receipt:'.$row->id, 'label' => $products[$row->product_id]['name'].' · استلام #'.$row->id.' · '.$warehouses[$row->warehouse_id],
            ])->all();

        $options = array_merge($serials, $receipts);
        if (preg_match('/^(serial|receipt):([1-9][0-9]{0,17})$/D', $selected, $match) && ! collect($options)->contains('value', $selected)) {
            $serial = $match[1] === 'serial';
            $row = DB::table($serial ? 'inventory_serials' : 'inventory_movements')
                ->whereIn('warehouse_id', $warehouses->keys())
                ->when($serial, fn ($q) => $q->where('state', 'available'), fn ($q) => $q->where('quantity', '>', '0'))
                ->find((int) $match[2]);
            if ($row) {
                $product = app(ProductsApi::class)->inventoryProduct($actor, (int) $row->product_id);
                if ($product['active'] && $product['kind'] !== 'service' && $product['tracking'] === ($serial ? 'serial' : 'quantity')) {
                    $reference = $serial ? $row->entered : 'استلام #'.$row->id;
                    $options[] = ['value' => $selected, 'label' => $product['name'].' · '.$reference.' · '.$warehouses[$row->warehouse_id]];
                }
            }
        }

        return $options;
    }

    public function workshopReservationsReconciled(int $actor, int $work, int $branch, array $reservations): bool
    {
        app(WorkshopApi::class)->authorizeReconciliation($actor, $work, $branch);
        foreach ($reservations as $id) {
            $r = DB::table('inventory_reservations')->find((int) $id);
            abort_unless($r && $r->source_module === 'workshop' && $r->source_id === (string) $work, 422);
            abort_unless((int) DB::table('inventory_warehouses')->where('id', $r->warehouse_id)->value('branch_id') === $branch, 422);
            $released = DB::table('inventory_reservation_events')->where('reservation_id', $r->id)->where('action', 'released')->exists();
            if (bccomp(bcsub(bcsub($r->reserved, $r->fulfilled, 8), $r->released, 8), '0', 8) !== 0 || (! $released && bccomp($r->requested, $r->reserved, 8) !== 0)) {
                return false;
            }
        }

        return true;
    }

    public function workshopIssueEvidence(int $actor, string $issueKey): array
    {
        return app(WorkshopStock::class)->evidence($actor, $issueKey);
    }

    public function returnWorkshopIssue(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials = []): array
    {
        return app(WorkshopStock::class)->restore($actor, $movement, $quantity, $key, $reason, $serials);
    }

    public function maintenanceReservationsReconciled(int $actor, int $work, int $branch, array $reservations): bool
    {
        app(FieldMaintenanceApi::class)->authorizeReconciliation($actor, $work, $branch);
        foreach ($reservations as $id) {
            $r = DB::table('inventory_reservations')->find((int) $id);
            abort_unless($r && $r->source_module === 'maintenance' && $r->source_id === (string) $work, 422);
            abort_unless((int) DB::table('inventory_warehouses')->where('id', $r->warehouse_id)->value('branch_id') === $branch, 422);
            $released = DB::table('inventory_reservation_events')->where('reservation_id', $r->id)->where('action', 'released')->exists();
            if (bccomp(bcsub(bcsub($r->reserved, $r->fulfilled, 8), $r->released, 8), '0', 8) !== 0 || (! $released && bccomp($r->requested, $r->reserved, 8) !== 0)) {
                return false;
            }
        }

        return true;
    }

    public function maintenanceIssueEvidence(int $actor, string $issueKey): array
    {
        return app(MaintenanceStock::class)->evidence($actor, $issueKey);
    }

    public function returnMaintenanceIssue(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials = []): array
    {
        return app(MaintenanceStock::class)->restore($actor, $movement, $quantity, $key, $reason, $serials);
    }

    public function projectSummary(int $actor, int $project, int $branch, array $salesOrders = []): array
    {
        app(ProjectsApi::class)->authorizeFinancialView($actor, $project, $branch);
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.journal_view', $branch));
        } catch (ContractException) {
            abort(403, 'صلاحية عرض التكلفة المالية غير متاحة.');
        }
        $maintenance = app(FieldMaintenanceApi::class)->projectWorkOrders($actor, $project, $branch);
        $workshop = app(WorkshopApi::class)->projectJobs($actor, $project, $branch);
        $rows = DB::table('inventory_reservations')->where(function ($q) use ($project, $maintenance, $workshop) {
            $q->where(fn ($q) => $q->where('source_module', 'projects')->where('source_id', (string) $project))->orWhere(fn ($q) => $q->where('source_module', 'maintenance')->whereIn('source_id', array_map('strval', $maintenance['ids'])))->orWhere(fn ($q) => $q->where('source_module', 'workshop')->whereIn('source_id', array_map('strval', $workshop['ids'])));
        })->get();
        $outstanding = '0.00000000';
        $unreserved = '0.00000000';
        $issued = '0.00000000';
        foreach ($rows as $r) {
            app(InventoryAccess::class)->authorize($actor, 'reservation_view', $r->warehouse_id);
            abort_unless((int) DB::table('inventory_warehouses')->where('id', $r->warehouse_id)->value('branch_id') === $branch, 422);
            $outstanding = bcadd($outstanding, bcsub(bcsub($r->reserved, $r->fulfilled, 8), $r->released, 8), 8);
            $issued = bcadd($issued, $r->fulfilled, 8);
            if (! DB::table('inventory_reservation_events')->where('reservation_id', $r->id)->where('action', 'released')->exists()) {
                $unreserved = bcadd($unreserved, bcsub($r->requested, $r->reserved, 8), 8);
            }
        }
        $movements = DB::table('inventory_movements as m')->leftJoin('inventory_movement_journals as j', 'j.movement_id', '=', 'm.id')->where(function ($q) use ($project, $salesOrders, $maintenance, $workshop) {
            $q->where(fn ($q) => $q->where('m.source_module', 'Projects')->where('m.source_id', (string) $project))
                ->orWhere(fn ($q) => $q->where('m.source_module', 'Sales')->whereIn('m.source_id', array_map('strval', $salesOrders)))
                ->orWhere(fn ($q) => $q->where('m.operation', 'sales_return')->whereIn('m.reversal_id', DB::table('inventory_movements')->where('source_module', 'Sales')->whereIn('source_id', array_map('strval', $salesOrders))->select('id')))
                ->orWhere(fn ($q) => $q->where('m.source_module', 'WorkshopRepairs')->whereIn('m.source_id', array_map('strval', $workshop['ids'])))
                ->orWhere(fn ($q) => $q->where('m.operation', 'workshop_return')->whereIn('m.reversal_id', DB::table('inventory_movements')->where('source_module', 'WorkshopRepairs')->whereIn('source_id', array_map('strval', $workshop['ids']))->select('id')))
                ->orWhere(fn ($q) => $q->where('m.source_module', 'FieldMaintenance')->whereIn('m.source_id', array_map('strval', $maintenance['ids'])))
                ->orWhere(fn ($q) => $q->where('m.operation', 'maintenance_return')->whereIn('m.reversal_id', DB::table('inventory_movements')->where('source_module', 'FieldMaintenance')->whereIn('source_id', array_map('strval', $maintenance['ids']))->select('id')));
        })->get(['m.*', 'j.amount', 'j.journal_id']);
        $amount = '0.00';
        $salesAmount = '0.00';
        foreach ($movements as $m) {
            app(InventoryAccess::class)->authorize($actor, 'movement_view', $m->warehouse_id);
            abort_unless((int) DB::table('inventory_warehouses')->where('id', $m->warehouse_id)->value('branch_id') === $branch, 422);
            if ($m->source_module === 'Projects' || $m->source_module === 'FieldMaintenance' || $m->source_module === 'WorkshopRepairs' || in_array($m->operation, ['maintenance_return', 'workshop_return'])) {
                $amount = in_array($m->operation, ['maintenance_return', 'workshop_return']) ? bcsub($amount, $m->amount ?? '0', 2) : bcadd($amount, $m->amount ?? '0', 2);
            } else {
                $salesAmount = $m->operation === 'sales_return' ? bcsub($salesAmount, $m->amount ?? '0', 2) : bcadd($salesAmount, $m->amount ?? '0', 2);
            }
        }

        return ['outstanding_quantity' => $outstanding, 'unreserved_quantity' => $unreserved, 'issued_quantity' => $issued, 'fifo_cost' => $amount, 'sales_fifo_cost' => $salesAmount, 'movements' => $movements->map(fn ($r) => (array) $r)->all()];
    }

    public function postOpening(int $actor, int $batch, string $date, array $lines): void
    {
        app(FinancialStock::class)->postOpening($actor, $batch, $date, $lines);
    }

    public function receivePurchase(int $actor, int $receipt): array
    {
        return app(FinancialStock::class)->receivePurchase($actor, $receipt);
    }

    public function costedMovement(int $actor, int $movement): array
    {
        return app(FinancialStock::class)->costedMovement($actor, $movement);
    }

    public function getAvailability(int $actor, int $warehouse, int $product): array
    {
        return DB::transaction(function () use ($actor, $warehouse, $product) {
            InventoryActions::lock();
            app(InventoryAccess::class)->authorize($actor, 'balance_view', $warehouse);
            $p = app(ProductsApi::class)->inventoryProduct($actor, $product);
            abort_if($p['kind'] === 'service' || ! in_array($p['tracking'], ['quantity', 'serial'], true), 422);
            $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product)->first();
            $transitLines = DB::table('inventory_document_lines as l')->join('inventory_documents as d', 'd.id', '=', 'l.document_id')
                ->where('d.kind', 'transfer')->where('d.status', 'dispatched')->where('d.destination_id', $warehouse)
                ->where('l.product_id', $product)->get(['l.id', 'l.quantity']);
            $transit = '0';
            $missing = '0';
            foreach ($transitLines as $line) {
                $s = DB::table('inventory_transfer_receipt_lines')->where('line_id', $line->id)->selectRaw('coalesce(sum(accepted+damaged),0) as physical,coalesce(sum(missing-recovered_missing),0) as missing')->first();
                $transit = bcadd($transit, bcsub($line->quantity, $s->physical, 8), 8);
                $missing = bcadd($missing, $s->missing, 8);
            }

            return ['warehouse_id' => $warehouse, 'product_id' => $product, 'base_unit_id' => $p['base_unit_id'],
                'on_hand' => $balance?->on_hand ?? '0.00000000', 'reserved' => $balance?->reserved ?? '0.00000000',
                'unavailable' => $balance?->unavailable ?? '0.00000000', 'missing' => $missing,
                'available' => $balance?->available ?? '0.00000000', 'in_transit' => bcadd((string) $transit, '0', 8)];
        });
    }

    public function reconcileWarehouse(int $actor, int $warehouse): array
    {
        return app(InventoryReconciliation::class)->warehouse($actor, $warehouse);
    }

    public function isolateStock(int $actor, array $data): array
    {
        return app(InventoryConditions::class)->isolate($actor, $data);
    }

    public function releaseIsolation(int $actor, int $isolation, string $quantity, string $key, array $serials = []): array
    {
        return app(InventoryConditions::class)->release($actor, $isolation, $quantity, $key, $serials);
    }

    public function receiveTransfer(int $actor, int $document, array $data): array
    {
        return app(InventoryTransfers::class)->receive($actor, $document, $data);
    }

    public function transferStatus(int $actor, int $document): array
    {
        return app(InventoryTransfers::class)->status($actor, $document);
    }

    public function reverseMovement(int $actor, array $data): array
    {
        return app(InventoryCorrections::class)->reverse($actor, $data);
    }

    public function document(int $actor, int $id): array
    {
        $r = DB::table('inventory_documents')->where('id', $id)->first();
        abort_unless($r, 404);
        app(InventoryQuery::class)->documents($actor, $r->kind)->where('id', $id)->firstOrFail();

        return ['id' => $r->id, 'warehouse_id' => $r->warehouse_id, 'kind' => $r->kind, 'status' => $r->status];
    }

    public function warehouse(int $id): ?array
    {
        $row = DB::table('inventory_warehouses')->where('id', $id)->first(['id', 'branch_id', 'code', 'name', 'active']);

        return $row ? (array) $row : null;
    }

    public function warehouseDirectory(int $actor): array
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M01.security_admin'));

        return DB::table('inventory_warehouses')->orderBy('name')->get(['id', 'branch_id', 'name', 'active'])->map(fn ($r) => (array) $r)->all();
    }

    public function requestReservation(int $actor, array $data): array
    {
        return app(InventoryReservations::class)->request($actor, $data);
    }

    public function reservationWarehouses(int $actor, int $branch): array
    {
        return DB::table('inventory_warehouses')->whereIn('id', app(InventoryAccess::class)->ids($actor, 'reservation_manage'))
            ->where('branch_id', $branch)->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($r) => (array) $r)->all();
    }

    public function replenishReservation(int $actor, int $id, string $quantity, string $key): array
    {
        return app(InventoryReservations::class)->replenish($actor, $id, $quantity, $key);
    }

    public function reservationStatus(int $actor, int $id): array
    {
        return app(InventoryReservations::class)->status($actor, $id);
    }

    public function adjustReservation(int $actor, int $id, string $quantity, string $key): array
    {
        return app(InventoryReservations::class)->adjust($actor, $id, $quantity, $key);
    }

    public function releaseReservation(int $actor, int $id, string $key): array
    {
        return app(InventoryReservations::class)->release($actor, $id, $key);
    }

    public function fulfillReservation(int $actor, int $id, string $quantity, string $key): array
    {
        return app(InventoryReservations::class)->fulfill($actor, $id, $quantity, $key);
    }

    public function financialValue(int $actor, array $branchIds, ?string $to = null): string
    {
        return app(InventoryFinancialHistory::class)->value($actor, $branchIds, $to);
    }

    public function transitValue(int $actor, array $branchIds, ?string $to = null): string
    {
        return app(InventoryFinancialHistory::class)->transit($actor, $branchIds, $to);
    }
}

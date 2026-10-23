<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;

final class InventoryService implements InventoryApi
{
    public function getAvailability(int $actor, int $warehouse, int $product): array
    {
        return DB::transaction(function () use ($actor, $warehouse, $product) {
            InventoryActions::lock();
            app(InventoryAccess::class)->authorize($actor, 'balance_view', $warehouse);
            $p = app(ProductsApi::class)->inventoryProduct($actor, $product);
            abort_if($p['kind'] === 'service' || !in_array($p['tracking'],['quantity','serial'],true), 422);
            $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product)->first();
            $transitLines = DB::table('inventory_document_lines as l')->join('inventory_documents as d', 'd.id', '=', 'l.document_id')
                ->where('d.kind', 'transfer')->where('d.status', 'dispatched')->where('d.destination_id', $warehouse)
                ->where('l.product_id', $product)->get(['l.id','l.quantity']);
            $transit='0'; $missing='0';
            foreach ($transitLines as $line) {
                $s=DB::table('inventory_transfer_receipt_lines')->where('line_id',$line->id)->selectRaw('coalesce(sum(accepted+damaged),0) as physical,coalesce(sum(missing-recovered_missing),0) as missing')->first();
                $transit=bcadd($transit,bcsub($line->quantity,$s->physical,8),8);
                $missing=bcadd($missing,$s->missing,8);
            }

            return ['warehouse_id' => $warehouse, 'product_id' => $product, 'base_unit_id' => $p['base_unit_id'],
                'on_hand' => $balance?->on_hand ?? '0.00000000', 'reserved' => $balance?->reserved ?? '0.00000000',
                'unavailable'=>$balance?->unavailable??'0.00000000','missing'=>$missing,
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
}

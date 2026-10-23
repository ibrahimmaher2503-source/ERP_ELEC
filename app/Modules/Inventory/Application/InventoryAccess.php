<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InventoryAccess
{
    public function authorize(int $actor, string $action, ?int $warehouse = null): void
    {
        try {
            $branch = $warehouse === null ? null : DB::table('inventory_warehouses')->where('id', $warehouse)->value('branch_id');
            abort_if($warehouse !== null && ! $branch, 404);
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M06.'.$action, $branch, $warehouse));
        } catch (ContractException $e) {
            abort($e->getCode(), 'الإجراء أو نطاق المستودع غير مسموح.');
        }
    }

    public function allows(int $actor, string $action, ?int $warehouse = null): bool
    {
        try {
            $this->authorize($actor, $action, $warehouse);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function ids(int $actor, string $action): array
    {
        try {
            return array_values(array_column(array_filter(app(OrganizationApi::class)->inventoryScopes($actor), fn ($s) => in_array('M06.'.$action, $s['permissions'], true)), 'warehouse_id'));
        } catch (ContractException) {
            return [];
        }
    }

    public function navigation(int $actor): array
    {
        $links = [];
        try {
            $scopes = app(OrganizationApi::class)->inventoryScopes($actor);
        } catch (ContractException) {
            return [];
        }
        $effective = array_unique(array_merge(...array_column($scopes, 'permissions')));
        $has = fn ($action) => in_array('M06.'.$action, $effective, true);
        foreach (['dashboard' => 'dashboard_view', 'warehouses' => 'warehouse_view', 'balances' => 'balance_view', 'movements' => 'movement_view', 'reservations' => 'reservation_view'] as $route => $action) {
            if ($route === 'warehouses' ? $this->allows($actor, $action) : $has($action)) {
                $links[$route] = true;
            }
        }
        foreach (['transfers' => 'transfer', 'counts' => 'count', 'adjustments' => 'adjustment', 'receipts' => 'opening', 'issues' => 'issue'] as $route => $kind) {
            foreach (['create', 'approve', ...($kind === 'transfer' ? ['dispatch', 'receive'] : ($kind === 'count' ? ['submit'] : ['post']))] as $action) {
                if ($has(app(InventoryDocuments::class)->permission($kind, $action))) {
                    $links[$route] = true;
                }
            }
        }

        $links['serials']=$has('serial_view');
        $links['isolations']=$has('isolation_manage') || $has('isolation_release');
        $links['corrections']=$has('correction_execute');
        return $links;
    }
}

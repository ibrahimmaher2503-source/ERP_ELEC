<?php

namespace App\Modules\Inventory\Application;

use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Support\Facades\DB;

final class ServicePartSerials
{
    public function selections(int $actor, string $owner, int $work, int $requirement, string $quantity, array $serials, string $purpose, ?int $movement): array
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($owner, ['FieldMaintenance', 'WorkshopRepairs'], true) && in_array($purpose, ['usage', 'return'], true), 422);
        InventoryActions::lock();
        $api = $owner === 'FieldMaintenance' ? app(FieldMaintenanceApi::class) : app(WorkshopApi::class);
        $demand = $api->inventoryDemand($actor, (string) $work, (string) $requirement, $purpose === 'usage' ? 'material_usage' : 'material_return');
        abort_unless($demand['eligible'], 409);
        if ($demand['tracking'] !== 'serial') {
            abort_unless($serials === [], 422, 'لا تقبل القطعة الكمية أرقامًا مسلسلة.');

            return [];
        }
        $names = array_keys(app(InventorySerials::class)->selections($serials, $quantity));
        $rows = DB::table('inventory_movements as m')->join('inventory_serial_events as e', 'e.movement_id', '=', 'm.id')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')
            ->where('m.source_module', $owner)->where('m.source_id', (string) $work)->where('m.source_line', (string) $requirement)
            ->where('m.operation', $owner === 'FieldMaintenance' ? 'maintenance_issue' : 'workshop_issue')->where('m.product_id', $demand['product_id'])
            ->whereIn('s.normalized', $names)->where('s.state', 'issued')->whereColumn('s.last_movement_id', 'm.id')
            ->when($movement !== null, fn ($q) => $q->where('m.id', $movement))->orderByRaw('s.normalized COLLATE "C"')
            ->get(['s.id', 's.normalized as serial', 'm.id as movement_id']);
        abort_unless($rows->count() === count($names), 422, 'الأرقام يجب أن تكون مصروفة فعليًا من المصدر نفسه ولم تعد للمخزون.');

        return $rows->map(fn ($row) => ['id' => (int) $row->id, 'serial' => $row->serial, 'movement_id' => (int) $row->movement_id])->all();
    }
}

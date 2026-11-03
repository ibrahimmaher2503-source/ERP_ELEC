<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Inventory\Domain\InventoryChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintenanceStock
{
    public function evidence(int $actor, string $key): array
    {
        $m = DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$key)->where('operation', 'maintenance_issue')->first();
        abort_unless($m, 404);
        app(InventoryAccess::class)->authorize($actor, 'movement_view', $m->warehouse_id);
        $v = DB::table('inventory_movement_journals')->where('movement_id', $m->id)->first();
        abort_unless($v, 409);

        return ['movement_id' => $m->id, 'journal_id' => $v->journal_id, 'amount' => $v->amount, 'serials' => DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')->where('e.movement_id', $m->id)->orderByRaw('s.normalized COLLATE "C"')->pluck('s.normalized')->all()];
    }

    public function restore(int $actor, int $movement, string $quantity, string $key, string $reason, array $serials = []): array
    {
        Validator::make(compact('key', 'reason'), ['key' => 'required|uuid', 'reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $movement, $quantity, $key, $reason, $serials) {
            InventoryActions::lock();
            $m = DB::table('inventory_movements')->lockForUpdate()->find($movement);
            abort_unless($m && $m->operation === 'maintenance_issue' && $m->source_module === 'FieldMaintenance', 422);
            app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
            $d = app(FieldMaintenanceApi::class)->inventoryDemand($actor, $m->source_id, $m->source_line, 'material_return');
            abort_unless($d['eligible'], 409);
            $quantity = app(InventoryLedger::class)->quantity($quantity);
            $serials = $serials === [] ? [] : array_keys(app(InventorySerials::class)->selections($serials, $quantity));
            app(FieldMaintenanceApi::class)->validateUnusedPartReturn($actor, (int) $m->source_id, (int) $m->source_line, $movement, $quantity, $key, $serials);
            $fingerprint = [$movement, $quantity, $reason];
            if ($serials !== []) {
                $fingerprint[] = $serials;
            }
            $hash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
            $old = DB::table('inventory_corrections')->where('idempotency_key', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_hash, $hash) && $old->status === 'posted', 409);

                return $this->returnEvidence($old->correcting_movement_id);
            }
            $returned = (string) DB::table('inventory_corrections')->where('movement_id', $movement)->where('status', 'posted')->sum('quantity');
            abort_unless(bccomp(bcadd($returned, $quantity, 8), bcsub('0', $m->quantity, 8), 8) <= 0, 422);
            abort_unless(DB::table('inventory_warehouses')->where('id', $m->warehouse_id)->value('active'), 422);
            $id = app(ValuedCorrections::class)->post($actor, $m, $quantity, 'maintenance-return:'.$key, $serials, 'maintenance_return');
            DB::table('inventory_corrections')->insert(['movement_id' => $movement, 'correcting_movement_id' => $id, 'quantity' => $quantity, 'reason' => $reason, 'idempotency_key' => $key, 'serials' => json_encode($serials, JSON_THROW_ON_ERROR), 'payload_hash' => $hash, 'status' => 'posted', 'actor_id' => $actor, 'occurred_at' => now()]);
            event(new InventoryChanged($actor, 'inventory_movement', $id, 'maintenance.returned', null, ['original' => $movement, 'quantity' => $quantity, 'reason' => $reason]));

            return $this->returnEvidence($id);
        }, 5);
    }

    private function returnEvidence(int $id): array
    {
        $j = DB::table('inventory_movement_journals')->where('movement_id', $id)->firstOrFail();

        return ['movement_id' => $id, 'journal_id' => $j->journal_id, 'amount' => $j->amount, 'serials' => DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')->where('e.movement_id', $id)->orderByRaw('s.normalized COLLATE "C"')->pluck('s.normalized')->all()];
    }

    public function financialSource(int $actor, object $m): array
    {
        if ($m->operation === 'maintenance_issue') {
            app(InventoryAccess::class)->authorize($actor, 'issue_post', $m->warehouse_id);
            $source = app(FieldMaintenanceApi::class)->authorizeIssue($actor, $m->source_id, $m->source_line, substr($m->idempotency_key, 12), $m->warehouse_id, bcsub('0', $m->quantity, 8));
            $a = DB::table('inventory_fifo_allocations')->where('movement_id', $m->id)->selectRaw('coalesce(sum(quantity),0) quantity,coalesce(sum(amount),0) amount')->first();
            abort_unless(bccomp($a->quantity, bcsub('0', $m->quantity, 8), 8) === 0, 422);

            return $source + ['movement_id' => $m->id, 'date' => now('Africa/Cairo')->toDateString(), 'amount' => $a->amount, 'debit_purpose' => 'inventory_issue', 'credit_purpose' => 'inventory'];
        }
        app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
        $o = DB::table('inventory_movements')->find($m->reversal_id);
        abort_unless($o && $o->operation === 'maintenance_issue' && $o->source_module === 'FieldMaintenance', 422);
        $d = app(FieldMaintenanceApi::class)->inventoryDemand($actor, $o->source_id, $o->source_line, 'material_return');
        abort_unless($d['eligible'], 409);
        $originalJournal = DB::table('inventory_movement_journals')->where('movement_id', $o->id)->value('journal_id');
        $project = $originalJournal ? app(AccountingApi::class)->inventorySourceProject($actor, $o->id, $d['branch_id']) : null;
        $f = DB::table('inventory_fifo_layers')->where('movement_id', $m->id)->firstOrFail();

        return ['movement_id' => $m->id, 'branch_id' => $d['branch_id'], 'created_by' => $o->actor_id, 'approved_by' => $actor, 'maintenance_material' => true, 'project_id' => $project, 'date' => now('Africa/Cairo')->toDateString(), 'amount' => $f->original_value, 'debit_purpose' => 'inventory', 'credit_purpose' => 'inventory_issue'];
    }
}

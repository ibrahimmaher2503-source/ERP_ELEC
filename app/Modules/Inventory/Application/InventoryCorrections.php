<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\InventoryChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class InventoryCorrections
{
    public function reverse(int $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data) {
            InventoryActions::lock();
            $d = Validator::make($data, ['movement_id' => 'required|integer|min:1', 'quantity' => 'required|string', 'reason' => 'required|string|max:2000|not_regex:/\x00/', 'idempotency_key' => 'required|uuid', 'serials' => 'sometimes|array'])->validate();
            $m = DB::table('inventory_movements')->where('id', $d['movement_id'])->lockForUpdate()->first();
            abort_unless($m, 404);
            app(InventoryAccess::class)->authorize($actor, 'correction_execute', $m->warehouse_id);
            $quantity = app(InventoryLedger::class)->quantity($d['quantity']);
            $serials = $d['serials'] ?? [];
            sort($serials);
            $hash = hash('sha256', json_encode([$m->id, $quantity, $d['reason'], $serials], JSON_THROW_ON_ERROR));
            $old = DB::table('inventory_corrections')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($old) {
                if ($old->payload_hash !== $hash) {
                    InventoryActions::fail('Correction key collision.');
                }

                return (array) $old;
            }
            if ($m->reversal_id !== null) {
                InventoryActions::fail('Correct the original source; reversal chains are not permitted.');
            }
            $reversed = (string) DB::table('inventory_corrections')->where('movement_id', $m->id)->where('status', 'posted')->sum('quantity');
            $absolute = bccomp($m->quantity, '0', 8) > 0 ? $m->quantity : bcsub('0', $m->quantity, 8);
            if (bccomp(bcadd($reversed, $quantity, 8), $absolute, 8) > 0) {
                InventoryActions::fail('Correction exceeds remaining reversible quantity.');
            }
            $doc = $m->document_id ? DB::table('inventory_documents')->find($m->document_id) : null;
            $internal = $m->source_module === 'Inventory' && $doc && in_array($doc->kind, ['issue', 'adjustment'], true) && $doc->status === 'posted' && in_array($m->operation, ['issue_post', 'adjustment_post'], true);
            $movementId = null;
            if ($internal) {
                if (! DB::table('inventory_warehouses')->where('id', $m->warehouse_id)->value('active')) {
                    InventoryActions::fail('Warehouse inactive.');
                }
                if (DB::table('inventory_movements')->where('warehouse_id', $m->warehouse_id)->where('product_id', $m->product_id)->where('id', '>', $m->id)->whereNull('reversal_id')->exists()) {
                    InventoryActions::fail('Later stock movements depend on this source. Review the later source before correction.');
                }
                $key = 'correction:'.$d['idempotency_key'];
                $movementId = app(ValuedCorrections::class)->post($actor, $m, $quantity, $key, $serials);
            }
            $id = DB::table('inventory_corrections')->insertGetId(['movement_id' => $m->id, 'correcting_movement_id' => $movementId, 'quantity' => $quantity, 'reason' => $d['reason'], 'idempotency_key' => $d['idempotency_key'], 'serials' => json_encode($serials, JSON_THROW_ON_ERROR), 'payload_hash' => $hash, 'status' => $internal ? 'posted' : 'pending_financial', 'actor_id' => $actor, 'occurred_at' => now()]);
            $out = (array) DB::table('inventory_corrections')->find($id);
            event(new InventoryChanged($actor, 'inventory_correction', $id, $internal ? 'posted' : 'pending_financial', null, $out));

            return $out;
        });
    }
}

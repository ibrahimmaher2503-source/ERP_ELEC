<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\InventoryChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

final class InventoryTransfers
{
    /** Already authorized by a document reader or receiver. No balance writes. */
    public function quantities(int $id): array
    {
        $out = [];
        foreach (DB::table('inventory_document_lines')->where('document_id', $id)->orderBy('id')->get() as $line) {
            $s = DB::table('inventory_transfer_receipt_lines')->where('line_id', $line->id)->selectRaw('coalesce(sum(accepted),0) as accepted, coalesce(sum(damaged),0) as damaged, coalesce(sum(missing-recovered_missing),0) as missing')->first();
            $remaining = bcsub(bcsub($line->quantity, $s->accepted, 8), $s->damaged, 8);
            $out[$line->id] = ['line_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => $line->quantity, 'accepted' => bcadd($s->accepted, '0', 8), 'damaged' => bcadd($s->damaged, '0', 8), 'missing' => bcadd($s->missing, '0', 8), 'outstanding' => bcsub($remaining, $s->missing, 8), 'in_transit' => $remaining];
        }

        return $out;
    }

    public function status(int $actor, int $id): array
    {
        $doc = app(InventoryQuery::class)->documents($actor, 'transfer')->where('id', $id)->firstOrFail();
        $receipts = DB::table('inventory_transfer_receipts')->where('document_id', $id)->orderBy('id')->get();

        return ['document' => (array) $doc, 'lines' => array_values($this->quantities($id)),
            'dispatch_movements' => DB::table('inventory_movements')->where('document_id', $id)->where('operation', 'transfer_dispatch')->pluck('id')->all(),
            'receipts' => $receipts->map(fn ($r) => (array) $r)->all(),
            'reconciliation_status' => $doc->status === 'received' ? 'physically_reconciled' : 'outstanding',
            'financial_status' => $receipts->contains('financial_status', 'pending_financial') ? 'pending_financial' : 'not_required'];
    }

    public function receiveFull(int $actor, int $id): array
    {
        $doc = DB::table('inventory_documents')->find($id);
        abort_unless($doc && $doc->kind === 'transfer', 404);
        app(InventoryAccess::class)->authorize($actor, 'transfer_receive', $doc->destination_id);
        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'inventory-full-transfer-receive:'.$id)->toString();
        if (DB::table('inventory_transfer_receipts')->where('idempotency_key', $key)->exists() || $doc->status === 'received') {
            return (array) $doc;
        }
        $lines = [];
        foreach ($this->quantities($id) as $s) {
            if (bccomp($s['in_transit'], '0', 8) === 0) {
                continue;
            }
            $serials = DB::table('inventory_serials')->where('transfer_id', $id)->where('product_id', $s['product_id'])->where('state', 'transit')->orderByRaw('normalized COLLATE "C"')->pluck('entered')->all();
            $lines[] = ['line_id' => $s['line_id'], 'accepted' => $s['in_transit'], 'damaged' => '0', 'missing' => '0', 'recovered_missing' => $s['missing'], 'accepted_serials' => $serials, 'damaged_serials' => []];
        }

        return $this->receive($actor, $id, ['idempotency_key' => $key, 'reason' => 'Full outstanding physical receipt', 'lines' => $lines]);
    }

    public function receive(int $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            InventoryActions::lock();
            $doc = DB::table('inventory_documents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($doc && $doc->kind === 'transfer', 404);
            app(InventoryAccess::class)->authorize($actor, 'transfer_receive', $doc->destination_id);
            app(InventoryAccess::class)->authorize($actor, 'transfer_receive', $doc->warehouse_id);
            $d = Validator::make($data, ['idempotency_key' => 'required|uuid', 'reason' => 'nullable|string|max:2000|not_regex:/\x00/', 'evidence' => 'nullable|string|max:3000|not_regex:/\x00/', 'lines' => 'required|array|min:1|max:100', 'lines.*.line_id' => 'required|integer|distinct', 'lines.*.accepted' => 'required|string', 'lines.*.damaged' => 'required|string', 'lines.*.missing' => 'required|string', 'lines.*.recovered_missing' => 'sometimes|string', 'lines.*.accepted_serials' => 'sometimes|array', 'lines.*.damaged_serials' => 'sometimes|array'])->validate();
            $lines = [];
            foreach ($d['lines'] as $input) {
                $entry = ['line_id' => (int) $input['line_id']];
                foreach (['accepted', 'damaged', 'missing', 'recovered_missing'] as $field) {
                    $entry[$field] = app(InventoryLedger::class)->quantity($input[$field] ?? '0', false, true);
                }
                foreach (['accepted_serials', 'damaged_serials'] as $field) {
                    $entry[$field] = $input[$field] ?? [];
                    sort($entry[$field]);
                }
                $lines[] = $entry;
            }
            usort($lines, fn ($a, $b) => $a['line_id'] <=> $b['line_id']);
            $reason = ($d['reason'] ?? null) ?: null;
            $evidence = ($d['evidence'] ?? null) ?: null;
            $hash = hash('sha256', json_encode([$id, $reason, $evidence, $lines], JSON_THROW_ON_ERROR));
            $existing = DB::table('inventory_transfer_receipts')->where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing) {
                if ($existing->document_id !== $id || $existing->payload_hash !== $hash) {
                    InventoryActions::fail('تعارض في مفتاح الاستلام.');
                }

                return (array) $doc;
            }
            if ($doc->status !== 'dispatched') {
                InventoryActions::fail('لا يُستلم إلا التحويلات المرسلة والمعلقة.');
            }
            if (! DB::table('inventory_warehouses')->where('id', $doc->destination_id)->value('active')) {
                InventoryActions::fail('المستودع المستقبل غير نشط.');
            }
            $totals = $this->quantities($id);
            $receipt = DB::table('inventory_transfer_receipts')->insertGetId(['document_id' => $id, 'idempotency_key' => $d['idempotency_key'], 'reason' => $reason, 'evidence' => $evidence, 'payload_hash' => $hash, 'financial_status' => collect($lines)->contains(fn ($l) => bccomp($l['damaged'], '0', 8) > 0 || bccomp($l['missing'], '0', 8) > 0) ? 'pending_financial' : 'not_required', 'actor_id' => $actor, 'occurred_at' => now()]);
            foreach ($lines as $line) {
                $source = DB::table('inventory_document_lines')->where('document_id', $id)->find($line['line_id']);
                if (! $source) {
                    InventoryActions::fail('البند لا يتبع التحويل.');
                }
                $total = $totals[$source->id];
                $physical = bcadd($line['accepted'], $line['damaged'], 8);
                $used = bcsub(bcadd($physical, $line['missing'], 8), $line['recovered_missing'], 8);
                if (bccomp(bcadd($physical, $line['missing'], 8), '0', 8) <= 0 || bccomp($line['recovered_missing'], $physical, 8) > 0 || bccomp($line['recovered_missing'], $total['missing'], 8) > 0 || bccomp($used, $total['outstanding'], 8) > 0) {
                    InventoryActions::fail('الكميات المستلمة والمفقودة تتجاوز التزام الإرسال غير المسوّى.');
                }
                if ((bccomp($line['damaged'], '0', 8) > 0 || bccomp($line['missing'], '0', 8) > 0 || bccomp($line['recovered_missing'], '0', 8) > 0) && trim($reason ?? '') === '') {
                    InventoryActions::fail('سبب الفرق أو الاسترداد مطلوب.');
                }
                foreach (['accepted', 'damaged'] as $condition) {
                    if (bccomp($line[$condition], '0', 8) === 0) {
                        if ($line[$condition.'_serials'] !== []) {
                            InventoryActions::fail('الأرقام التسلسلية تتطلب كمية فعلية.');
                        }

                        continue;
                    }
                    $key = 'transfer-receipt:'.$receipt.':'.$source->id.':'.$condition;
                    $movements = app(ValuedTransfers::class)->receive($actor, $doc, $source, $line[$condition], $key, $line[$condition.'_serials']);
                    if ($condition === 'damaged') {
                        foreach ($movements as $movementId) {
                            $movement = DB::table('inventory_movements')->find($movementId);
                            $serials = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')->where('e.movement_id', $movement->id)->orderByRaw('s.normalized COLLATE "C"')->pluck('s.entered')->all();
                            app(InventoryConditions::class)->recordTransferDamage($actor, ['warehouse_id' => $doc->destination_id, 'product_id' => $source->product_id, 'source_movement_id' => $movement->id, 'quantity' => $movement->quantity, 'reason' => $reason, 'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, $movement->idempotency_key.':damage')->toString(), 'serials' => $serials]);
                        }
                    }
                }
                foreach (['accepted_serials', 'damaged_serials'] as $field) {
                    $line[$field] = json_encode($line[$field], JSON_THROW_ON_ERROR);
                }
                DB::table('inventory_transfer_receipt_lines')->insert($line + ['receipt_id' => $receipt]);
            }
            $complete = collect($this->quantities($id))->every(fn ($s) => bccomp($s['in_transit'], '0', 8) === 0);
            $update = ['status' => $complete ? 'received' : 'dispatched', 'version' => $doc->version + 1, 'updated_at' => now()];
            if ($complete) {
                $update['posted_at'] = now();
            }
            DB::table('inventory_documents')->where('id', $id)->update($update);
            event(new InventoryChanged($actor, 'inventory_document', $id, 'partial_receive', (array) $doc, $update + ['receipt_id' => $receipt, 'quantities' => $this->quantities($id), 'financial_reconciliation' => 'pending_if_discrepancy']));

            return (array) DB::table('inventory_documents')->find($id);
        });
    }
}

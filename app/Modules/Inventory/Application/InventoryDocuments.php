<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Inventory\Domain\InventoryChanged;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class InventoryDocuments
{
    public const KINDS = ['opening' => 'أرصدة افتتاحية', 'receipt' => 'استلام مخزون', 'issue' => 'صرف مخزون', 'transfer' => 'تحويل مستودعات', 'adjustment' => 'تسوية مخزون', 'count' => 'جرد المخزون'];

    public function permission(string $kind, string $action): string
    {
        abort_unless(isset(self::KINDS[$kind]), 404);

        return ($kind === 'opening' ? 'receipt' : $kind).'_'.$action;
    }

    private function authorize(int $actor, string $kind, string $action, int $warehouse, ?int $destination): void
    {
        app(InventoryAccess::class)->authorize($actor, $this->permission($kind, $action), $warehouse);
        if ($destination) {
            app(InventoryAccess::class)->authorize($actor, $this->permission($kind, $action), $destination);
        }
    }

    public function cutoff(int $warehouse): int
    {
        return (int) DB::table('inventory_balances')->where('warehouse_id', $warehouse)->sum('version');
    }

    public function save(int $actor, string $kind, array $data, ?int $id = null, ?int $version = null): array
    {
        return DB::transaction(function () use ($actor, $kind, $data, $id, $version) {
            InventoryActions::lock();
            $d = Validator::make($data, [
                'creation_key' => ['required', 'uuid'], 'warehouse_id' => ['required', 'integer', 'min:1'],
                'destination_id' => [Rule::requiredIf($kind === 'transfer'), 'nullable', 'integer', 'different:warehouse_id'],
                'reason' => ['required', 'string', 'max:2000', 'not_regex:/\x00/'], 'lines' => ['required', 'array', 'min:1', 'max:100'],
                'lines.*.product_id' => ['required', 'integer', 'distinct', 'min:1'], 'lines.*.quantity' => ['required', 'string'], 'lines.*.serials' => ['sometimes'],
                'lines.*.unit_cost' => ['nullable', 'string'], 'lines.*.cost_reference' => ['nullable', 'string', 'max:500', 'not_regex:/\x00/'],
            ])->validate();
            $warehouse = (int) $d['warehouse_id'];
            $destination = empty($d['destination_id']) ? null : (int) $d['destination_id'];
            if ($kind !== 'transfer' && $destination !== null) {
                InventoryActions::fail('المستودع المستقبل مخصص للتحويلات.');
            }
            $this->authorize($actor, $kind, 'create', $warehouse, $destination);
            foreach (array_filter([$warehouse, $destination]) as $wid) {
                $w = DB::table('inventory_warehouses')->where('id', $wid)->lockForUpdate()->first();
                if (! $w || ! $w->active) {
                    InventoryActions::fail('يلزم مستودع نشط.');
                }
            }
            $existing = DB::table('inventory_documents')->where('creation_key', $d['creation_key'])->first();
            if (! $id && $existing) {
                abort_unless($existing->created_by === $actor && $existing->kind === $kind && $existing->warehouse_id === $warehouse, 409);
                $stored = DB::table('inventory_document_lines')->where('document_id', $existing->id)->orderBy('product_id')->get()->map(fn ($l) => [(int) $l->product_id, bcadd($l->quantity, '0', 8), json_decode($l->serials, true)])->all();
                $incoming = array_map(fn ($l) => [(int) $l['product_id'], app(InventoryLedger::class)->quantity($l['quantity'], $kind === 'adjustment', $kind === 'count'), $this->serialValues($l['serials'] ?? [])], $d['lines']);
                usort($incoming, fn ($a, $b) => $a[0] <=> $b[0]);
                if ($stored !== $incoming || $existing->reason !== $d['reason'] || $existing->destination_id !== $destination) {
                    InventoryActions::fail('مرجع إنشاء المستند مستخدم لمحتوى مختلف.');
                }
                foreach ($d['lines'] as $input) {
                    $saved = DB::table('inventory_document_lines')->where('document_id', $existing->id)->where('product_id', $input['product_id'])->first();
                    $cost = ($input['unit_cost'] ?? '') === '' ? null : bcadd(FinancialDecimal::value($input['unit_cost']), '0', 16);
                    if ($saved->unit_cost !== $cost || ($saved->cost_reference ?? '') !== trim($input['cost_reference'] ?? '')) {
                        InventoryActions::fail('مرجع الإنشاء مستخدم بتقييم مختلف.');
                    }
                }

                return (array) $existing;
            }
            $before = $id ? DB::table('inventory_documents')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($before, 404);
                if ($before->version !== $version || $before->status !== 'draft') {
                    InventoryActions::fail('المستند تغير أو لم يعد مسودة.');
                }
                if ($before->created_by !== $actor) {
                    InventoryActions::fail('تعديل المسودة متاح لمنشئها فقط.');
                }
                if ($before->kind !== $kind || $before->warehouse_id !== $warehouse || $before->creation_key !== $d['creation_key']) {
                    InventoryActions::fail('نوع المستند ومستودعه وهويته ثابتة.');
                }
            }
            $lines = [];
            if ($kind === 'count' && $before && $this->cutoff($warehouse) !== $before->cutoff) {
                InventoryActions::fail('تحرك المخزون منذ بدء الجرد؛ ألغ الجلسة وابدأ جردًا جديدًا.');
            }
            $originalLines = $before && $kind === 'count'
                ? DB::table('inventory_document_lines')->where('document_id', $id)->get()->keyBy('product_id')
                : collect();
            foreach ($d['lines'] as $line) {
                $p = app(ProductsApi::class)->inventoryProduct($actor, (int) $line['product_id']);
                if (! $p['active'] || ! in_array($p['tracking'], ['quantity', 'serial'], true) || $p['kind'] === 'service') {
                    InventoryActions::fail('اختر صنفًا نشطًا بتتبع كمي.');
                }
                $quantity = app(InventoryLedger::class)->quantity($line['quantity'], $kind === 'adjustment', $kind === 'count');
                $unitCost = ($line['unit_cost'] ?? '') === '' ? null : FinancialDecimal::value($line['unit_cost']);
                $costReference = trim($line['cost_reference'] ?? '') ?: null;
                if (($unitCost !== null || $costReference !== null) && ($kind !== 'adjustment' || bccomp($quantity, '0', 8) <= 0)) {
                    InventoryActions::fail('التكلفة المدخلة مخصصة لفرق التسوية الموجب فقط.');
                }
                $serials = $line['serials'] ?? [];
                if ($p['tracking'] === 'serial') {
                    $serials = array_values(app(InventorySerials::class)->selections($serials, bccomp($quantity, '0', 8) < 0 ? bcsub('0', $quantity, 8) : $quantity));
                } elseif ($serials !== [] && $serials !== '') {
                    InventoryActions::fail('Serials require a serialized product.');
                } else {
                    $serials = [];
                }
                $recorded = $kind === 'count' ? ($originalLines->get($p['id'])?->recorded ?? DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $p['id'])->value('on_hand') ?? '0') : null;
                $lines[] = ['product_id' => $p['id'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'base_unit_id' => $p['base_unit_id'], 'unit_name' => $p['unit_name'], 'quantity' => $quantity, 'recorded' => $recorded, 'unit_cost' => $unitCost, 'cost_reference' => $costReference, 'serials' => json_encode($serials, JSON_THROW_ON_ERROR)];
            }
            $row = ['creation_key' => $d['creation_key'], 'kind' => $kind, 'warehouse_id' => $warehouse, 'destination_id' => $destination, 'reason' => $d['reason'], 'version' => ($before->version ?? 0) + 1, 'updated_at' => now()];
            if ($kind === 'count') {
                $row['cutoff'] = $before?->cutoff ?? $this->cutoff($warehouse);
            }
            if ($id) {
                DB::table('inventory_document_lines')->where('document_id', $id)->delete();
                DB::table('inventory_documents')->where('id', $id)->update($row);
            } else {
                $id = DB::table('inventory_documents')->insertGetId($row + ['status' => 'draft', 'created_by' => $actor, 'created_at' => now()]);
            }
            foreach ($lines as $line) {
                DB::table('inventory_document_lines')->insert($line + ['document_id' => $id]);
            }
            event(new InventoryChanged($actor, 'inventory_document', $id, 'saved', $before ? (array) $before : null, $row + ['lines' => $lines]));

            return (array) DB::table('inventory_documents')->where('id', $id)->first();
        });
    }

    public function transition(int $actor, int $id, string $action, int $version): array
    {
        return DB::transaction(function () use ($actor, $id, $action, $version) {
            InventoryActions::lock();
            $doc = DB::table('inventory_documents')->where('id', $id)->lockForUpdate()->first();
            abort_unless($doc, 404);
            abort_unless(in_array($action, ['submit', 'approve', 'post', 'dispatch', 'receive', 'cancel'], true), 422);
            if ($action === 'receive' && $doc->kind === 'transfer') {
                return app(InventoryTransfers::class)->receiveFull($actor, $id);
            }
            $cap = match ($action) {
                'submit' => $doc->kind === 'count' ? 'submit' : 'create','cancel' => 'create',default => $action
            };
            $this->authorize($actor, $doc->kind, $cap, $doc->warehouse_id, $doc->destination_id);
            $finished = match ($action) {
                'post' => 'posted','dispatch' => 'dispatched','receive' => 'received',default => null
            };
            if ($finished && ($doc->status === $finished || ($action === 'dispatch' && $doc->kind === 'transfer' && $doc->status === 'received'))) {
                return (array) $doc;
            }
            if ($doc->version !== $version) {
                InventoryActions::fail('تغير المستند؛ أعد تحميل الصفحة.');
            }
            $expected = match ($action) {
                'submit' => 'draft','approve' => 'submitted','post','dispatch' => 'approved','receive' => 'dispatched','cancel' => null
            };
            if ($expected !== null && $doc->status !== $expected) {
                InventoryActions::fail('الانتقال غير مسموح من حالة المستند الحالية.');
            }
            if ($action === 'cancel' && ! in_array($doc->status, ['draft', 'submitted', 'approved'], true)) {
                InventoryActions::fail('لا يمكن إلغاء مخزون مرحل أو قيد النقل.');
            }
            if ($action === 'approve' && $doc->created_by === $actor) {
                InventoryActions::fail('لا يمكن لمنشئ المستند اعتماد مستنده.');
            }
            if ($doc->kind === 'receipt' && $action !== 'cancel') {
                InventoryActions::fail('الاستلام الخارجي يتطلب عقد أهلية مستند المشتريات أو المرتجع؛ استخدم الأرصدة الافتتاحية المعتمدة للتهيئة.');
            }
            if ($doc->kind === 'transfer' && $action === 'post') {
                InventoryActions::fail('التحويل يمر بالإرسال ثم الاستلام.');
            }
            if ($doc->kind !== 'transfer' && in_array($action, ['dispatch', 'receive'], true)) {
                InventoryActions::fail('هذا الإجراء مخصص للتحويلات.');
            }
            $lines = DB::table('inventory_document_lines')->where('document_id', $id)->orderBy('product_id')->get();
            if ($lines->isEmpty() && $action !== 'cancel') {
                InventoryActions::fail('المستند بلا بنود.');
            }
            if ($doc->kind === 'adjustment' && in_array($action, ['submit', 'approve', 'post'], true)
                && $lines->contains(fn ($line) => bccomp($line->quantity, '0', 8) > 0 && ($line->unit_cost === null || trim($line->cost_reference ?? '') === ''))) {
                InventoryActions::fail('أكمل تكلفة الوحدة ومرجع تقييم كل فرق موجب قبل إرسال التسوية للاعتماد.');
            }
            foreach (array_filter([$doc->warehouse_id, $doc->destination_id]) as $wid) {
                $w = DB::table('inventory_warehouses')->where('id', $wid)->lockForUpdate()->first();
                if (! $w->active && $action !== 'cancel') {
                    InventoryActions::fail('المستودع غير نشط.');
                }
            }
            $next = match ($action) {
                'submit' => 'submitted','approve' => 'approved','cancel' => 'cancelled',default => $finished
            };
            $update = ['status' => $next, 'version' => $doc->version + 1, 'updated_at' => now()];
            if ($action === 'approve') {
                $update += ['approved_by' => $actor, 'approved_at' => now()];
            }
            if ($doc->kind === 'count' && $action === 'approve') {
                if ($this->cutoff($doc->warehouse_id) !== $doc->cutoff) {
                    InventoryActions::fail('تحرك المخزون منذ لقطة الجرد؛ أعد الجرد قبل الاعتماد.');
                }
                $variance = [];
                foreach ($lines as $line) {
                    $delta = bcsub($line->quantity, $line->recorded, 8);
                    $p = app(ProductsApi::class)->inventoryProduct($actor, $line->product_id);
                    if ($p['tracking'] === 'serial') {
                        $selected = array_keys(app(InventorySerials::class)->selections(json_decode($line->serials, true), $line->quantity));
                        $held = DB::table('inventory_serials')->where('warehouse_id', $doc->warehouse_id)->where('product_id', $line->product_id)->whereIn('state', ['available', 'reserved', 'unavailable'])->orderByRaw('normalized COLLATE "C"')->pluck('normalized')->all();
                        if ($selected !== $held) {
                            InventoryActions::fail('Serial count differs from occupancy; record a source-linked serial adjustment/correction before restarting the count.');
                        }
                    }
                    if (bccomp($delta, '0', 8) !== 0) {
                        $variance[] = ['product_id' => $line->product_id, 'quantity' => $delta];
                    }
                }
                if ($variance) {
                    $adjustment = $this->save($actor, 'adjustment', ['warehouse_id' => $doc->warehouse_id, 'reason' => 'فرق الجرد #'.$id.' — '.$doc->reason, 'creation_key' => (string) Str::uuid(), 'lines' => $variance]);
                    DB::table('inventory_documents')->where('id', $adjustment['id'])->update(['cutoff' => $doc->cutoff]);
                    $update['linked_document_id'] = $adjustment['id'];
                }
                $update['status'] = 'posted';
                $update['posted_at'] = now();
            }
            if (in_array($action, ['post', 'dispatch', 'receive'], true)) {
                $countCutoff = $doc->kind === 'adjustment'
                    ? DB::table('inventory_documents')->where('kind', 'count')->where('linked_document_id', $doc->id)->value('cutoff')
                    : null;
                if ($doc->kind === 'adjustment' && ($countCutoff ?? $doc->cutoff) !== null && $this->cutoff($doc->warehouse_id) !== (int) ($countCutoff ?? $doc->cutoff)) {
                    InventoryActions::fail('تحرك المخزون منذ لقطة الجرد؛ لا يمكن ترحيل فرق قديم. أعد الجرد.');
                }
                if ($doc->kind === 'count') {
                    InventoryActions::fail('اعتماد الجرد ينتج مسودة تسوية مستقلة.');
                }
                foreach ($lines as $line) {
                    if ($doc->kind === 'opening' && DB::table('inventory_movements')->where('warehouse_id', $doc->warehouse_id)->where('product_id', $line->product_id)->exists()) {
                        InventoryActions::fail('الأرصدة الافتتاحية لا تستبدل سجل صنف سبق ترحيله.');
                    }
                    $delta = ($doc->kind === 'issue' || $action === 'dispatch') ? bcsub('0', $line->quantity, 8) : $line->quantity;
                    $wid = $action === 'receive' ? $doc->destination_id : $doc->warehouse_id;
                    app(InventoryLedger::class)->post($actor, $wid, (array) $line, $delta, $doc->kind.'_'.$action, 'Inventory', (string) $id, (string) $line->id, 'document:'.$id.':'.$action.':'.$line->id, $id);
                }
                $update['posted_at'] = now();
            }
            DB::table('inventory_documents')->where('id', $id)->update($update);
            event(new InventoryChanged($actor, 'inventory_document', $id, $action, (array) $doc, $update));

            return (array) DB::table('inventory_documents')->where('id', $id)->first();
        });
    }

    private function serialValues(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/\R/u', $values, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($values)) {
            InventoryActions::fail('Invalid serial list.');
        }
        usort($values, fn ($a, $b) => app(InventorySerials::class)->normalize($a) <=> app(InventorySerials::class)->normalize($b));

        return array_values(array_map('trim', $values));
    }
}

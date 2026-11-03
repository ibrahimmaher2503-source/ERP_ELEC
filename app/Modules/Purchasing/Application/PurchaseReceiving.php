<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PurchaseReceiving
{
    public function warehouses(int $actor): array
    {
        $scopes = app(OrganizationApi::class)->inventoryScopes($actor);
        $rows = [];
        foreach ($scopes as $s) {
            if ($s['permissions'] === []) {
                continue;
            }
            $w = app(InventoryApi::class)->warehouse($s['warehouse_id']);
            if ($w && $w['active']) {
                $rows[] = $w;
            }
        }

        return $rows;
    }

    private function authorizeWarehouse(int $actor, int $warehouse, int $branch): void
    {
        $w = app(InventoryApi::class)->warehouse($warehouse);
        abort_unless($w && $w['active'] && $w['branch_id'] === $branch, 403, 'المستودع غير متاح أو خارج فرع الأمر.');
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M06.receipt_create', $branch, $warehouse));
        } catch (ContractException $e) {
            abort($e->getCode(), 'صلاحية ونطاق استلام المستودع مطلوبان.');
        }
    }

    public function save(int $actor, array $data, ?int $id, int $version): int
    {
        return DB::transaction(function () use ($actor, $data, $id, $version) {
            $helper = app(SupplierOffers::class);
            $helper->lock();
            $v = Validator::make($data, [
                'creation_key' => 'required|uuid', 'order_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1',
                'receipt_date' => 'required|date_format:Y-m-d', 'delivery_reference' => 'required|string|max:200|not_regex:/\x00/', 'notes' => 'nullable|string|max:5000|not_regex:/\x00/',
                'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|integer|distinct',
                'lines.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.serials' => 'sometimes|array|list|max:1000', 'lines.*.serials.*' => 'required|string|max:200',
            ])->validate();
            $po = app(PurchaseOrders::class)->record($actor, (int) $v['order_id']);
            app(PurchasingAccess::class)->authorize($actor, 'receipt_create', $po->branch_id);
            $this->authorizeWarehouse($actor, (int) $v['warehouse_id'], $po->branch_id);
            if ($po->status !== 'approved') {
                $helper->fail('يتطلب الاستلام أمر شراء معتمدًا داخليًا. المسودة لا ترحل مخزونًا.');
            }
            $old = $id ? DB::table('purchasing_receipts')->where('id', $id)->lockForUpdate()->first() : DB::table('purchasing_receipts')->where('creation_key', $v['creation_key'])->first();
            if ($id && ! $old) {
                abort(404);
            }
            $lines = [];
            foreach ($v['lines'] as $l) {
                $source = DB::table('purchasing_order_lines')->where('order_id', $po->id)->find($l['order_line_id']);
                if (! $source || bccomp($l['quantity'], '0', 8) <= 0) {
                    $helper->fail('بند أو كمية استلام غير صالح.');
                }
                $allocated = DB::table('purchasing_receipt_lines as l')->join('purchasing_receipts as r', 'r.id', '=', 'l.receipt_id')->where('l.order_line_id', $source->id)->when($old, fn ($q) => $q->where('r.id', '<>', $old->id))->sum('l.quantity');
                if (bccomp(bcadd((string) $allocated, $l['quantity'], 8), $source->quantity, 8) > 0) {
                    $helper->fail('تجاوزت كمية الأمر بعد احتساب مسودات الاستلام الأخرى.');
                }
                $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $source->product_id))->product;
                $serials = $l['serials'] ?? [];
                if ($product['tracking'] === 'serial') {
                    $serials = app(InventoryApi::class)->normalizeSerialSelections($serials, $l['quantity']);
                } else {
                    abort_unless($serials === [], 422, 'الأرقام التسلسلية تخص صنفًا مسلسلًا فقط.');
                }
                $lines[] = ['order_line_id' => $source->id, 'quantity' => bcadd($l['quantity'], '0', 8), 'serials' => json_encode($serials, JSON_THROW_ON_ERROR)];
            }
            $header = ['receipt_date' => $v['receipt_date'], 'delivery_reference' => $v['delivery_reference'], 'notes' => ($v['notes'] ?? null) ?: null];
            if ($old) {
                if ($old->created_by !== $actor || $old->order_id !== $po->id || $old->warehouse_id !== (int) $v['warehouse_id'] || $old->creation_key !== $v['creation_key']) {
                    $helper->fail('هوية الاستلام غير قابلة للتغيير.');
                }
                if (! $id) {
                    $same = collect($header)->every(fn ($val, $k) => (string) ($old->$k ?? '') === (string) ($val ?? ''));
                    $stored = DB::table('purchasing_receipt_lines')->where('receipt_id', $old->id)->orderBy('id')->get(['order_line_id', 'quantity', 'serials'])->map(fn ($l) => ['order_line_id' => $l->order_line_id, 'quantity' => $l->quantity, 'serials' => json_encode(json_decode($l->serials, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR)])->all();
                    if (! $same || $stored !== $lines) {
                        $helper->fail('مفتاح حفظ مكرر ببيانات مختلفة.');
                    }

                    return $old->id;
                }
                if ($old->status !== 'draft' || $old->version !== $version) {
                    $helper->fail('الاستلام مثبت أو نسخته قديمة.');
                }
                DB::table('purchasing_receipts')->where('id', $id)->update($header + ['version' => $version + 1, 'updated_at' => now()]);
                DB::table('purchasing_receipt_lines')->where('receipt_id', $id)->delete();
            } else {
                $id = DB::table('purchasing_receipts')->insertGetId($header + ['creation_key' => $v['creation_key'], 'order_id' => $po->id, 'branch_id' => $po->branch_id, 'warehouse_id' => $v['warehouse_id'], 'created_by' => $actor, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($lines as $l) {
                DB::table('purchasing_receipt_lines')->insert($l + ['receipt_id' => $id]);
            }
            $helper->audit($actor, 'purchase_receipt', $id, 'receipt.draft_saved', $old ? (array) $old : null, $header + ['lines' => $lines]);

            return $id;
        });
    }

    public function submit(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            $helper = app(SupplierOffers::class);
            $helper->lock();
            $row = DB::table('purchasing_receipts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            app(PurchasingAccess::class)->authorize($actor, 'receipt_create', $row->branch_id);
            $this->authorizeWarehouse($actor, $row->warehouse_id, $row->branch_id);
            if ($row->created_by === $actor && $row->status === 'integration_pending') {
                return;
            }
            if ($row->created_by !== $actor || $row->version !== $version || $row->status !== 'draft') {
                $helper->fail('نسخة قديمة أو ليست مسودتك.');
            }
            DB::table('purchasing_receipts')->where('id', $id)->update(['status' => 'integration_pending', 'version' => $version + 1, 'updated_at' => now()]);
            $helper->audit($actor, 'purchase_receipt', $id, 'receipt.integration_requested', (array) $row, ['status' => 'integration_pending', 'physical_stock_changed' => false, 'blocker' => PurchaseOrders::ISSUE_BLOCKER]);
        });
    }

    public function requestAmendment(int $actor, int $order, string $key, string $reason, array $changes): int
    {
        return DB::transaction(function () use ($actor, $order, $key, $reason, $changes) {
            $helper = app(SupplierOffers::class);
            $helper->lock();
            $doc = app(PurchaseOrders::class)->record($actor, $order, 'order_amend');
            Validator::make(['key' => $key, 'reason' => $reason, 'changes' => $changes], ['key' => 'required|uuid', 'reason' => 'required|string|max:3000|not_regex:/\x00/', 'changes' => 'required|array|min:1|max:100', 'changes.*.order_line_id' => 'required|integer|distinct', 'changes.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D']])->validate();
            if ($doc->status !== 'approved') {
                $helper->fail('طلبات التعديل تخص الأوامر المعتمدة فقط.');
            }
            $changes = array_map(fn ($c) => ['order_line_id' => (int) $c['order_line_id'], 'quantity' => bcadd($c['quantity'], '0', 8)], $changes);
            foreach ($changes as $c) {
                if (! DB::table('purchasing_order_lines')->where('order_id', $order)->where('id', $c['order_line_id'])->exists() || bccomp($c['quantity'], '0', 8) <= 0) {
                    $helper->fail('بند تعديل غير صالح.');
                }
            }
            $existing = DB::table('purchasing_amendment_requests')->where('order_id', $order)->first();
            if ($existing) {
                if ($existing->creation_key === $key && $existing->created_by === $actor && $existing->reason === $reason && json_decode($existing->proposed_changes, true) === $changes) {
                    return $existing->id;
                }
                $helper->fail('يوجد طلب تعديل قيد الانتظار لهذا الأمر.');
            }
            $id = DB::table('purchasing_amendment_requests')->insertGetId(['creation_key' => $key, 'order_id' => $order, 'created_by' => $actor, 'source_version' => $doc->version, 'reason' => $reason, 'proposed_changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'status' => 'pending_contract', 'created_at' => now(), 'updated_at' => now()]);
            $helper->audit($actor, 'purchase_order', $order, 'order.amendment_requested', null, ['request_id' => $id, 'source_version' => $doc->version, 'changes' => $changes, 'reason' => $reason]);

            return $id;
        });
    }
}

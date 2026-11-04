<?php

namespace App\Modules\Sales\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderChanged;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ApproveSalesOrder
{
    /** Warehouse choices are explicit, indexed by order line ID. */
    public function execute(int $actor, int $id, int $version, array $warehouses): void
    {
        DB::transaction(function () use ($actor, $id, $version, $warehouses) {
            DB::select('SELECT pg_advisory_xact_lock(40421)');
            $r = SalesOrder::with('lines')->lockForUpdate()->findOrFail($id);
            app(SalesOrderAccess::class)->authorize($actor, 'approve', $r->branch_id);
            app(SalesOrderAccess::class)->authorize($actor, 'view', $r->branch_id);
            Validator::make(['warehouses' => $warehouses], ['warehouses' => 'required|array|size:'.$r->lines->count(), 'warehouses.*' => 'required|integer|min:1'])->validate();
            if ($r->status === 'approved') {
                foreach (DB::table('sales_order_allocations')->where('order_id', $id)->get() as $allocation) {
                    abort_unless(isset($warehouses[$allocation->line_id]) && (int) $warehouses[$allocation->line_id] === $allocation->warehouse_id, 409);
                    app(InventoryApi::class)->requestReservation($actor, ['warehouse_id' => $allocation->warehouse_id, 'product_id' => $r->lines->firstWhere('id', $allocation->line_id)->product_id,
                        'quantity' => $allocation->base_quantity, 'source_module' => 'sales', 'source_id' => (string) $id, 'source_line' => (string) $allocation->line_id,
                        'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-order-reserve:'.$id.':'.$allocation->line_id)->toString()]);
                }

                return;
            }
            app(SalesOrderActions::class)->version($r, $version);
            $review = $r->reviews()->where('status', 'pending')->lockForUpdate()->first();
            $snapshot = app(SalesOrderActions::class)->snapshot($r);
            abort_unless($r->status === 'pending_approval' && $review && $review->target_version === $version
                && hash_equals($review->content_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))), 409);
            app(SalesOrderApprovalPolicy::class)->authorizeApprover($actor, $review->requested_by, $r->branch_id);
            $tax = null;
            $commercialReference = $r->customer_reference ?? '';
            if ($r->source_type === 'quotation') {
                $conversion = DB::table('sales_quotation_conversions')->where('order_id', $id)->first();
                abort_unless($conversion, 422, 'يلزم مصدر عرض سعر صادر ومقبول.');
                $issue = DB::table('sales_quotation_issues')->where('quotation_id', $conversion->quotation_id)->first();
                $acceptance = DB::table('sales_quotation_acceptances')->find($conversion->acceptance_id);
                abort_unless($issue && $acceptance && $acceptance->issue_id === $issue->id, 409);
                $original = json_decode($issue->commercial_snapshot, true, 512, JSON_THROW_ON_ERROR);
                abort_unless(count($original['lines']) === $r->lines->count(), 409);
                foreach ($r->lines as $i => $line) {
                    foreach (['product_id', 'unit_id', 'quantity', 'suggested_price', 'requested_price', 'price_source', 'discount_mode', 'discount_value', 'description'] as $field) {
                        $saved = $original['lines'][$i][$field];
                        $actual = $line->$field;
                        $equal = in_array($field, ['quantity', 'suggested_price', 'requested_price', 'discount_value'], true)
                            ? ($actual === null || $saved === null ? $actual === $saved : bccomp($actual, $saved, 8) === 0)
                            : $actual === $saved;
                        abort_unless($equal, 409, 'تغير محتوى الإصدار المقبول.');
                    }
                }
                foreach (['customer_id', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms'] as $field) {
                    $equal = $field === 'discount_value' ? bccomp($r->$field, $original['quotation'][$field], 8) === 0
                        : $original['quotation'][$field] === $r->$field;
                    abort_unless($equal, 409, 'تغيرت شروط الإصدار المقبول.');
                }
                $tax = json_decode($issue->tax_snapshot, true, 512, JSON_THROW_ON_ERROR);
                $commercialReference = $acceptance->reference;
            } else {
                abort_unless($r->source_type === 'direct', 422);
                app(SalesOrderCommercialReview::class)->requireReady($actor, $r, $review);
            }
            abort_unless(collect(app(SalesOrderCatalog::class)->branches($actor, 'approve'))->contains('id', $r->branch_id), 403);
            app(SalesOrderCatalog::class)->customer($actor, $r->customer_id);
            app(AccountingApi::class)->requirePostingDate($r->order_date->toDateString());
            $draft = app(DraftAmounts::class)->calculate($r->lines->toArray(), $r->discount_mode, $r->discount_value);
            $taxLines = [];
            $base = [];
            foreach ($r->lines as $i => $line) {
                app(SalesOrderCatalog::class)->product($actor, $line->product_id, $line->unit_id, $line->quantity);
                $stockProduct = app(ProductsApi::class)->inventoryProduct($actor, $line->product_id);
                abort_unless($stockProduct['active'] && $stockProduct['kind'] !== 'service' && in_array($stockProduct['tracking'], ['quantity', 'serial'], true),
                    422, 'مسار الاعتماد والحجز المالي الحالي للأصناف الكمية؛ يلزم المنتج المالي المختص للأصناف المسلسلة أو الخدمية.');
                $base[$line->id] = app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor, $line->product_id, $line->unit_id, $line->quantity))->baseQuantity;
                $taxLines[] = ['product_id' => $line->product_id, 'quantity' => $line->quantity, 'unit_price' => $line->requested_price ?? $line->suggested_price, 'discount' => bcadd($draft['lines'][$i]['discount'], '0', 32)];
                abort_unless(isset($warehouses[$line->id]) && ctype_digit((string) $warehouses[$line->id]), 422, 'اختر مستودع حجز لكل بند.');
            }
            $currentTax = app(AccountingApi::class)->calculateTax($actor, 'sale', $r->order_date->toDateString(), 'exclusive', $taxLines, bcadd($draft['quotation_discount'], '0', 32));
            if (! $currentTax['production_validated']) {
                throw ValidationException::withMessages(['readiness' => 'يلزم التحقق من المعاملة الضريبية قبل اعتماد المبالغ الرسمية.']);
            }
            if ($tax !== null) {
                foreach (['net', 'tax', 'total'] as $field) {
                    abort_unless(bccomp($tax[$field], $currentTax[$field], 2) === 0, 422, 'تغيرت المعاملة الضريبية؛ يلزم إصدار وقبول تجاري محدث.');
                }
                abort_unless(count($tax['tax_totals']) === count($currentTax['tax_totals']), 422);
                foreach ($tax['tax_totals'] as $i => $saved) {
                    $current = $currentTax['tax_totals'][$i];
                    ksort($saved);
                    ksort($current);
                    abort_unless($saved === $current, 422, 'تغيرت المعاملة الضريبية؛ يلزم إصدار وقبول تجاري محدث.');
                }
            }
            // Amounts/treatment must honor acceptance; the new order still records
            // the rules and calculation date actually validated for its own date.
            $tax = $currentTax;
            app(SalesOrderCreditLimit::class)->require($actor, $r, $tax['total'], (int) $review->requested_by);
            $doc = app(OfficialDocuments::class)->issue($actor, 'sales_order', (string) $id, 1, $r->order_date->toDateString(), [
                'title' => 'أمر بيع', 'fields' => ['العميل' => $r->customer_snapshot['name'], 'مرجع العميل أو القبول' => $commercialReference, 'شروط السداد' => $r->payment_terms ?? '', 'شروط التسليم' => $r->delivery_terms ?? ''],
                'columns' => ['الصنف', 'الوحدة', 'الكمية'], 'rows' => $r->lines->map(fn ($l) => [$l->description, $l->product_snapshot['unit_name'], $l->quantity])->all(),
                'tax' => $tax, 'totals' => ['الصافي' => $tax['net'], 'الضريبة' => $tax['tax'], 'الإجمالي' => $tax['total']],
            ]);
            DB::table('sales_order_issues')->insert(['order_id' => $id, 'review_id' => $review->id, 'document_id' => $doc['id'],
                'commercial_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'tax_snapshot' => json_encode($tax, JSON_THROW_ON_ERROR), 'approved_by' => $actor, 'approved_at' => now()]);
            $r->update(['status' => 'approved', 'number' => $doc['number'], 'version' => $version + 1, 'updated_by' => $actor,
                'calculation' => array_replace($draft, ['net' => $tax['net'], 'tax' => $tax['tax'], 'total' => $tax['total'], 'official' => true])]);
            foreach ($r->lines as $line) {
                $reservation = app(InventoryApi::class)->requestReservation($actor, ['warehouse_id' => (int) $warehouses[$line->id], 'product_id' => $line->product_id,
                    'quantity' => $base[$line->id], 'source_module' => 'sales', 'source_id' => (string) $id, 'source_line' => (string) $line->id,
                    'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-order-reserve:'.$id.':'.$line->id)->toString()]);
                DB::table('sales_order_allocations')->insert(['order_id' => $id, 'line_id' => $line->id, 'base_quantity' => $base[$line->id], 'warehouse_id' => (int) $warehouses[$line->id], 'reservation_id' => $reservation['id']]);
            }
            $review->update(['status' => 'approved', 'decided_by' => $actor, 'decided_at' => now()]);
            event(new SalesOrderChanged($actor, $id, 'approved_and_reserved', null, ['number' => $doc['number'], 'document_id' => $doc['id'], 'review_id' => $review->id]));
        });
    }
}

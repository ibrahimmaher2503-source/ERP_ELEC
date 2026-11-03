<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PurchaseOrders
{
    public const ISSUE_BLOCKER = 'الاعتماد الداخلي لا يصدر مستندًا رسميًا. الإصدار يتطلب قواعد ضريبية متحققًا منها. ترحيل الاستلام يتطلب أمرًا صادرًا وتفعيل المالية واعتماد ترحيل مستقلًا ونطاق المستودع.';

    public function record(int $actor, int $id, string $permission = 'order_view'): object
    {
        $doc = DB::table('purchasing_orders')->find($id);
        abort_unless($doc, 404);
        app(PurchasingAccess::class)->authorize($actor, $permission, $doc->branch_id);

        return $doc;
    }

    public function save(int $actor, array $data, ?int $id, int $version): int
    {
        return DB::transaction(function () use ($actor, $data, $id, $version) {
            $helper = app(SupplierOffers::class);
            $helper->lock();
            $v = Validator::make($data, [
                'creation_key' => 'required|uuid', 'branch_id' => 'required|integer|min:1', 'supplier_id' => 'required|integer|min:1', 'award_id' => 'nullable|integer|min:1',
                'order_date' => 'required|date_format:Y-m-d', 'expected_date' => 'nullable|date_format:Y-m-d|after_or_equal:order_date',
                'currency' => ['required', 'in:EGP'], 'payment_terms' => 'nullable|string|max:3000|not_regex:/\x00/', 'notes' => 'nullable|string|max:5000|not_regex:/\x00/',
                'tax_mode' => 'sometimes|required|in:inclusive,exclusive',
                'reported_tax' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|integer|min:1|distinct',
                'lines.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.unit_price' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.discount_amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
            ])->validate();
            app(PurchasingAccess::class)->authorize($actor, $id ? 'order_update' : 'order_create', (int) $v['branch_id']);
            try {
                $supplier = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $v['supplier_id']));
            } catch (SupplierContractException $e) {
                if ($e->getCode() === 403) {
                    abort(403);
                } $helper->fail('المورد غير متاح أو غير نشط.');
            }
            $old = $id ? $this->record($actor, $id, 'order_update') : DB::table('purchasing_orders')->where('creation_key', $v['creation_key'])->first();
            $award = null;
            $offer = null;
            $sourceLines = collect();
            if (! empty($v['award_id'])) {
                $award = DB::table('purchasing_awards')->where('id', $v['award_id'])->lockForUpdate()->first();
                if (! $award || $award->status !== 'approved' || $award->branch_id !== (int) $v['branch_id']) {
                    $helper->fail('الترسية غير معتمدة أو من فرع مختلف.');
                }
                $offer = app(SupplierOffers::class)->record($actor, $award->offer_id);
                if ($offer->supplier_id !== $supplier->id) {
                    $helper->fail('المورد لا يطابق الترسية.');
                }
                $sourceLines = DB::table('purchasing_offer_lines as l')->join('purchasing_rfq_product_lines as r', 'r.id', '=', 'l.rfq_line_id')->where('l.offer_id', $offer->id)->get(['l.*', 'r.product_id']);
                $used = DB::table('purchasing_orders')->where('award_id', $award->id)->when($old, fn ($q) => $q->where('id', '<>', $old->id))->exists();
                if ($used) {
                    $helper->fail('الترسية مرتبطة بأمر شراء بالفعل.');
                }
            }
            $lines = [];
            $net = '0.00000000';
            foreach ($v['lines'] as $l) {
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $l['product_id']))->product;
                if (! $p['active'] || bccomp($l['quantity'], '0', 8) <= 0) {
                    $helper->fail('صنف غير نشط أو كمية غير موجبة.');
                }
                $raw = bcmul($l['quantity'], $l['unit_price'], 16);
                if (bccomp($raw, bcadd($raw, '0', 8), 16) !== 0) {
                    $helper->fail('ناتج السعر والكمية يتجاوز الدقة المتاحة.');
                }
                $amount = bcsub($raw, $l['discount_amount'], 8);
                if (bccomp($amount, '0', 8) < 0) {
                    $helper->fail('خصم البند يتجاوز قيمته.');
                }
                $source = $sourceLines->firstWhere('product_id', $p['id']);
                if ($award && (! $source || bccomp($l['quantity'], $source->quantity, 8) !== 0 || bccomp($l['unit_price'], $source->unit_price, 8) !== 0 || bccomp($l['discount_amount'], $source->discount_amount, 8) !== 0)) {
                    $helper->fail('البيانات تختلف عن الترسية المعتمدة؛ يلزم قرار تعديل تجاري مستقل.');
                }
                $lines[] = ['product_id' => $p['id'], 'unit_id' => $p['base_unit_id'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'offer_line_id' => $source?->id, 'quantity' => bcadd($l['quantity'], '0', 8), 'unit_price' => bcadd($l['unit_price'], '0', 8), 'discount_amount' => bcadd($l['discount_amount'], '0', 8), 'net_amount' => $amount];
                $net = bcadd($net, $amount, 8);
                if (bccomp($net, '999999999999999999999999.99999999', 8) > 0) {
                    $helper->fail('إجمالي الأمر يتجاوز الدقة المالية المدعومة.');
                }
            }
            $tax = ($v['reported_tax'] ?? '') !== '' ? bcadd($v['reported_tax'], '0', 8) : null;
            if ($award && (count($lines) !== $sourceLines->count() || $v['currency'] !== $offer->currency || ($v['payment_terms'] ?: null) !== ($offer->payment_terms ?: null) || $tax !== $offer->reported_tax)) {
                $helper->fail('العملة والشروط والكميات يجب أن تطابق الترسية.');
            }
            $header = ['order_date' => $v['order_date'], 'expected_date' => ($v['expected_date'] ?? null) ?: null, 'currency' => $v['currency'], 'tax_mode' => $v['tax_mode'] ?? 'exclusive', 'payment_terms' => ($v['payment_terms'] ?? null) ?: null, 'notes' => ($v['notes'] ?? null) ?: null, 'net_amount' => $net, 'reported_tax' => $tax];
            if ($old) {
                if ($old->created_by !== $actor || $old->branch_id !== (int) $v['branch_id'] || $old->supplier_id !== $supplier->id || $old->award_id !== $award?->id || $old->creation_key !== $v['creation_key']) {
                    $helper->fail('هوية أمر الشراء غير قابلة للتغيير.');
                }
                if (! $id) {
                    $same = collect($header)->every(fn ($value, $key) => (string) ($old->$key ?? '') === (string) ($value ?? ''));
                    $stored = DB::table('purchasing_order_lines')->where('order_id', $old->id)->orderBy('id')->get()->map(fn ($l) => array_replace(array_fill_keys(array_keys($lines[0]), null), array_intersect_key((array) $l, array_flip(array_keys($lines[0])))))->all();
                    if (! $same || $stored !== $lines) {
                        $helper->fail('مفتاح إنشاء مستخدم ببيانات مختلفة.');
                    }

                    return $old->id;
                }
                if ($old->status !== 'draft' || $old->version !== $version) {
                    $helper->fail('أمر الشراء ليس مسودة أو نسخته قديمة.');
                }
                DB::table('purchasing_orders')->where('id', $id)->update($header + ['version' => $version + 1, 'updated_at' => now()]);
                DB::table('purchasing_order_lines')->where('order_id', $id)->delete();
            } else {
                $id = DB::table('purchasing_orders')->insertGetId($header + ['creation_key' => $v['creation_key'], 'branch_id' => $v['branch_id'], 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'award_id' => $award?->id, 'created_by' => $actor, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($lines as $l) {
                DB::table('purchasing_order_lines')->insert($l + ['order_id' => $id]);
            }
            $helper->audit($actor, 'purchase_order', $id, 'order.saved', $old ? (array) $old : null, $header + ['lines' => $lines]);

            return $id;
        });
    }

    public function transition(int $actor, int $id, int $version, string $action, string $reason = ''): void
    {
        DB::transaction(function () use ($actor, $id, $version, $action, $reason) {
            $helper = app(SupplierOffers::class);
            $helper->lock();
            $permission = match ($action) {
                'submit' => 'order_submit','approve','reject' => 'order_approve','reopen' => 'order_update',default => abort(422)
            };
            $doc = $this->record($actor, $id, $permission);
            if ($doc->version !== $version) {
                $helper->fail('نسخة قديمة؛ أعد فتح أمر الشراء.');
            }
            $source = match ($action) {
                'submit' => 'draft','reopen' => 'rejected',default => 'submitted'
            };
            if ($doc->status !== $source) {
                $helper->fail('الحالة الحالية لا تسمح بالإجراء.');
            }
            if (in_array($action, ['approve', 'reject']) && $doc->created_by === $actor) {
                $helper->fail('الاعتماد الذاتي ممنوع.');
            }
            if (in_array($action, ['submit', 'reopen']) && $doc->created_by !== $actor) {
                $helper->fail('هذا الإجراء لمنشئ الأمر.');
            }
            Validator::make(['reason' => $reason], ['reason' => ($action === 'reject' ? 'required' : 'nullable').'|string|max:3000|not_regex:/\x00/'])->validate();
            $lines = DB::table('purchasing_order_lines')->where('order_id', $id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                $helper->fail('الأمر بلا بنود.');
            }
            if ($action === 'approve') {
                try {
                    app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, $doc->supplier_id));
                } catch (SupplierContractException $exception) {
                    if ($exception->getCode() === 403) {
                        abort(403);
                    }
                    $helper->fail('المورد غير متاح أو غير نشط؛ أعد مراجعة أمر الشراء.');
                }
                foreach ($lines as $line) {
                    $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $line->product_id))->product;
                    if (! $product['active'] || $product['base_unit_id'] !== $line->unit_id) {
                        $helper->fail('صنف غير نشط أو تغيرت وحدته؛ أعد مراجعة أمر الشراء.');
                    }
                }
            }
            if ($action === 'approve' && $doc->award_id !== null) {
                $award = DB::table('purchasing_awards')->find($doc->award_id);
                if (! $award || $award->status !== 'approved') {
                    $helper->fail('الترسية ليست معتمدة.');
                }
                $offer = app(SupplierOffers::class)->record($actor, $award->offer_id);
                if ($offer->valid_until && $offer->valid_until < now('Africa/Cairo')->toDateString()) {
                    $helper->fail('انتهت صلاحية عرض المورد.');
                }
            }
            $status = match ($action) {
                'submit' => 'submitted','approve' => 'approved','reject' => 'rejected','reopen' => 'draft'
            };
            DB::table('purchasing_order_reviews')->insert(['order_id' => $id, 'actor_id' => $actor, 'action' => $action, 'document_version' => $version, 'reason' => $reason ?: null, 'snapshot' => json_encode(['header' => (array) $doc, 'lines' => $lines->all()], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $after = ['status' => $status, 'version' => $version + 1, 'updated_at' => now()];
            if ($action === 'approve') {
                $after += ['approved_by' => $actor, 'approved_at' => now()];
            }
            DB::table('purchasing_orders')->where('id', $id)->update($after);
            $helper->audit($actor, 'purchase_order', $id, 'order.'.$action, (array) $doc, $after);
        });
    }

    public function issue(int $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            app(SupplierOffers::class)->lock();
            $order = $this->record($actor, $id, 'order_issue');
            if ($order->status !== 'approved' || $order->currency !== 'EGP') {
                app(SupplierOffers::class)->fail('يلزم أمر معتمد بالجنيه المصري للإصدار الرسمي.');
            }
            if (DB::table('purchasing_order_issues')->where('order_id', $id)->exists()) {
                return;
            }
            app(AccountingApi::class)->requirePostingDate($order->order_date);
            $supplier = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, $order->supplier_id));
            $lines = DB::table('purchasing_order_lines')->where('order_id', $id)->orderBy('id')->get();
            $tax = app(AccountingApi::class)->calculateTax($actor, 'purchase', $order->order_date, $order->tax_mode,
                $lines->map(fn ($l) => ['product_id' => $l->product_id, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'discount' => $l->discount_amount])->all());
            if (! $tax['production_validated']) {
                app(SupplierOffers::class)->fail('يلزم تسجيل التحقق من المعاملة الضريبية الفعلية للشركة قبل الإصدار.');
            }
            // Supplier-reported tax is evidence for comparison, never an override.
            if ($order->reported_tax !== null && FinancialDecimal::round($order->reported_tax) !== $tax['tax']) {
                app(SupplierOffers::class)->fail('الضريبة المبلغ عنها تختلف عن القاعدة الضريبية. يلزم تصحيح المصدر بمسار تجاري معتمد.');
            }
            $rows = [];
            foreach ($lines as $line) {
                $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $line->product_id))->product;
                if (! $product['active'] || $product['base_unit_id'] !== $line->unit_id) {
                    app(SupplierOffers::class)->fail('صنف غير مؤهل للإصدار.');
                }
                $rows[] = [$line->sku, $line->product_name, $line->quantity, $line->unit_price, $line->discount_amount];
            }
            $document = app(OfficialDocuments::class)->issue($actor, 'purchase_order', (string) $id, 1, $order->order_date,
                ['title' => 'أمر شراء', 'fields' => ['المورد' => $supplier->name, 'شروط السداد' => $order->payment_terms ?? '', 'ملاحظات' => $order->notes ?? ''],
                    'columns' => ['الكود', 'الصنف', 'الكمية', 'سعر الوحدة', 'الخصم'], 'rows' => $rows, 'tax' => $tax,
                    'totals' => ['الصافي' => $tax['net'], 'الضريبة' => $tax['tax'], 'الإجمالي' => $tax['total']]]);
            DB::table('purchasing_order_issues')->insert(['order_id' => $id, 'source_version' => $order->version, 'document_id' => $document['id'],
                'official_number' => $document['number'], 'tax_snapshot' => json_encode($tax, JSON_THROW_ON_ERROR), 'issued_by' => $actor, 'issued_at' => now()]);
            app(SupplierOffers::class)->audit($actor, 'purchase_order', $id, 'order.issued', null, ['number' => $document['number'], 'document_id' => $document['id'], 'sha256' => $document['sha256'], 'tax' => $tax]);
        });
    }
}

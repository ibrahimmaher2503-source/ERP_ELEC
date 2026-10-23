<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Support\Facades\DB;

final class PurchasingExportController
{
    public function __invoke(string $entity)
    {
        if ($entity === 'comparison') {
            return $this->comparison();
        }
        request()->validate(['branch' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'supplier' => 'nullable|string|max:200', 'status' => 'nullable|string|max:30']);
        [$table,$permission,$columns] = match ($entity) {
            'requisitions' => ['purchase_requisitions', 'requisition_view', ['id' => 'المرجع الداخلي', 'branch_id' => 'الفرع', 'request_date' => 'التاريخ', 'purpose' => 'سبب الاحتياج', 'status' => 'الحالة']],
            'rfqs' => ['purchasing_rfqs', 'rfq_view', ['id' => 'المرجع الداخلي', 'requisition_id' => 'طلب الاحتياج', 'subject' => 'الموضوع', 'status' => 'الحالة', 'request_date' => 'التاريخ']],
            'offers' => ['purchasing_offers', 'offer_view', ['id' => 'المرجع الداخلي', 'rfq_id' => 'RFQ', 'supplier_name' => 'المورد', 'supplier_reference' => 'مرجع المورد', 'currency' => 'العملة', 'net_amount' => 'الصافي المقدم', 'reported_tax' => 'الضريبة المقدمة غير المعتمدة', 'status' => 'الحالة']],
            'orders' => ['purchasing_orders', 'order_view', ['id' => 'المرجع الداخلي', 'supplier_name' => 'المورد', 'order_date' => 'التاريخ', 'currency' => 'العملة', 'net_amount' => 'الصافي', 'status' => 'الاعتماد الداخلي', 'official_number' => 'رقم الإصدار الرسمي']],
            'receipts' => ['purchasing_receipts', 'receipt_view', ['id' => 'المرجع الداخلي', 'order_id' => 'أمر الشراء', 'warehouse_id' => 'المستودع', 'receipt_date' => 'التاريخ', 'delivery_reference' => 'مرجع التوريد', 'status' => 'الحالة']],
            default => abort(404),
        };
        $access = app(PurchasingAccess::class);
        $ids = array_intersect(array_column($access->branches(auth()->id(), $permission), 'id'), array_column($access->branches(auth()->id(), 'export'), 'id'));
        abort_unless($ids, 403);
        $q = DB::table($table)->whereIn('branch_id', $ids);
        $date = match ($entity) {
            'requisitions', 'rfqs' => 'request_date', 'offers' => 'document_date', 'orders' => 'order_date', 'receipts' => 'receipt_date',
        };
        if (request()->filled('from')) {
            $q->where($date, '>=', request('from'));
        }
        if (request()->filled('to')) {
            $q->where($date, '<=', request('to'));
        }
        if ($entity === 'orders' && request()->filled('status')) {
            $q->where('status', request('status'));
        }
        if (request()->filled('supplier')) {
            if (in_array($entity, ['orders', 'offers'], true)) {
                $q->where('supplier_name', 'ilike', '%'.request('supplier').'%');
            } elseif ($entity === 'receipts') {
                $q->whereIn('order_id', DB::table('purchasing_orders')->whereIn('branch_id', $ids)->where('supplier_name', 'ilike', '%'.request('supplier').'%')->select('id'));
            }
        }
        if ($entity === 'receipts') {
            $q->whereIn('warehouse_id', array_column(app(OrganizationApi::class)->inventoryScopes(auth()->id()), 'warehouse_id'));
        }
        if (request()->filled('branch')) {
            abort_unless(ctype_digit((string) request('branch')), 422);
            $q->where('branch_id', (int) request('branch'));
        }
        $path = app(WorkbookCodec::class)->write('purchasing-'.$entity, $columns, (function () use ($q, $columns) {
            foreach ($q->orderBy('id')->limit(10001)->get(array_keys($columns)) as $row) {
                yield (array) $row;
            }
        })());

        return response()->download($path, 'purchasing-'.$entity.'.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    private function comparison()
    {
        $input = request()->validate(['rfq' => 'required|integer|min:1']);
        $rfq = DB::table('purchasing_rfqs')->find($input['rfq']);
        abort_unless($rfq, 404);
        $access = app(PurchasingAccess::class);
        foreach (['comparison_view', 'offer_view', 'export'] as $permission) {
            $access->authorize(auth()->id(), $permission, $rfq->branch_id);
        }
        $columns = ['offer_id' => 'نسخة العرض', 'supplier_name' => 'المورد', 'supplier_reference' => 'مرجع المورد', 'currency' => 'العملة الأصلية', 'sku' => 'SKU', 'product_name' => 'الصنف', 'requested' => 'الكمية المطلوبة', 'quantity' => 'الكمية المقدمة', 'unit_price' => 'سعر الوحدة', 'discount_amount' => 'خصم البند', 'net_amount' => 'صافي البند', 'specification' => 'مواصفات المورد', 'lead_days' => 'مدة التوريد بالأيام', 'payment_terms' => 'شروط الدفع', 'warranty' => 'الضمان', 'valid_until' => 'صالح حتى'];
        $q = DB::table('purchasing_offers as o')->join('purchasing_offer_lines as l', 'l.offer_id', '=', 'o.id')->join('purchasing_rfq_product_lines as r', 'r.id', '=', 'l.rfq_line_id')->where('o.rfq_id', $rfq->id)->where('o.status', 'recorded');
        $path = app(WorkbookCodec::class)->write('purchasing-comparison', $columns, (function () use ($q) {
            foreach ($q->orderBy('o.id')->orderBy('l.id')->limit(10001)->get(['o.id as offer_id', 'o.supplier_name', 'o.supplier_reference', 'o.currency', 'r.sku', 'r.product_name', 'r.quantity as requested', 'l.quantity', 'l.unit_price', 'l.discount_amount', 'l.net_amount', 'l.specification', 'o.lead_days', 'o.payment_terms', 'o.warranty', 'o.valid_until']) as $row) {
                yield (array) $row;
            }
        })());

        return response()->download($path, 'supplier-comparison.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}

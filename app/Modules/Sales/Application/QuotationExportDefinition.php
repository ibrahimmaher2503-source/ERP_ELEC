<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Quotation;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Support\Facades\DB;

final class QuotationExportDefinition
{
    public function export(int $actor, array $filters = [], ?int $id = null): string
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, 'export');
        $query = app(QuotationQuery::class)->query($actor, $filters)->with('lines');
        if ($id !== null) {
            Quotation::findOrFail($id);
            $query->whereKey($id);
        }
        $rows = (function () use ($query) {
            foreach ($query->orderBy('id')->cursor() as $q) {
                foreach ($q->lines as $i => $l) {
                    yield [
                        $q->number ?? 'DRAFT-'.$q->draft_key, $q->revision, $q->status, DB::table('sales_quotation_conversions')->where('quotation_id', $q->id)->exists() ? 'full' : 'not_converted', $q->customer_snapshot['name'] ?? '',
                        $l->product_snapshot['sku'] ?? '', $l->description, $l->quantity, $l->product_snapshot['unit_code'] ?? '',
                        $l->suggested_price ?? '', $l->requested_price ?? '', $l->discount_mode, $l->discount_value,
                        $q->discount_mode, $q->discount_value, $q->calculation['lines'][$i]['net'] ?? '',
                        $q->calculation['net'] ?? '', $q->calculation['tax'] ?? '', $q->calculation['total'] ?? '', 'EGP', ($q->calculation['official'] ?? false) ? 'ISSUED - FROZEN TAX SNAPSHOT' : 'DRAFT - UNROUNDED - TAX UNAVAILABLE',
                    ];
                }
            }
        })();

        return app(WorkbookCodec::class)->write('quotations', ['رقم العرض أو المسودة', 'المراجعة', 'الحالة التجارية', 'حالة التحويل', 'العميل', 'SKU', 'الوصف', 'الكمية', 'الوحدة', 'السعر المقترح', 'السعر المطلوب', 'نوع خصم البند', 'قيمة خصم البند', 'نوع خصم العرض', 'قيمة خصم العرض', 'صافي البند قبل خصم العرض', 'صافي العرض قبل الضريبة', 'الضريبة', 'الإجمالي النهائي', 'العملة', 'حالة الحساب'], $rows);
    }
}

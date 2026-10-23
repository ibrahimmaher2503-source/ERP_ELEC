<?php

namespace App\Modules\Sales\Application;

use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;

final class SalesOrderExportDefinition
{
    public function export(int $actor, array $filters = [], ?int $id = null): string
    {
        $query = app(SalesOrderQuery::class)->query($actor, $filters, 'export')->with('lines');
        if ($id !== null) {
            $query->whereKey($id)->firstOrFail();
        }
        $rows = (function () use ($query) {
            foreach ($query->orderBy('id')->lazyById(100) as $r) {
                foreach ($r->lines as $i => $line) {
                    yield [$r->number ?? 'DRAFT-'.$r->draft_key, $r->branch_snapshot['code'] ?? '', $r->customer_snapshot['name'] ?? '', $r->order_date->toDateString(), $r->source_type, $r->status, $line->product_snapshot['sku'] ?? '', $line->description, $line->quantity, $line->product_snapshot['unit_code'] ?? '', $line->requested_price ?? '', $line->discount_mode, $line->discount_value, $r->calculation['lines'][$i]['net'] ?? '', 'EGP', 'PROPOSED UNROUNDED DRAFT; NO TAX OR OFFICIAL TOTAL'];
                }
            }
        })();

        return app(WorkbookCodec::class)->write('sales-orders', ['معرف المسودة', 'الفرع', 'العميل', 'التاريخ', 'المصدر', 'الحالة', 'SKU', 'الصنف', 'الكمية', 'الوحدة', 'السعر المقترح للمراجعة', 'نوع الخصم', 'الخصم', 'صافي البند قبل خصم الأمر', 'العملة', 'حالة الحساب'], $rows);
    }
}

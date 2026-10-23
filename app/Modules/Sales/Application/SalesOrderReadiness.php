<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesOrderReadiness
{
    public function blockers(SalesOrder $r, string $operation = 'approve'): array
    {
        return match ($operation) {
            'convert' => ['عرض السعر الحالي لا يملك نسخة صادرة مقبولة بإثبات قبول محفوظ؛ لا يمكن تخصيص أي كمية للتحويل.'],
            'amend' => ['التعديل الرسمي يتطلب نسخة أمر بيع معتمدة محفوظة وتفويض إعادة اعتماد وعقد تسوية حجوزات M06. المسودة قابلة للتحرير المباشر.'],
            'delivery_create', 'delivery_confirm' => ['أمر البيع غير معتمد تشغيليًا.', 'عقود M06 للمخازن ونطاقها والحجز والصرف الفعلي وعقد إذن التسليم غير متاحة. لا توجد كميات مؤكدة مؤهلة للتسليم.'],
            'invoice_view' => ['لا توجد تسليمات مؤكدة أو طلبات فوترة لهذا الأمر.', 'منتج الفواتير الفعلية من المحاسبة غير متاح؛ الطلب لا يعد فاتورة.'],
            'return_create' => ['المرتجع يحتاج إذن تسليم مؤكدًا وكميات مؤهلة وعقود الاستلام والفحص والتسوية من أصحابها. لا توجد تسليمات مؤكدة لهذا الأمر.'],
            'close' => ['يجب اعتماد الأمر وإتمام أو إلغاء الكميات غير المنفذة وتحرير الحجوزات أولًا.', 'الإغلاق يتطلب تغطية بفواتير صادرة فعلية؛ عقد الفوترة غير متاح. التحصيل ليس شرطًا للإغلاق.'],
            default => [
                'الاعتماد وفق صلاحية M01 الديناميكية. الاستثناءات تحتاج مسؤول الاستثناءات المحدد في إعدادات أوامر البيع؛ لا توجد حدود مفترضة.',
                'مصدر السعر التجاري المعتمد وعقد الضرائب والتقريب من M11 غير متاحين؛ القيم الحالية مقترحات مسودة فقط.',
                'ترقيم أمر البيع الرسمي لم يعتمد؛ معرف المسودة التقني ليس رقمًا رسميًا.',
                'عقد M06 لطلب الحجز التلقائي ونتائجه غير متاح؛ لم يتم حجز أو صرف أي مخزون.',
            ],
        };
    }

    public function guard(int $actor, int $id, int $version, string $operation): never
    {
        abort_unless(in_array($operation, ['approve', 'amend', 'delivery_create', 'delivery_confirm', 'invoice_view', 'close', 'return_create', 'convert'], true), 422);
        DB::transaction(function () use ($actor, $id, $version, $operation) {
            $r = SalesOrder::lockForUpdate()->findOrFail($id);
            app(SalesOrderAccess::class)->authorize($actor, 'view', $r->branch_id);
            app(SalesOrderAccess::class)->authorize($actor, $operation, $r->branch_id);
            app(SalesOrderActions::class)->version($r, $version);
            if ($operation === 'approve') {
                $review = $r->reviews()->where('status', 'pending')->first();
                if ($r->status === 'pending_approval' && $review) {
                    app(SalesOrderApprovalPolicy::class)->authorizeApprover($actor, (int) $review->requested_by, $r->branch_id);
                }
            }
            throw ValidationException::withMessages(['readiness' => implode(' ', $this->blockers($r, $operation))]);
        });
    }
}

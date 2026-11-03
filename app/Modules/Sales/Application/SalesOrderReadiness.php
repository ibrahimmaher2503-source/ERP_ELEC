<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SalesOrderReadiness
{
    public function blockers(SalesOrder $r, string $operation = 'approve'): array
    {
        if ($operation === 'approve' && $r->source_type === 'quotation') {
            return $r->status === 'pending_approval' ? ['اختر مستودع كل بند وسجل الاعتماد المستقل؛ يتحقق الإجراء من الإصدار المقبول والضريبة ويطلب الحجز الفعلي.'] : ['يلزم طلب مراجعة مستقل قبل الاعتماد.'];
        }
        if ($operation === 'approve' && $r->source_type === 'direct') {
            $review = $r->reviews()->where('status', 'pending')->first();
            if ($r->status !== 'pending_approval' || ! $review || $review->target_version !== $r->version) {
                return ['يلزم إرسال المحتوى الحالي لطلب مراجعة مستقل قبل الاعتماد.'];
            }
            try {
                app(SalesOrderCommercialReview::class)->requireReady(auth()->id() ?? $r->updated_by ?? $r->created_by ?? 0, $r, $review);
            } catch (ValidationException $exception) {
                return array_values(array_merge(...array_values($exception->errors())));
            } catch (HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }

                return ['تعذر التحقق من صلاحيات مصدر السعر أو صاحب قرار الاستثناءات الحالي.'];
            }

            return [];
        }
        if (in_array($operation, ['delivery_create', 'delivery_confirm'], true) && $r->status === 'approved') {
            return [];
        }

        return match ($operation) {
            'convert' => ['ابدأ التحويل من صفحة عرض السعر الصادر والمقبول، وحدد الفرع؛ لا تحول مسودة أمر بيع إلى مصدر قبول.'],
            'amend' => ['التعديل الرسمي يتطلب نسخة أمر بيع معتمدة محفوظة وتفويض إعادة اعتماد وعقد تسوية حجوزات M06. المسودة قابلة للتحرير المباشر.'],
            'delivery_create', 'delivery_confirm' => ['أمر البيع غير معتمد تشغيليًا. يلزم اعتماد فعلي وحجز مؤهل قبل إنشاء طلب التسليم وتأكيد الصرف.'],
            'invoice_view' => ['لا توجد تسليمات مؤكدة أو طلبات فوترة لهذا الأمر.', 'منتج الفواتير الفعلية من المحاسبة غير متاح؛ الطلب لا يعد فاتورة.'],
            'return_create' => ['المرتجع يحتاج إذن تسليم مؤكدًا وكميات مؤهلة وعقود الاستلام والفحص والتسوية من أصحابها. لا توجد تسليمات مؤكدة لهذا الأمر.'],
            'close' => ['يجب اعتماد الأمر وإتمام أو إلغاء الكميات غير المنفذة وتحرير الحجوزات أولًا.', 'الإغلاق يتطلب تغطية بفواتير صادرة فعلية؛ عقد الفوترة غير متاح. التحصيل ليس شرطًا للإغلاق.'],
            default => [
                'الاعتماد وفق صلاحية M01 الديناميكية. الاستثناءات تحتاج مسؤول الاستثناءات المحدد في إعدادات أوامر البيع؛ لا توجد حدود مفترضة.',
                'المسودات المباشرة تحتاج تقييمًا تجاريًا وربط قرار الاستثناءات قبل اعتمادها. مسار عرض السعر الصادر والمقبول متاح.',
                'معرف المسودة ليس رقمًا رسميًا؛ الترقيم والمبالغ الثابتة والحجز تنشأ ذريًا عند الاعتماد الفعلي للمصدر المؤهل.',
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

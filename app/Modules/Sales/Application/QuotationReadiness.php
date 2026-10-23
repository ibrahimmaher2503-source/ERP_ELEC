<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Quotation;
use Illuminate\Validation\ValidationException;

final class QuotationReadiness
{
    public function blockers(Quotation $r): array
    {
        $blockers = [];
        if ($r->lines->isEmpty()) {
            $blockers[] = 'أضف بندًا من كتالوج المنتجات.';
        }
        if ($r->lines->contains(fn ($l) => $l->suggested_price === null || $l->price_source === null)) {
            $blockers[] = 'مصدر أسعار البيع المعتمد من M02 غير متاح؛ الأسعار المطلوبة في المسودة لا تُعد أسعارًا معتمدة.';
        }
        $evaluation = app(QuotationCommercialPolicy::class)->evaluate($r);
        $blockers = array_merge($blockers, $evaluation['blockers']);
        if ($evaluation['exceptions'] && ! app(QuotationApprovals::class)->hasCurrentApproval($r, $evaluation)) {
            $blockers[] = 'الاستثناءات التجارية تحتاج طلب موافقة مرتبطًا بهذه المسودة والسياسة الحالية.';
        }

        $blockers[] = 'عقد الضرائب ودقة وتقريب المبالغ من M11 غير متاح؛ الضريبة والإجمالي النهائي غير محسوبين.';
        $blockers[] = 'عقد لقطة هوية الشركة والتوقيع المعتمد وملف PDF الرسمي الثابت غير متاح.';

        return $blockers;
    }

    public function requireIssueReady(Quotation $r): void
    {
        throw ValidationException::withMessages(['readiness' => implode(' ', $this->blockers($r))]);
    }

    public function guardOperation(int $actor, Quotation $r, string $operation, int $version): never
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, $operation);
        app(QuotationActions::class)->version($r, $version);
        if (in_array($r->status, ['cancelled', 'rejected', 'expired'], true)) {
            throw ValidationException::withMessages(['readiness' => 'عرض السعر مغلق نهائيًا؛ يمكن إنشاء عرض جديد عن طريق النسخ.']);
        }
        $reason = match ($operation) {
            'convert' => 'مسودات أوامر البيع المباشرة متاحة، لكن التحويل يتطلب نسخة عرض صادرة مقبولة بإثبات مكتوب وكميات تحويل محفوظة. منتج النسخ الصادرة والقبول غير متاح؛ لم يتم تحويل أي كمية.',
            'accept' => 'لا توجد نسخة صادرة يمكن ربط قبول العميل وإثباته بها.',
            'deliver' => 'لا توجد نسخة صادرة بملف رسمي لتسجيل إرسالها. التنزيل لا يسجل الإرسال.',
            'revise' => 'لا توجد نسخة صادرة محفوظة لإنشاء مراجعة منها. يمكن متابعة تعديل المسودة.',
            default => implode(' ', $this->blockers($r)),
        };
        throw ValidationException::withMessages(['readiness' => $reason]);
    }
}

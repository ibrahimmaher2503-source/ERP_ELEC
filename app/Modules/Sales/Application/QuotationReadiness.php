<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuotationReadiness
{
    public function blockers(Quotation $r): array
    {
        if (in_array($r->status, ['issued', 'sent', 'accepted', 'converted'], true)) {
            return [];
        }
        $blockers = [];
        if ($r->lines->isEmpty()) {
            $blockers[] = 'أضف بندًا من كتالوج المنتجات.';
        }
        if ($r->lines->contains(fn ($l) => $l->suggested_price === null || $l->price_source === null)) {
            $blockers[] = 'قائمة أسعار البيع المعتمدة غير متاحة؛ الأسعار المطلوبة في المسودة لا تُعد أسعارًا معتمدة.';
        }
        $evaluation = app(QuotationCommercialPolicy::class)->evaluate($r);
        $blockers = array_merge($blockers, $evaluation['blockers']);
        if ($evaluation['exceptions'] && ! app(QuotationApprovals::class)->hasCurrentApproval($r, $evaluation)) {
            $blockers[] = 'الاستثناءات التجارية تحتاج طلب موافقة مرتبطًا بهذه المسودة والسياسة الحالية.';
        }

        if (! $r->valid_until || $r->valid_until->toDateString() < now('Africa/Cairo')->toDateString()) {
            $blockers[] = 'عرض السعر منتهي الصلاحية.';
        }

        return $blockers;
    }

    public function requireIssueReady(Quotation $r): void
    {
        if ($blockers = $this->blockers($r)) {
            throw ValidationException::withMessages(['readiness' => implode(' ', $blockers)]);
        }
    }

    public function guardOperation(int $actor, Quotation $r, string $operation, int $version): void
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, $operation);
        if ($operation === 'issue') {
            app(QuotationIssuance::class)->issue($actor, $r->id, $version);

            return;
        }
        app(QuotationActions::class)->version($r, $version);
        if (in_array($r->status, ['cancelled', 'rejected', 'expired'], true)) {
            throw ValidationException::withMessages(['readiness' => 'عرض السعر مغلق نهائيًا؛ يمكن إنشاء عرض جديد عن طريق النسخ.']);
        }
        $issued = DB::table('sales_quotation_issues')->where('quotation_id', $r->id)->exists();
        $reason = match ($operation) {
            'convert' => 'استخدم صفحة التحويل مع الفرع المصرح به؛ يلزم إصدار مقبول بإثبات مكتوب وكميات لم تحول سابقًا.',
            'accept' => $issued ? 'أدخل تاريخ ومرجع القبول وأرفق الإثبات المكتوب من صفحة قبول العميل.' : 'لا توجد نسخة صادرة يمكن ربط قبول العميل وإثباته بها.',
            'deliver' => $issued ? 'توثيق الإرسال اليدوي لم يكتمل بعد. تنزيل الملف لا يسجل إرساله.' : 'أصدر الملف الرسمي قبل تسجيل الإرسال.',
            'revise' => $issued ? 'الإصدار محفوظ وثابت؛ إنشاء مراجعة رسمية جديدة يحتاج مسار إعادة إصدار مستقلًا.' : 'لا توجد نسخة صادرة محفوظة لإنشاء مراجعة منها. يمكن متابعة تعديل المسودة.',
            default => implode(' ', $this->blockers($r)),
        };
        throw ValidationException::withMessages(['readiness' => $reason]);
    }
}

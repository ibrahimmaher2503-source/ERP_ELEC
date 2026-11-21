@php
    $actionLabels = [
        'job.created' => 'إنشاء أمر الإصلاح',
        'job.assigned' => 'إسناد الفني والورشة',
        'job.diagnosed' => 'تسجيل الفحص والتشخيص وخطة الاختبار',
        'job.start' => 'بدء تنفيذ الإصلاح',
        'job.waiting_parts' => 'تحويل الحالة إلى انتظار قطع الغيار',
        'job.resume' => 'استئناف تنفيذ الإصلاح',
        'job.hold' => 'إيقاف الإصلاح مؤقتًا',
        'job.testing' => 'تحويل المعدة إلى مرحلة الاختبار والجودة',
        'job.repair' => 'إعادة المعدة للإصلاح',
        'job.verified' => 'اعتماد التحقق الفني النهائي',
        'job.unrepairable' => 'تسجيل تعذر الإصلاح',
        'estimate.proposed' => 'إعداد وحفظ مراجعة تقدير / عرض الإصلاح',
        'quote.pricing_approved' => 'الاعتماد الداخلي لسعر عرض الإصلاح',
        'quote.issued' => 'إصدار عرض الإصلاح الرسمي',
        'quote.customer_decision' => 'توثيق قرار العميل على عرض الإصلاح',
        'contract.authorized' => 'اعتماد الإصلاح ضمن عقد الخدمة',
        'nonbillable.authorized' => 'اعتماد أساس الإصلاح غير المدفوع',
        'parts.requested' => 'طلب قطعة غيار لأمر الإصلاح',
        'parts.alternative' => 'اعتماد بديل قطعة غيار',
        'parts.alternative_approved' => 'اعتماد بديل قطعة غيار',
        'part.alternative_approved' => 'اعتماد بديل قطعة غيار',
        'parts.reserved' => 'حجز قطعة غيار من المستودع',
        'parts.issued' => 'صرف قطعة غيار لأمر الإصلاح',
        'parts.released' => 'فك حجز قطعة غيار',
        'parts.returned' => 'إرجاع قطعة غيار غير مستخدمة للمستودع',
        'parts.used' => 'تثبيت استخدام قطعة غيار في التنفيذ',
        'customer_part.recorded' => 'توثيق قطعة غيار واردة مع العميل',
        'work.recorded' => 'تسجيل أعمال ووقت التنفيذ',
        'test.recorded' => 'تسجيل نتيجة اختبار فحص',
        'equipment.delivered' => 'تسليم المعدة للعميل',
        'equipment.returned_unrepaired' => 'إعادة المعدة للعميل دون إصلاح',
        'labor.recognized' => 'الاعتراف المحاسبي بتكلفة العمل',
        'expense.linked' => 'ربط مصروف تشغيلي بأمر الإصلاح',
        'custody.dispatched' => 'إرسال عهدة المعدة للنقل بين الورش',
        'custody.transfer_received' => 'استلام عهدة المعدة في الورشة الوجهة',
        'custody.received' => 'تأكيد استلام عهدة المعدة',
        'custody.inspection' => 'إحالة العهدة للفحص',
        'custody.in_transit' => 'العهدة قيد النقل بين الورش',
        'custody.received_transfer' => 'استلام نقل العهدة',
        'custody.delivered' => 'تسليم العهدة للعميل',
        'custody.returned_unrepaired' => 'إعادة العهدة دون إصلاح',
        'intake.saved' => 'حفظ بيانات استلام المعدة',
        'document.uploaded' => 'إرفاق مستند أو صورة',
        'workshop.saved' => 'حفظ إعداد الورشة',
        'workshop.member' => 'تحديث عضوية فني بالورشة',
        'rate.proposed' => 'اقتراح معدل تكلفة ساعة عمل',
        'rate.approved' => 'اعتماد معدل تكلفة ساعة عمل',
        'deposit.collected' => 'تحصيل دفعة مقدمة (عربون) لأمر الإصلاح',
        'deposit.applied' => 'تطبيق عربون على فاتورة الإصلاح',
        'deposit.refunded' => 'رد عربون للعميل من الخزينة',
        'job.cancelled_with_fee' => 'إلغاء الإصلاح بعد التشخيص مع رسوم متفق عليها',
        'job.cancelled_after_diagnosis' => 'إلغاء الإصلاح بعد التشخيص',
    ];
    $statusLabels = [
        'inspection' => 'الفحص والتشخيص', 'waiting_approval' => 'بانتظار الموافقة', 'approved' => 'معتمد للتنفيذ',
        'rejected' => 'مرفوض / لإعادة المعدة', 'repair' => 'قيد الإصلاح', 'waiting_parts' => 'انتظار قطع الغيار',
        'on_hold' => 'موقوف مؤقتًا', 'testing' => 'الاختبار والجودة', 'verified' => 'تم التحقق الفني',
        'delivered' => 'تم التسليم', 'returned_unrepaired' => 'أعيدت دون إصلاح',
    ];
@endphp
<x-ui.panel>
    <flux:heading>التاريخ المحفوظ</flux:heading>
    @forelse($history as $e)
        @php
            $details = json_decode($e->details ?? '{}', true);
            $details = is_array($details) ? $details : [];
            $notes = [];
            if (array_key_exists('accepted', $details)) {
                $notes[] = $details['accepted'] ? 'قرار العميل: قبول العرض' : 'قرار العميل: رفض العرض';
            }
            if (! empty($details['status']) && isset($statusLabels[$details['status']])) {
                $notes[] = 'الحالة: '.$statusLabels[$details['status']];
            }
            if (! empty($details['minutes'])) {
                $notes[] = 'المدة: '.$details['minutes'].' دقيقة';
            }
            if (! empty($details['quantity'])) {
                $notes[] = 'الكمية: '.$details['quantity'];
            }
            if (! empty($details['outcome'])) {
                $notes[] = 'النتيجة: '.($details['outcome'] === 'passed' ? 'ناجح' : ($details['outcome'] === 'failed' ? 'فاشل' : $details['outcome']));
            }
            if (! empty($details['kind'])) {
                $notes[] = 'النوع: '.(['warranty' => 'ضمان', 'internal' => 'داخلي', 'goodwill' => 'مجاملة'][$details['kind']] ?? $details['kind']);
            }
            if (! empty($details['reason'])) {
                $notes[] = 'السبب: '.$details['reason'];
            }
            if (! empty($details['evidence'])) {
                $notes[] = 'الدليل: '.$details['evidence'];
            }
            if (! empty($details['reference'])) {
                $notes[] = 'المرجع: '.$details['reference'];
            }
            if (! empty($details['amount'])) {
                $notes[] = 'المبلغ: '.$details['amount'].' ج.م';
            }
            if (array_key_exists('fee_amount', $details) && $details['fee_amount'] !== null) {
                $notes[] = 'رسوم التشخيص/الإلغاء: '.$details['fee_amount'].' ج.م';
            }
            if (! empty($details['total_amount'])) {
                $notes[] = 'الإجمالي: '.$details['total_amount'].' ج.م';
            }
            if (! empty($details['tax_treatment'])) {
                $notes[] = 'الضريبة: '.\App\Modules\Accounting\Application\TaxCalculator::treatmentLabel($details['tax_treatment']);
            }
            if (! empty($details['tax_exempt_reason'])) {
                $notes[] = 'سبب الإعفاء: '.$details['tax_exempt_reason'];
            }
            if (! empty($details['self_approved'])) {
                $notes[] = 'اعتماد ذاتي موثّق';
            }
        @endphp
        <div class="border-b py-3 last:border-b-0">
            <strong>{{ $actionLabels[$e->action] ?? 'إجراء تشغيلي مسجّل' }}</strong>
            <p class="text-sm text-slate-500">
                <x-erp.user-name :id="$e->actor_id" /> · <bdi>{{ \Carbon\CarbonImmutable::parse($e->occurred_at)->setTimezone('Africa/Cairo')->format('Y-m-d H:i') }}</bdi>
                @if(! empty($details['version'])) · الإصدار {{ $details['version'] }} @endif
            </p>
            @if($notes !== [])
                <p class="mt-1 text-xs text-slate-600">{{ implode(' · ', $notes) }}</p>
            @endif
        </div>
    @empty
        <x-ui.empty-state title="لا يوجد تاريخ محفوظ" description="ستظهر الإجراءات المسجلة لهذا الأمر هنا." icon="clock" />
    @endforelse
</x-ui.panel>

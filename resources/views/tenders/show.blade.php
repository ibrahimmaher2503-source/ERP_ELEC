<div data-erp-archetype="{{ request()->routeIs('*.create', '*.edit') ? 'form' : 'details' }}" class="space-y-5">
<x-page.heading :title="$t?->number??'تسجيل مناقصة'" :description="$t?->title??'هوية واحدة للجهة والمرجع، مع مستندات ومراجعات متعددة'"><x-slot:actions><flux:button :href="route('tenders.index')" wire:navigate>سجل المناقصات</flux:button></x-slot:actions></x-page.heading>
<x-ui.validation-summary />
@if($t)<div class="flex flex-wrap items-center gap-2">@include('tenders.status',['status'=>$t->status])<span class="text-xs text-slate-500">الإصدار {{ $version }} · توقيت العرض Africa/Cairo</span></div>
<nav class="erp-section-nav flex flex-wrap gap-2" aria-label="أقسام المناقصة">@foreach(['details'=>'البيانات','technical'=>'الفني وBOQ','financial'=>'التسعير','approvals'=>'الاعتمادات','submission'=>'التقديم','award'=>'النتيجة والتحويل','guarantees'=>'الضمانات','documents'=>'المستندات','history'=>'التاريخ'] as $key=>$label)@if($canMoney || !in_array($key,['financial','submission','award','guarantees']))<flux:button size="sm" :variant="$tab===$key?'primary':'outline'" :href="route('tenders.show',['tender'=>$t->id,'tab'=>$key])" wire:navigate>{{ $label }}</flux:button>@endif @endforeach</nav>@endif
@if(!$t || $tab==='details')
@include('tenders.details')
@elseif($tab==='technical')
@include('tenders.technical')
@elseif($tab==='financial')
@include('tenders.financial')
@elseif($tab==='approvals')
<x-ui.panel><flux:heading>اعتماد النسخ الحالية</flux:heading><p class="my-3 text-sm">الفني #{{ $t->technical_id??'لم يعد' }} · المالي #{{ $canMoney?($t->financial_id??'لم يعد'):'محجوب' }}. الاعتماد مستقل عن مُعد النسخة.</p>@foreach($approvals as $approval)<p class="text-sm">{{ $approval->kind==='technical'?'فني':'مالي' }} #{{ $approval->technical_id??$approval->financial_id }} · المعتمد #{{ $approval->approved_by }} · {{ $approval->reference }}</p>@endforeach
<flux:textarea label="مرجع قرار الاعتماد" wire:model="reason"/>
<div class="mt-3 flex flex-wrap gap-2">@if($a->allows(auth()->id(),$branch,'technical_approve'))<flux:button wire:click="approveTechnical" variant="primary" wire:loading.attr="disabled">اعتماد فني</flux:button>@endif @if($canMoney && $a->allows(auth()->id(),$branch,'financial_approve'))<flux:button wire:click="approveFinancial" variant="primary" wire:loading.attr="disabled">اعتماد مالي</flux:button>@endif</div></x-ui.panel>
@elseif($tab==='submission')
<x-ui.panel><flux:heading>العرض الرسمي ودليل التقديم</flux:heading><p class="my-3 text-sm text-slate-600">إصدار المستند لا يغير الحالة إلى مقدمة. يلزم إيصال فعلي وموعد ساري واعتماد النسختين.</p>
@if($bidDocument)<flux:button :href="route('tenders.bid',['tender'=>$t->id,'financial'=>$t->financial_id])">PDF النسخة المالية الحالية</flux:button>@endif
@if($a->allows(auth()->id(),$branch,'submit'))<flux:button wire:click="issue" variant="primary" wire:loading.attr="disabled">إصدار العرض المعتمد</flux:button><form wire:submit="submitBid" class="mt-4 grid gap-3 md:grid-cols-2"><flux:select label="وسيلة التقديم" wire:model="submission.method"><option value="physical">تسليم ورقي</option><option value="email">بريد إلكتروني</option><option value="portal">بوابة الجهة</option></flux:select><flux:input type="datetime-local" label="وقت التقديم بالقاهرة" wire:model="submission.submitted_at"/><flux:input label="رقم الإيصال أو مرجع الرسالة" wire:model="submission.reference"/><flux:select label="دليل التقديم المرفوع" wire:model="submission.evidence_id"><option value="">اختر الدليل</option>@foreach($documents->where('classification','submission') as $doc)<option value="{{ $doc->id }}">{{ $doc->title }}</option>@endforeach</flux:select><flux:button type="submit" variant="primary" wire:loading.attr="disabled">تسجيل التقديم الفعلي</flux:button></form>@endif
@foreach($submissions as $sub)<div class="mt-3 border-t border-blue-100 pt-3 text-sm">تقديم #{{ $sub->id }} · {{ $sub->method }} · {{ $sub->reference }} · {{ \Carbon\CarbonImmutable::parse($sub->submitted_at)->tz('Africa/Cairo')->format('Y-m-d H:i P') }}<a class="ms-2 text-blue-700 underline" href="{{ route('tenders.bid',['tender'=>$t->id,'financial'=>$sub->financial_id]) }}">PDF النسخة المقدمة</a></div>@endforeach</x-ui.panel>
@elseif($tab==='award')
@include('tenders.award')
@elseif($tab==='guarantees')
@include('tenders.guarantees')
@elseif($tab==='documents')
<x-ui.panel><flux:heading>المستندات الخاصة</flux:heading><div class="my-4 space-y-2">@forelse($documents as $doc)<p class="text-sm">{{ $doc->title }} · {{ $doc->classification }} · {{ $doc->uploaded_at }} @if($a->allows(auth()->id(),$branch,'document_view'))<a class="ms-2 text-blue-700 underline" href="{{ route('tenders.document',$doc->id) }}">تنزيل</a>@endif</p>@empty<p class="text-sm text-slate-500">لم ترفع مستندات.</p>@endforelse</div>
@if($a->allows(auth()->id(),$branch,'manage'))<form wire:submit="saveAttachment" class="grid gap-3 md:grid-cols-2"><flux:input label="عنوان المستند" wire:model="uploadTitle"/><flux:select label="التصنيف" wire:model="uploadClassification">@foreach(['general'=>'عام','technical'=>'فني','financial'=>'مالي','submission'=>'إيصال التقديم','award'=>'النتيجة','guarantee'=>'ضمان','addendum'=>'ملحق'] as $key=>$label)@if($canMoney || !in_array($key,['financial','submission','award','guarantee']))<option value="{{ $key }}">{{ $label }}</option>@endif @endforeach</flux:select><flux:input type="file" label="PDF أو صورة حتى 10MB" wire:model="file"/><flux:button type="submit" variant="primary" wire:loading.attr="disabled">حفظ المستند</flux:button></form>@endif</x-ui.panel>
@elseif($tab==='history')
<x-ui.panel><flux:heading>تاريخ المناقصة</flux:heading>@foreach($events as $event)<div class="border-b border-blue-100 py-3 text-sm"><p>{{ $event->action }} · مستخدم #{{ $event->actor_id }} · {{ \Carbon\CarbonImmutable::parse($event->occurred_at)->tz('Africa/Cairo')->format('Y-m-d H:i P') }}</p>@if($canMoney || !preg_match('/financial|guarantee|handoff|result|bid\./',$event->action))<p class="break-words text-xs text-slate-500">{{ json_encode(json_decode($event->snapshot,true),JSON_UNESCAPED_UNICODE) }}</p>@endif</div>@endforeach</x-ui.panel>
@endif
</div>

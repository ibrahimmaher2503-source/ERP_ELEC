<x-ui.panel>
    <flux:heading>الاستهلاك والعمل الفعلي والمقبوض والمسترد</flux:heading>
    <div class="mt-3 overflow-x-auto"><table class="w-full text-start text-sm">
        <thead class="bg-blue-50"><tr>@foreach(['أمر الإصلاح','الدقائق الفعلية / المخططة','استهلاك القطع حسب الصنف','مرات استلام المعدة','التكلفة المدعومة','المقبوض','المسترد'] as $label)<th class="p-3 text-start">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>@foreach($rows as $job)
            @php
                $actual = app(\App\Modules\WorkshopRepairs\Application\WorkshopReports::class)->execution(auth()->id(), $job->id);
                $jobLedger = $access->billingHandoff(auth()->id(), $job->branch_id) ? app(\App\Modules\WorkshopRepairs\Application\WorkshopFinance::class)->ledger(auth()->id(), $job->id) : ['available' => false, 'totals' => null];
            @endphp
            <tr class="border-b">
                <td class="p-3">{{ $job->number }}</td>
                <td class="p-3">{{ $actual['minutes'] }} / {{ $job->estimated_minutes ?? 'غير محددة' }}</td>
                <td class="p-3">{{ $actual['parts'] ?: 'لا يوجد استهلاك مسجل' }}</td>
                <td class="p-3">{{ $actual['repeat_intakes'] ?? 'هوية غير محددة' }}</td>
                <td class="p-3">@if($access->allows(auth()->id(),$job->branch_id,'cost_view')){{ app(\App\Modules\WorkshopRepairs\Application\WorkshopFinance::class)->costs(auth()->id(),$job->id)['supported_total'] }} EGP @else غير مصرح @endif</td>
                <td class="p-3 tabular-nums">@if($jobLedger['available']){{ $jobLedger['totals']['total_collected'] }} EGP @else — @endif</td>
                <td class="p-3 tabular-nums">@if($jobLedger['available']){{ $jobLedger['totals']['total_refunded'] }} EGP @else — @endif</td>
            </tr>
        @endforeach</tbody>
    </table></div>
</x-ui.panel>

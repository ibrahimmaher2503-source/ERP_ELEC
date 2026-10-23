<x-page.heading :title="$heading ?? 'تفاصيل عرض السعر'" :description="$record->label().' · مراجعة '.$record->revision" :breadcrumbs="['المبيعات','عروض الأسعار',$heading??'تفاصيل العرض']">
 <x-slot:actions>
  <flux:button :href="route('quotations.index')" wire:navigate icon="arrow-right">عروض الأسعار</flux:button>
  @if($can('export'))<flux:button wire:click="export" icon="arrow-down-tray">تصدير Excel</flux:button>@endif
  @if($record->editable()&&$can('edit'))<flux:button variant="primary" :href="route('quotations.edit',$record->id)" wire:navigate icon="pencil-square">تعديل المسودة</flux:button>@endif
 </x-slot:actions>
</x-page.heading>
@include('sales.quotations.partials.feedback')

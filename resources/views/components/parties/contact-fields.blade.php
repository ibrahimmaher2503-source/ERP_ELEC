@props(['required' => true])
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    @foreach(['name'=>'الاسم','job_title'=>'المسمى الوظيفي','phone'=>'الهاتف','mobile'=>'الجوال','email'=>'البريد الإلكتروني'] as $field=>$label)
        <flux:input wire:model.live="contact.{{ $field }}" :label="$label" :required="$required && $field === 'name'" :type="$field === 'email' ? 'email' : (in_array($field,['phone','mobile']) ? 'tel' : 'text')" />
    @endforeach
</div>

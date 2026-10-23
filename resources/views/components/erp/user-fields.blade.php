@props(['branches', 'creating' => false])

<flux:input wire:model="form.name" label="الاسم الكامل" required maxlength="200" />
<flux:input wire:model="form.email" type="email" label="البريد الإلكتروني" required maxlength="255" />
<flux:select wire:model="form.gender" label="النوع" required><option value="">اختر النوع</option><option value="male">ذكر</option><option value="female">أنثى</option></flux:select>
<flux:select wire:model="form.branch_id" label="الفرع"><option value="">بدون فرع رئيسي</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
@if ($creating)
    <flux:input wire:model="form.password" type="password" label="كلمة المرور" description="12 حرفًا على الأقل، مع حرف كبير وحرف صغير ورقم." required minlength="12" autocomplete="new-password" />
    <flux:input wire:model="form.password_confirmation" type="password" label="تأكيد كلمة المرور" required minlength="12" autocomplete="new-password" />
@endif

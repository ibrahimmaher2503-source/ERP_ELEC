@if ($errors->any())
    <div {{ $attributes->class('erp-validation-summary erp-feedback--danger') }} data-erp-validation role="alert" tabindex="-1">
        <h2 class="font-semibold">{{ app()->getLocale() === 'ar' ? 'راجع البيانات التالية لإكمال العملية' : 'Review these details to continue' }}</h2>
        <ul class="mt-2 space-y-1">
            @foreach ($errors->getMessages() as $field => $messages)
                @foreach ($messages as $message)
                    <li><button type="button" data-error-field="{{ $field }}" class="text-start underline underline-offset-4">{{ $message }}</button></li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif

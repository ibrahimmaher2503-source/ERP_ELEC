<x-layouts::auth :title="'إعداد المدير الأول'">
    <div class="flex flex-col gap-6">
        @if ($created)
            <div class="space-y-4 text-center">
                <x-auth-header title="تم إنشاء المدير الأول" description="تم إغلاق رابط الإعداد نهائيًا." />
                <flux:callout variant="success">يمكنك الآن تسجيل الدخول بالبريد وكلمة المرور التي أدخلتها. لا تعيد استخدام رابط الإعداد.</flux:callout>
                <flux:button variant="primary" class="w-full" href="{{ route('login') }}" wire:navigate>الانتقال إلى تسجيل الدخول</flux:button>
            </div>
        @else
            <x-auth-header title="إعداد المدير الأول" description="هذه الصفحة المؤقتة تُستخدم مرة واحدة فقط لإنشاء حساب مدير النظام." />

            <flux:callout icon="shield-check">
                أدخل بيانات الحساب الأول. سيتم تأكيد البريد تلقائيًا، ومنح الحساب دور مدير النظام، ثم إغلاق هذا الرابط فورًا.
            </flux:callout>

            <form method="POST" action="{{ route('one-time-admin.store', ['token' => $token]) }}" class="flex flex-col gap-5">
                @csrf
                <flux:input name="name" label="اسم المدير" :value="old('name')" required autofocus autocomplete="name" />
                <flux:input name="email" label="البريد الإلكتروني" :value="old('email')" type="email" required autocomplete="username" />
                <flux:input name="password" label="كلمة المرور" type="password" required minlength="12" autocomplete="new-password" viewable />
                <flux:input name="password_confirmation" label="تأكيد كلمة المرور" type="password" required minlength="12" autocomplete="new-password" viewable />
                @if ($errors->any())
                    <div class="space-y-1 text-sm text-red-600" role="alert">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
                <flux:button variant="primary" type="submit" class="w-full">إنشاء حساب المدير وإغلاق الرابط</flux:button>
            </form>
        @endif
    </div>
</x-layouts::auth>

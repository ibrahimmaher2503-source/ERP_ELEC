<?php

use App\Concerns\ProfileValidationRules;

use Illuminate\Contracts\Auth\MustVerifyEmail;

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public string $gender = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->gender = Auth::user()->gender ?? '';
    }

    public function updateGender(): void
    {
        app(\App\Modules\Organization\Application\UpdateOwnGender::class)->execute(Auth::user(), $this->gender);
        $this->redirect(route('profile.edit'), navigate: true);
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $validated) {
            $guard = app(\App\Modules\Organization\Application\RbacGuard::class);
            $guard->lock();
            $current = \App\Models\User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->active, 403);
            $previousEmail = $current->email;
            $before = ['name' => $current->name, 'email' => $previousEmail];
            $current->fill($validated);
            $emailChanged = $current->isDirty('email');
            if ($emailChanged) {
                $current->email_verified_at = null;
            }
            $current->version++;
            $current->save();
            if (app(\App\Modules\Organization\Application\PermissionAccess::class)->securityDesignated($current->id)) {
                $guard->assertAdministratorRemains();
            }
            if ($emailChanged) {
                \Illuminate\Support\Facades\DB::table('password_reset_tokens')->where('email', $previousEmail)->delete();
                app(\App\Modules\Organization\Application\RevokeUserSessions::class)->execute($current->id);
            }
            event(new \App\Modules\Organization\Domain\MasterChanged($current->id, 'user', $current->id, 'self_profile_updated', $before, ['name' => $current->name, 'email' => $current->email]));
        });

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }

    
    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }


    
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                
                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
                
            </div>

            <flux:error name="assignment" />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

            </div>
        </form>
        <form wire:submit="updateGender" class="mb-6 space-y-4 border-t border-slate-100 pt-6">
            <flux:select wire:model="gender" label="النوع" description="تُختار الصورة الافتراضية بناءً على النوع." required><option value="">اختر النوع</option><option value="male">ذكر</option><option value="female">أنثى</option></flux:select>
            <flux:button type="submit" class="erp-action-positive" wire:loading.attr="disabled">حفظ النوع والصورة الافتراضية</flux:button>
        </form>

        
    </x-pages::settings.layout>
</section>

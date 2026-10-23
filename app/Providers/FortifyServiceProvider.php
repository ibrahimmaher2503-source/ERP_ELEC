<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::authenticateUsing(function (Request $request) {
            if (! is_string($request->input('email')) || ! is_string($request->input('password'))) {
                return null;
            }
            $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($request->input('email')))])->first();

            return $user && $user->active && Hash::check($request->input('password'), $user->password) ? $user : null;
        });
        Event::listen(Login::class, function (Login $event) {
            $account = DB::table('users')->where('id', $event->user->getAuthIdentifier())->first(['active', 'session_version']);
            if (! $account?->active) {
                Auth::logout();
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            DB::transaction(function () use ($event) {
                $id = (int) $event->user->getAuthIdentifier();
                DB::table('users')->where('id', $id)->update(['last_login_at' => now()]);
                event(new MasterChanged($id, 'user', $id, 'login_succeeded', null, []));
            });
            if (request()->hasSession()) {
                request()->session()->put('organization_auth_version', $account->session_version);
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            $user = $event->user;
            if (! $user && is_string($event->credentials['email'] ?? null)) {
                $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($event->credentials['email']))])->first();
            }
            if ($user) {
                event(new MasterChanged(null, 'user', (int) $user->getAuthIdentifier(), 'login_failed', null, [], 'failed'));
            }
        });
        Event::listen(PasswordResetLinkSent::class, function (PasswordResetLinkSent $event) {
            event(new MasterChanged(null, 'user', (int) $event->user->getAuthIdentifier(), 'password_reset_link_sent', null, []));
        });

    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));

        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));

        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));

        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

    }
}

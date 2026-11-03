<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;

final class MonitoringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Pulse::filter(fn ($entry): bool => in_array($entry->type, ['slow_request', 'slow_query', 'exception'], true));

        Gate::define('viewPulse', function (?User $user = null): bool {
            if (! config('pulse.enabled') || ! $user?->active || ! $user->hasVerifiedEmail()) {
                return false;
            }

            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($user->id, 'M01.security_admin'));

                return true;
            } catch (ContractException) {
                return false;
            }
        });
    }
}

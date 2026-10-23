<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\UserActivityReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserSecurityHistory
{
    public function forUser(User $actor, int $user): Collection
    {
        app(RbacGuard::class)->authorize($actor);
        app(UserAccess::class)->authorize($actor, 'M01.user_view', User::findOrFail($user));
        $events = app(UserActivityReader::class)->forUser($user);
        $names = DB::table('users')->whereIn('id', $events->pluck('actor_id')->filter())->pluck('name', 'id');

        return $events->map(fn ($event) => $event + ['actor' => $event['actor_id'] ? ($names[$event['actor_id']] ?? 'حساب غير متاح') : 'فاعل غير موثّق (طلب عام)']);
    }
}

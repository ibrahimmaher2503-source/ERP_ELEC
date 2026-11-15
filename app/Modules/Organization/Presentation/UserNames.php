<?php

namespace App\Modules\Organization\Presentation;

use Illuminate\Support\Facades\DB;

/** Display-only lookup so history lists show who acted instead of a bare id. One query per user per request. */
final class UserNames
{
    /** @var array<int,string> */
    private static array $cache = [];

    public static function name(int|string|null $id): string
    {
        if ($id === null || $id === '' || (int) $id <= 0) {
            return 'النظام';
        }
        $id = (int) $id;

        return self::$cache[$id] ??= (string) (DB::table('users')->where('id', $id)->value('name') ?: 'مستخدم محذوف');
    }
}

<?php

namespace App\Support;

class DefaultAvatar
{
    public static function url(int $userId, ?string $gender = null): string
    {
        $pool = match ($gender) {
            'male' => [1, 3, 5, 7, 9, 11, 13, 15],
            'female' => [2, 4, 6, 8, 10, 12, 14],
            default => [],
        };
        if ($pool === []) {
            return asset('images/avatars/neutral.svg');
        }
        $number = str_pad((string) $pool[($userId - 1) % count($pool)], 2, '0', STR_PAD_LEFT);

        return asset("images/avatars/avatar-{$number}.webp");
    }
}

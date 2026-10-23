<?php

namespace App\Modules\Organization\Domain;

use Illuminate\Support\Collection;

interface UserActivityReader
{
    /** Internal read contract: caller must authorize security history for this user. */
    public function forUser(int $user): Collection;
}

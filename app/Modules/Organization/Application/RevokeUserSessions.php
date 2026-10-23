<?php

namespace App\Modules\Organization\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevokeUserSessions
{
    public function execute(int $id): void
    {
        DB::table('sessions')->where('user_id', $id)->delete();
        DB::table('users')->where('id', $id)->update(['remember_token' => Str::random(60), 'session_version' => DB::raw('session_version + 1')]);
    }
}

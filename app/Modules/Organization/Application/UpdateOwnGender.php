<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UpdateOwnGender
{
    public function execute(User $actor, string $gender): void
    {
        DB::transaction(function () use ($actor, $gender) {
            $current = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            abort_unless($current?->active, 403);
            Validator::make(['gender' => $gender], ['gender' => ['required', Rule::in(['male', 'female'])]])->validate();
            DB::table('users')->where('id', $actor->id)->update(['gender' => $gender, 'version' => $current->version + 1, 'updated_at' => now()]);
            event(new MasterChanged($actor->id, 'user', $actor->id, 'own_gender_updated', ['gender' => $current->gender], ['gender' => $gender]));
        });
    }
}

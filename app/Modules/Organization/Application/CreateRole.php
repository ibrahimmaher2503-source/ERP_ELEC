<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateRole
{
    public function execute(User $actor, string $name): int
    {
        return DB::transaction(function () use ($actor, $name) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            $name = Str::squish($name);
            Validator::make(['roleName' => $name], ['roleName' => ['required', 'string', 'max:200', 'regex:/^[^\x00-\x1F\x7F]+$/u']], [], ['roleName' => 'اسم الدور'])->validate();
            if (DB::table('roles')->whereRaw('lower(name) = lower(?) OR lower(code) = lower(?)', [$name, $name])->exists()) {
                throw ValidationException::withMessages(['roleName' => 'يوجد دور بهذا الاسم أو الكود. اختر اسمًا مختلفًا.']);
            }
            $code = 'Custom_'.Str::ulid();
            $id = DB::table('roles')->insertGetId(['code' => $code, 'name' => $name, 'protected' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $guard->audit($actor, $actor->id, 'role.created', [], ['role_id' => $id, 'code' => $code, 'name' => $name, 'permissions' => []]);

            return $id;
        });
    }
}

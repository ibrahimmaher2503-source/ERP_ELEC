<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveBranch
{
    public function execute(User $actor, array $input, ?int $id = null, ?int $expectedVersion = null): object
    {
        return DB::transaction(function () use ($actor, $input, $id, $expectedVersion) {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            $access = app(OrganizationAccess::class);
            $id === null ? $access->authorize($actor, 'M01.branch_create') : $access->authorizeBranch($actor, 'M01.branch_update', $id);
            $company = DB::table('companies')->where('id', 1)->lockForUpdate()->first();
            if (! $company?->setup_complete) {
                throw ValidationException::withMessages(['form.name' => 'أكمل بيانات الشركة أولًا.']);
            }
            $current = $id === null ? null : DB::table('branches')->where('id', $id)->lockForUpdate()->first();
            abort_if($id !== null && ! $current, 404);
            if ($current && $current->version !== $expectedVersion) {
                throw ValidationException::withMessages(['form.name' => 'تغير الفرع. أعد فتح النموذج.']);
            }
            if (is_string($input['code'] ?? null)) {
                $input['code'] = \Normalizer::normalize(mb_strtoupper(trim($input['code'])), \Normalizer::FORM_C);
            }
            if (is_string($input['name'] ?? null)) {
                $input['name'] = trim($input['name']);
            }
            $data = Validator::make($input, [
                'code' => ['required', 'string', 'max:50', 'regex:/^[^\x00-\x1F\x7F]+$/u', Rule::unique('branches', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'address' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/u'],
            ])->validate();
            $data += ['version' => ($current?->version ?? 0) + 1, 'updated_at' => now()];
            if ($current) {
                DB::table('branches')->where('id', $id)->update($data);
            } else {
                $id = DB::table('branches')->insertGetId($data + ['company_id' => 1, 'active' => true, 'created_at' => now()]);
            }
            $saved = DB::table('branches')->where('id', $id)->first();
            event(new MasterChanged($actor->id, 'branch', $id, $current ? 'updated' : 'created', $current ? (array) $current : null, (array) $saved));

            return $saved;
        });
    }
}

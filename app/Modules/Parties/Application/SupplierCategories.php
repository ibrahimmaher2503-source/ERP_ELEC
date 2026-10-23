<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\Parties\Domain\SupplierCategoryChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierCategories
{
    public function save(int $actorId, array $input, ?int $id = null): SupplierCategory
    {
        app(SupplierAccess::class)->authorize($actorId, 'M03.supplier_category_manage');

        return DB::transaction(function () use ($actorId, $input, $id) {
            DB::select('SELECT pg_advisory_xact_lock(30312)');
            $r = $id === null ? new SupplierCategory : SupplierCategory::lockForUpdate()->findOrFail($id);
            $input['code'] = mb_strtoupper(trim((string) ($input['code'] ?? '')));
            $data = Validator::make($input, ['code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_.-]*$/', Rule::unique('supplier_categories', 'code')->ignore($id)], 'name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'active' => ['required', 'boolean']])->validate();
            if ($id !== null && $r->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'كود التصنيف ثابت.']);
            }
            $before = $r->exists ? $r->only(['code', 'name', 'active']) : null;
            $r->fill($data);
            $r->save();
            event(new SupplierCategoryChanged($actorId, $r->id, $before, $r->only(['code', 'name', 'active'])));

            return $r;
        });
    }
}

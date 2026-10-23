<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\ProductChanged;
use App\Modules\Products\Domain\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductMasters
{
    public function save(int $actorId, string $kind, array $input, ?int $id = null, ?int $version = null): Model
    {
        return DB::transaction(function () use ($actorId, $kind, $input, $id, $version) {
            abort_unless(in_array($kind, ['category', 'brand', 'unit'], true), 404);
            DB::select('SELECT pg_advisory_xact_lock(20401)');
            app(ProductAccess::class)->authorize($actorId, 'M02.'.$kind.'_manage');
            $class = match ($kind) {
                'category' => Category::class, 'brand' => Brand::class, 'unit' => Unit::class
            };
            $record = $id === null ? new $class : $class::lockForUpdate()->findOrFail($id);
            if ($id !== null && $record->version !== $version) {
                throw ValidationException::withMessages(['name' => 'تغيرت البيانات. أعد فتح النموذج.']);
            }
            $input['code'] = is_string($input['code'] ?? null) ? \Normalizer::normalize(mb_strtoupper(trim($input['code'])), \Normalizer::FORM_C) : null;
            $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : null;
            $rules = ['code' => ['required', 'string', 'max:50', 'regex:/^[^\x00-\x1F\x7F]+$/u', Rule::unique($record->getTable(), 'code')->ignore($id)], 'name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'active' => ['required', 'boolean']];
            if ($kind === 'category') {
                $rules['parent_id'] = ['nullable', 'integer', Rule::exists('product_categories', 'id')];
            }
            if ($kind === 'unit') {
                $rules['dimension'] = ['required', Rule::in(['count', 'length', 'mass', 'volume', 'time'])];
            }
            $data = Validator::make($input, $rules)->validate();
            if ($kind === 'brand' && Brand::whereRaw('lower(btrim(name)) = lower(?)', [$data['name']])->when($id !== null, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'العلامة التجارية مسجلة بهذا الاسم.']);
            }
            if ($kind === 'category') {
                $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
                $visited = [];
                while ($parent !== null) {
                    if ($parent === $id || in_array($parent, $visited, true)) {
                        throw ValidationException::withMessages(['parent_id' => 'لا يمكن إنشاء حلقة في التصنيفات.']);
                    }
                    $visited[] = $parent;
                    $ancestor = Category::findOrFail($parent);
                    if (! $ancestor->active && $record->parent_id !== $parent) {
                        throw ValidationException::withMessages(['parent_id' => 'التصنيف الأب غير نشط.']);
                    }
                    $parent = $ancestor->parent_id;
                }
            }
            if ($kind === 'unit' && $id !== null && $data['dimension'] !== $record->dimension) {
                // Dimension is stable because conversions and future source snapshots depend on it.
                throw ValidationException::withMessages(['dimension' => 'بُعد الوحدة ثابت؛ أنشئ وحدة مستقلة بدل تغييره.']);
            }
            $before = $id === null ? null : $record->toArray();
            $record->fill($data);
            $record->version = ($version ?? 0) + 1;
            $record->save();
            event(new ProductChanged($actorId, $kind, $record->id, $before ? 'updated' : 'created', $before, $record->toArray()));

            return $record;
        });
    }
}

<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductChanged;
use App\Modules\Products\Domain\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductActions
{
    public function saveWithMedia(int $actorId, array $input, ?int $id, ?int $version, array $conversions, array $attributes, array $uploads): Product
    {
        app(ProductAccess::class)->authorize($actorId, $id === null ? 'M02.product_create' : 'M02.product_update');
        if ($uploads !== []) {
            app(ProductAccess::class)->authorize($actorId, 'M02.media_manage');
            app(ProductAccess::class)->authorize($actorId, 'M02.product_view');
        }
        Validator::make(['uploads' => $uploads], ['uploads' => ['array', 'max:20'], 'uploads.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf']])->validate();
        $stored = [];
        try {
            return DB::transaction(function () use ($actorId, $input, $id, $version, $conversions, $attributes, $uploads, &$stored) {
                $product = $this->save($actorId, $input, $id, $version, $conversions, $attributes);
                foreach ($uploads as $upload) {
                    $media = app(ProductMediaActions::class)->upload($actorId, $product->id, $upload);
                    $stored[] = $media->path;
                }

                return $product;
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function save(int $actorId, array $input, ?int $id = null, ?int $version = null, array $conversions = [], array $attributes = [], bool $validateOnly = false): Product
    {
        return DB::transaction(function () use ($actorId, $input, $id, $version, $conversions, $attributes, $validateOnly) {
            DB::select('SELECT pg_advisory_xact_lock(20401)');
            $access = app(ProductAccess::class);
            $access->authorize($actorId, $id === null ? 'M02.product_create' : 'M02.product_update');
            $product = $id === null ? new Product : Product::lockForUpdate()->findOrFail($id);
            if ($id !== null && $product->version !== $version) {
                throw ValidationException::withMessages(['sku' => 'تغير الصنف. أعد تحميل الصفحة قبل الحفظ.']);
            }
            foreach (['sku', 'barcode', 'name'] as $key) {
                if (is_string($input[$key] ?? null)) {
                    $input[$key] = \Normalizer::normalize(trim($input[$key]), \Normalizer::FORM_C);
                }
            }
            if (is_string($input['sku'] ?? null)) {
                $input['sku'] = mb_strtoupper($input['sku']);
            }
            foreach (['barcode', 'category_id', 'brand_id', 'purchase_unit_id', 'sales_unit_id'] as $key) {
                if (($input[$key] ?? null) === '') {
                    $input[$key] = null;
                }
            }
            $data = Validator::make($input, [
                'sku' => ['required', 'string', 'max:50', 'regex:/^[^\x00-\x1F\x7F]+$/u', Rule::unique('products', 'sku')->ignore($id)],
                'name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'name_en' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/u'],
                'short_description' => ['nullable', 'string', 'max:500', 'not_regex:/\x00/u'], 'description' => ['nullable', 'string', 'max:10000', 'not_regex:/\x00/u'],
                'kind' => ['required', Rule::in(['serialized_equipment', 'bulk', 'service'])], 'tracking' => ['required', Rule::in(['serial', 'quantity', 'none'])],
                'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')], 'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
                'model' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/u'], 'base_unit_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')],
                'barcode' => ['nullable', 'string', 'max:100', 'regex:/^[\x21-\x7E]+$/', Rule::unique('products', 'barcode')->ignore($id)], 'active' => ['required', 'boolean'],
                'internal_notes' => ['sometimes', 'nullable', 'string', 'max:10000', 'not_regex:/\x00/u'],
                'purchase_unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')],
                'sales_unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')],
            ])->validate();
            if ($id === null && ! (bool) $data['active']) {
                $access->authorize($actorId, 'M02.product_status');
            }
            if ($id !== null) {
                foreach (['sku', 'base_unit_id', 'kind', 'tracking', 'purchase_unit_id', 'sales_unit_id'] as $stable) {
                    if ((string) ($data[$stable] ?? null) !== (string) $product->$stable) {
                        throw ValidationException::withMessages([$stable => 'هذا التعريف ثابت لحماية المراجع؛ أنشئ صنفًا مستقلًا.']);
                    }
                }
                if ((bool) $data['active'] !== (bool) $product->active) {
                    $access->authorize($actorId, 'M02.product_status');
                }
            }
            if (array_key_exists('internal_notes', $data)) {
                $access->authorize($actorId, 'M02.notes_update');
            }
            foreach (['category_id' => Category::class, 'brand_id' => Brand::class, 'base_unit_id' => Unit::class] as $field => $class) {
                if (($data[$field] ?? null) !== null && ! $class::findOrFail($data[$field])->active && (string) $product->$field !== (string) $data[$field]) {
                    throw ValidationException::withMessages([$field => 'لا يمكن اختيار مرجع غير نشط.']);
                }
            }
            Validator::make(['conversions' => $conversions, 'attributes' => $attributes], [
                'conversions' => ['array', 'max:30'], 'conversions.*.unit_id' => ['required', 'integer', 'distinct', Rule::exists('units_of_measure', 'id')],
                'conversions.*.factor' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/'],
                'attributes' => ['array', 'max:100'], 'attributes.*.name' => ['required', 'string', 'max:100', 'distinct', 'not_regex:/\x00/u'],
                'attributes.*.value' => ['required', 'string', 'max:1000', 'not_regex:/\x00/u'], 'attributes.*.unit_id' => ['nullable', 'integer', Rule::exists('units_of_measure', 'id')],
            ])->validate();
            $base = Unit::findOrFail($data['base_unit_id']);
            $normalized = [];
            foreach ($conversions as $index => $conversion) {
                $unit = Unit::findOrFail($conversion['unit_id']);
                if ($unit->id === $base->id || $unit->dimension !== $base->dimension || (! $unit->active && $id === null) || bccomp($conversion['factor'], '0', 8) <= 0) {
                    throw ValidationException::withMessages(['conversions.'.$index.'.factor' => 'يجب اختيار وحدة نشطة من نفس البُعد ومعامل موجب إلى الوحدة الأساسية.']);
                }
                $normalized[(int) $unit->id] = bcadd($conversion['factor'], '0', 8);
            }
            ksort($normalized);
            foreach (['purchase_unit_id', 'sales_unit_id'] as $field) {
                $unitId = isset($data[$field]) ? (int) $data[$field] : null;
                if ($unitId !== null && $unitId !== $base->id && ! array_key_exists($unitId, $normalized)) {
                    throw ValidationException::withMessages([$field => 'عرّف تحويلًا صريحًا إلى الوحدة الأساسية أولًا.']);
                }
            }
            if ($id !== null) {
                $existing = $product->conversions()->orderBy('unit_id')->pluck('factor', 'unit_id')->all();
                if ($existing !== $normalized) {
                    throw ValidationException::withMessages(['conversions' => 'تعريف التحويلات المحفوظ ثابت لحماية الكميات التاريخية.']);
                }
            }
            $before = $id === null ? null : $product->toArray();
            $product->fill($data);
            $product->attributes = array_map(fn ($row) => ['name' => $row['name'], 'value' => $row['value'], 'unit_id' => empty($row['unit_id']) ? null : (int) $row['unit_id']], $attributes);
            if ($validateOnly) {
                return $product;
            }
            $product->updated_by = $actorId;
            $product->version = ($version ?? 0) + 1;
            if ($id === null) {
                $product->created_by = $actorId;
            }
            $product->save();
            if ($id === null) {
                foreach ($normalized as $unitId => $factor) {
                    $product->conversions()->create(['unit_id' => $unitId, 'factor' => $factor]);
                }
            }
            event(new ProductChanged($actorId, 'product', $product->id, $before ? 'updated' : 'created', $before, $product->toArray()));

            return $product;
        });
    }

    public function changeStatus(int $actorId, int $id, int $version): Product
    {
        return DB::transaction(function () use ($actorId, $id, $version) {
            DB::select('SELECT pg_advisory_xact_lock(20401)');
            app(ProductAccess::class)->authorize($actorId, 'M02.product_status');
            $product = Product::lockForUpdate()->findOrFail($id);
            if ($product->version !== $version) {
                throw ValidationException::withMessages(['status' => 'تغير الصنف. حدّث الصفحة.']);
            }
            $before = ['active' => $product->active, 'version' => $product->version];
            $product->active = ! $product->active;
            $product->version++;
            $product->updated_by = $actorId;
            $product->save();
            event(new ProductChanged($actorId, 'product', $id, 'status_changed', $before, ['active' => $product->active, 'version' => $product->version]));

            return $product;
        });
    }
}

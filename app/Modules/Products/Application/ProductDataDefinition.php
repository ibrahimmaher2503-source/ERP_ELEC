<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\System\Application\DataOperations\EntityDefinition;
use Illuminate\Validation\ValidationException;

class ProductDataDefinition implements EntityDefinition
{
    public function id(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'الأصناف والمنتجات';
    }

    public function version(): string
    {
        return 'M02-products-v1';
    }

    public function authorize(int $actorId, string $capability): void
    {
        $key = match ($capability) {
            'bulk' => 'product_bulk_update','import' => 'product_import','export' => 'product_export','template' => 'product_template',default => null
        };
        abort_unless($key, 403);
        $access = app(ProductAccess::class);
        $access->authorize($actorId, 'M02.product_view');
        $access->authorize($actorId, 'M02.'.$key);
    }

    public function columns(): array
    {
        $fields = [
            'sku' => ['رمز الصنف', true, 'SW-001', 'المفتاح الثابت؛ لا يتم تغييره'],
            'name' => ['اسم الصنف بالعربية', true, 'مفتاح كهربائي مفرد', 'مطلوب عند الإنشاء'],
            'name_en' => ['اسم الصنف بالإنجليزية', false, 'Single switch', 'نص اختياري'],
            'kind' => ['نوع الصنف', true, 'bulk', 'serialized_equipment / bulk / service'],
            'tracking' => ['سياسة التتبع', true, 'quantity', 'serial / quantity / none؛ اختيار صريح'],
            'category_code' => ['كود التصنيف', false, '', 'استخدم كودًا من Lookups'],
            'brand_code' => ['كود العلامة', false, '', 'استخدم كودًا من Lookups'],
            'base_unit_code' => ['كود الوحدة الأساسية', true, '', 'مطلوب؛ استخدم كودًا من Lookups'],
            'barcode' => ['الباركود', false, '', 'نص اختياري وفريد؛ احتفظ بالأصفار كنص'],
            'model' => ['الموديل', false, '', 'نص اختياري'],
            'short_description' => ['وصف مختصر', false, '', 'حتى 500 حرف'],
            'description' => ['الوصف', false, '', 'نص اختياري'],
            'active' => ['الحالة', false, '1', '1 نشط / 0 غير نشط'],
        ];
        $result = [];
        foreach ($fields as $key => [$label,$required,$example,$description]) {
            $allowed = match ($key) {
                'kind' => ['serialized_equipment', 'bulk', 'service'],'tracking' => ['serial', 'quantity', 'none'],'active' => ['1', '0'],default => []
            };
            $result[] = ['key' => $key, 'label' => $label, 'required' => $required, 'type' => 'text', 'allowed' => $allowed, 'example' => $example, 'description' => $description.'؛ في التحديث الخلية الفارغة تبقي القيمة، و#CLEAR يمسح الحقول الاختيارية القابلة للمسح فقط.'];
        }

        return $result;
    }

    public function lookups(int $actorId): array
    {
        $this->authorize($actorId, 'template');

        return ['category_code' => Category::where('active', true)->orderBy('code')->get(['code', 'name'])->toArray(), 'brand_code' => Brand::where('active', true)->orderBy('code')->get(['code', 'name'])->toArray(), 'base_unit_code' => Unit::where('active', true)->orderBy('code')->get(['code', 'name'])->toArray()];
    }

    public function bulkFields(): array
    {
        return ['active' => ['label' => 'الحالة', 'clear' => false, 'type' => 'status'], 'category_id' => ['label' => 'التصنيف', 'clear' => true, 'type' => 'category'], 'brand_id' => ['label' => 'العلامة التجارية', 'clear' => true, 'type' => 'brand'], 'model' => ['label' => 'الموديل', 'clear' => true, 'type' => 'text'], 'short_description' => ['label' => 'الوصف المختصر', 'clear' => true, 'type' => 'text'], 'description' => ['label' => 'الوصف', 'clear' => true, 'type' => 'text']];
    }

    private function input(Product $product): array
    {
        return $product->only(['sku', 'name', 'name_en', 'kind', 'tracking', 'category_id', 'brand_id', 'model', 'base_unit_id', 'purchase_unit_id', 'sales_unit_id', 'barcode', 'active', 'short_description', 'description']);
    }

    private function validatedPlan(int $actorId, array $input, ?Product $product, string $operation): array
    {
        $conversions = $product ? $product->conversions->map(fn ($row) => ['unit_id' => $row->unit_id, 'factor' => $row->factor])->all() : [];
        $attributes = $product?->getAttribute('attributes') ?? [];
        $draft = app(ProductActions::class)->save($actorId, $input, $product?->id, $product?->version, $conversions, $attributes, validateOnly: true);
        $before = $product ? $this->input($product) : [];
        $after = $this->input($draft);

        return ['identifier' => $input['sku'], 'target_id' => $product?->id, 'version' => $product?->version, 'operation' => $operation, 'input' => $input, 'conversions' => $conversions, 'attributes' => $attributes, 'before' => $before, 'after' => $after, 'skip' => $product && $before === $after];
    }

    public function previewBulk(int $actorId, int $id, array $changes): array
    {
        $this->authorize($actorId, 'bulk');
        $product = Product::with('conversions')->findOrFail($id);
        $input = $this->input($product);
        $touched = false;
        foreach ($changes as $field => $change) {
            $definition = $this->bulkFields()[$field] ?? null;
            if (! $definition || ! is_array($change) || array_diff(array_keys($change), ['mode', 'value'])) {
                throw ValidationException::withMessages(['changes' => 'حقل تعديل جماعي غير مسموح.']);
            }
            $mode = $change['mode'] ?? 'keep';
            if ($mode === 'keep') {
                continue;
            }
            $touched = true;
            if ($mode === 'clear' && $definition['clear']) {
                $input[$field] = null;
            } elseif ($mode === 'set' && array_key_exists('value', $change) && $change['value'] !== '' && $change['value'] !== null) {
                $input[$field] = $change['value'];
            } else {
                throw ValidationException::withMessages(['changes' => 'حدد قيمة صريحة، أو اختر إبقاء القيمة/مسحها.']);
            }
        }
        if (! $touched) {
            throw ValidationException::withMessages(['changes' => 'اختر حقلًا لتعديله أولًا.']);
        }

        return $this->validatedPlan($actorId, $input, $product, 'update');
    }

    public function previewImport(int $actorId, array $row, string $mode): array
    {
        $this->authorize($actorId, 'import');
        abort_unless(in_array($mode, ['create', 'update'], true), 422);
        if (array_diff(array_keys($row), array_column($this->columns(), 'key'))) {
            throw ValidationException::withMessages(['row' => 'أعمدة غير مسموحة.']);
        }
        $sku = is_string($row['sku'] ?? null) ? \Normalizer::normalize(mb_strtoupper(trim($row['sku'])), \Normalizer::FORM_C) : '';
        $product = Product::with('conversions')->where('sku', $sku)->first();
        if ($mode === 'create' && $product) {
            throw ValidationException::withMessages(['sku' => 'رمز الصنف مستخدم بالفعل.']);
        }
        if ($mode === 'update' && ! $product) {
            throw ValidationException::withMessages(['sku' => 'رمز الصنف غير موجود للتحديث.']);
        }
        $input = $product ? $this->input($product) : ['sku' => $sku, 'name' => '', 'kind' => '', 'tracking' => '', 'base_unit_id' => null, 'category_id' => null, 'brand_id' => null, 'barcode' => null, 'active' => true, 'purchase_unit_id' => null, 'sales_unit_id' => null];
        foreach ($row as $field => $value) {
            if ($field === 'sku') {
                continue;
            }
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                continue;
            }
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (! is_string($value)) {
                throw ValidationException::withMessages([$field => 'يجب إدخال القيم كنصوص وفق القالب.']);
            }
            $clear = $value === '#CLEAR';
            if ($clear && ! in_array($field, ['name_en', 'category_code', 'brand_code', 'barcode', 'model', 'short_description', 'description'], true)) {
                throw ValidationException::withMessages([$field => 'هذا الحقل لا يقبل المسح.']);
            }
            if (in_array($field, ['category_code', 'brand_code', 'base_unit_code'], true)) {
                [$class,$target] = match ($field) {
                    'category_code' => [Category::class, 'category_id'],'brand_code' => [Brand::class, 'brand_id'],default => [Unit::class, 'base_unit_id']
                };
                $reference = $clear ? null : $class::where('code', \Normalizer::normalize(mb_strtoupper(trim($value)), \Normalizer::FORM_C))->where('active', true)->first();
                if (! $clear && ! $reference) {
                    throw ValidationException::withMessages([$field => 'كود المرجع غير موجود أو غير نشط.']);
                }
                $input[$target] = $reference?->id;
            } else {
                if ($field === 'active' && ! in_array($value, ['0', '1'], true)) {
                    throw ValidationException::withMessages(['active' => 'الحالة تقبل 0 أو 1 فقط.']);
                }
                $input[$field] = $clear ? null : ($field === 'active' ? $value === '1' : $value);
            }
        }

        return $this->validatedPlan($actorId, $input, $product, $mode === 'create' ? 'create' : 'update');
    }

    public function execute(int $actorId, array $plan): array
    {
        // Plans are persisted server-side; normal Actions still revalidate every field and version.
        $product = app(ProductActions::class)->save($actorId, $plan['input'], $plan['target_id'], $plan['version'], $plan['conversions'], $plan['attributes']);

        return ['id' => $product->id, 'sku' => $product->sku, 'operation' => $plan['operation']];
    }

    public function duplicateKeys(array $row): array
    {
        $keys = ['sku' => \Normalizer::normalize(mb_strtoupper(trim((string) ($row['sku'] ?? ''))), \Normalizer::FORM_C)];
        $barcode = trim((string) ($row['barcode'] ?? ''));
        if ($barcode !== '' && $barcode !== '#CLEAR') {
            $keys['barcode'] = $barcode;
        }

        return $keys;
    }

    public function exportRows(int $actorId, string $mode, array $filters, array $ids): iterable
    {
        $this->authorize($actorId, 'export');
        abort_unless(in_array($mode, ['all', 'filtered', 'selected'], true), 422);
        $query = app(ProductCatalogQuery::class)->query($actorId, $mode === 'filtered' ? $filters : [])->with(['category', 'brand', 'baseUnit']);
        if ($mode === 'selected') {
            abort_unless($ids !== [], 422);
            $query->whereIn('id', $ids);
        }
        foreach ($query->lazyById(200) as $product) {
            $this->authorize($actorId, 'export');
            yield ['sku' => $product->sku, 'name' => $product->name, 'name_en' => $product->name_en, 'kind' => $product->kind, 'tracking' => $product->tracking, 'category_code' => $product->category?->code, 'brand_code' => $product->brand?->code, 'base_unit_code' => $product->baseUnit?->code, 'barcode' => $product->barcode, 'model' => $product->model, 'short_description' => $product->short_description, 'description' => $product->description, 'active' => $product->active ? '1' : '0'];
        }
    }
}

<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\System\Application\DataOperations\EntityDefinition;
use Illuminate\Validation\ValidationException;

class SupplierDataDefinition implements EntityDefinition
{
    public function id(): string
    {
        return 'suppliers';
    }

    public function label(): string
    {
        return 'الموردين';
    }

    public function version(): string
    {
        return 'M03-suppliers-v1';
    }

    public function authorize(int $actorId, string $capability): void
    {
        $key = match ($capability) {
            'bulk' => 'supplier_bulk_update','import' => 'supplier_import','export' => 'supplier_export','template' => 'supplier_template',default => null
        };
        abort_unless($key, 403);
        $a = app(SupplierAccess::class);
        $a->authorize($actorId, 'M03.supplier_view');
        $a->authorize($actorId, 'M03.'.$key);
    }

    public function columns(): array
    {
        $labels = ['code' => 'كود المورد', 'name' => 'الاسم بالعربية', 'name_en' => 'الاسم بالإنجليزية', 'trading_name' => 'الاسم التجاري', 'entity_type' => 'الكيان القانوني', 'supplier_type' => 'نوع المورد', 'category_code' => 'كود التصنيف', 'status' => 'الحالة', 'registration_number' => 'رقم التسجيل', 'tax_number' => 'الرقم الضريبي', 'country' => 'الدولة', 'city' => 'المدينة', 'phone' => 'الهاتف', 'mobile' => 'الجوال', 'email' => 'البريد الإلكتروني', 'website' => 'الموقع الإلكتروني'];
        $out = [];
        foreach ($labels as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'required' => in_array($key, ['code', 'name', 'entity_type', 'supplier_type', 'status']), 'type' => 'text', 'allowed' => match ($key) {
                'entity_type' => ['individual', 'organization'],'supplier_type' => ['manufacturer', 'distributor', 'service_provider', 'other'],'status' => ['active', 'inactive', 'archived'],default => []
            }, 'example' => match ($key) {
                'code' => 'C-EXAMPLE-001','entity_type' => 'organization','supplier_type' => 'manufacturer','status' => 'active',default => ''
            }, 'description' => 'الفراغ يبقي القيمة في التحديث، و#CLEAR يمسح الاختياري فقط. الكود ثابت.'];
        }

        return $out;
    }

    public function lookups(int $actorId): array
    {
        $this->authorize($actorId, 'template');

        return ['category_code' => SupplierCategory::where('active', true)->orderBy('code')->get(['code', 'name'])->toArray()];
    }

    public function bulkFields(): array
    {
        return ['status' => ['label' => 'حالة المورد', 'clear' => false, 'type' => 'status'], 'category_id' => ['label' => 'تصنيف المورد', 'clear' => true, 'type' => 'supplier_category'], 'supplier_type' => ['label' => 'نوع المورد', 'clear' => false, 'type' => 'supplier_type']];
    }

    private function plan(int $actor, array $input, ?Supplier $r, string $operation): array
    {
        $draft = app(SupplierActions::class)->save($actor, $input, $r?->id, $r?->version, validateOnly: true);
        $before = $r?->only(SupplierActions::FIELDS) ?? [];
        $after = $draft->only(SupplierActions::FIELDS);

        return ['identifier' => $draft->code, 'target_id' => $r?->id, 'version' => $r?->version, 'operation' => $operation, 'input' => $input, 'before' => $before, 'after' => $after, 'skip' => $r && $before === $after];
    }

    public function previewBulk(int $actorId, int $id, array $changes): array
    {
        $this->authorize($actorId, 'bulk');
        $r = Supplier::findOrFail($id);
        $input = $r->only(SupplierActions::FIELDS);
        $touched = false;
        foreach ($changes as $field => $change) {
            $def = $this->bulkFields()[$field] ?? null;
            if (! $def || ! is_array($change) || array_diff(array_keys($change), ['mode', 'value'])) {
                throw ValidationException::withMessages(['changes' => 'حقل تعديل غير مسموح.']);
            }
            $mode = $change['mode'] ?? 'keep';
            if ($mode === 'keep') {
                continue;
            } $touched = true;
            if ($mode === 'clear' && $def['clear']) {
                $input[$field] = null;
            } elseif ($mode === 'set' && isset($change['value']) && $change['value'] !== '') {
                $input[$field] = $change['value'];
            } else {
                throw ValidationException::withMessages(['changes' => 'اختر قيمة صريحة أو إبقاء/مسح.']);
            }
        }
        if (! $touched) {
            throw ValidationException::withMessages(['changes' => 'اختر تغييرًا أولًا.']);
        }

        return $this->plan($actorId, $input, $r, 'update');
    }

    public function previewImport(int $actorId, array $row, string $mode): array
    {
        $this->authorize($actorId, 'import');
        abort_unless(in_array($mode, ['create', 'update'], true), 422);
        if (array_diff(array_keys($row), array_column($this->columns(), 'key'))) {
            throw ValidationException::withMessages(['row' => 'أعمدة غير مسموحة.']);
        }
        $code = mb_strtoupper(trim((string) ($row['code'] ?? '')));
        $r = Supplier::where('code', $code)->first();
        if (($mode === 'create' && $r) || ($mode === 'update' && ! $r)) {
            throw ValidationException::withMessages(['code' => $r ? 'كود المورد مستخدم بالفعل.' : 'كود المورد غير موجود للتحديث.']);
        }
        $input = $r?->only(SupplierActions::FIELDS) ?? array_fill_keys(SupplierActions::FIELDS, null);
        $input['code'] = $code;
        foreach ($row as $field => $value) {
            if ($field === 'code' || $value === null || $value === '') {
                continue;
            }
            if (! is_scalar($value)) {
                throw ValidationException::withMessages([$field => 'استخدم نصًا في الخلية.']);
            }
            if ($field === 'category_code') {
                if ($value === '#CLEAR') {
                    $input['category_id'] = null;

                    continue;
                }
                $category = SupplierCategory::where('code', mb_strtoupper(trim((string) $value)))->where('active', true)->first();
                if (! $category) {
                    throw ValidationException::withMessages(['category_code' => 'كود التصنيف غير موجود أو غير نشط.']);
                }
                $input['category_id'] = $category->id;

                continue;
            }
            if ($value === '#CLEAR') {
                if (in_array($field, ['code', 'name', 'entity_type', 'supplier_type', 'status'])) {
                    throw ValidationException::withMessages([$field => 'حقل مطلوب لا يقبل المسح.']);
                }
                $input[$field] = null;
            } else {
                $input[$field] = (string) $value;
            }
        }

        return $this->plan($actorId, $input, $r, $mode);
    }

    public function duplicateKeys(array $row): array
    {
        return ['code' => mb_strtoupper(trim((string) ($row['code'] ?? '')))];
    }

    public function execute(int $actorId, array $plan): array
    {
        $r = app(SupplierActions::class)->save($actorId, $plan['input'], $plan['target_id'], $plan['version']);

        return ['id' => $r->id, 'code' => $r->code, 'operation' => $plan['operation']];
    }

    public function exportRows(int $actorId, string $mode, array $filters, array $ids): iterable
    {
        $this->authorize($actorId, 'export');
        abort_unless(in_array($mode, ['all', 'selected', 'filtered'], true), 422);
        $q = app(SupplierCatalogQuery::class)->query($actorId, $mode === 'filtered' ? $filters : [])->with('category');
        if ($mode === 'selected') {
            abort_unless($ids !== [], 422);
            $q->whereIn('id', $ids);
        }
        foreach ($q->lazyById(200) as $r) {
            $this->authorize($actorId, 'export');
            $row = $r->only(SupplierActions::FIELDS);
            unset($row['category_id']);
            $row['category_code'] = $r->category?->code;
            yield $row;
        }
    }
}

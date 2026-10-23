<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Customer;
use App\Modules\System\Application\DataOperations\EntityDefinition;
use Illuminate\Validation\ValidationException;

class CustomerDataDefinition implements EntityDefinition
{
    public function id(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'العملاء';
    }

    public function version(): string
    {
        return 'M03-customers-v1';
    }

    public function authorize(int $actorId, string $capability): void
    {
        $key = match ($capability) {
            'bulk' => 'customer_bulk_update','import' => 'customer_import','export' => 'customer_export','template' => 'customer_template',default => null
        };
        abort_unless($key, 403);
        $a = app(CustomerAccess::class);
        $a->authorize($actorId, 'M03.customer_view');
        $a->authorize($actorId, 'M03.'.$key);
    }

    public function columns(): array
    {
        $labels = ['code' => 'كود العميل', 'name' => 'الاسم بالعربية', 'name_en' => 'الاسم بالإنجليزية', 'trading_name' => 'الاسم التجاري', 'entity_type' => 'نوع العميل', 'segment' => 'شريحة العميل', 'status' => 'الحالة', 'registration_number' => 'رقم التسجيل', 'tax_number' => 'الرقم الضريبي', 'country' => 'الدولة', 'city' => 'المدينة', 'phone' => 'الهاتف', 'mobile' => 'الجوال', 'email' => 'البريد الإلكتروني', 'website' => 'الموقع الإلكتروني'];
        $out = [];
        foreach ($labels as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'required' => in_array($key, ['code', 'name', 'entity_type', 'status']), 'type' => 'text', 'allowed' => match ($key) {
                'entity_type' => ['individual', 'organization'],'status' => ['active', 'inactive', 'archived'],default => []
            }, 'example' => match ($key) {
                'code' => 'C-EXAMPLE-001','entity_type' => 'organization','status' => 'active',default => ''
            }, 'description' => 'الفراغ يبقي القيمة في التحديث، و#CLEAR يمسح الاختياري فقط. الكود ثابت.'];
        }

        return $out;
    }

    public function lookups(int $actorId): array
    {
        $this->authorize($actorId, 'template');

        return [];
    }

    public function bulkFields(): array
    {
        return ['status' => ['label' => 'حالة العميل', 'clear' => false, 'type' => 'customer_status'], 'segment' => ['label' => 'شريحة العميل', 'clear' => true, 'type' => 'text'], 'city' => ['label' => 'المدينة', 'clear' => true, 'type' => 'text'], 'country' => ['label' => 'الدولة', 'clear' => true, 'type' => 'text']];
    }

    private function plan(int $actor, array $input, ?Customer $r, string $operation): array
    {
        $draft = app(CustomerActions::class)->save($actor, $input, $r?->id, $r?->version, validateOnly: true);
        $before = $r?->only(CustomerActions::FIELDS) ?? [];
        $after = $draft->only(CustomerActions::FIELDS);

        return ['identifier' => $draft->code, 'target_id' => $r?->id, 'version' => $r?->version, 'operation' => $operation, 'input' => $input, 'before' => $before, 'after' => $after, 'skip' => $r && $before === $after];
    }

    public function previewBulk(int $actorId, int $id, array $changes): array
    {
        $this->authorize($actorId, 'bulk');
        $r = Customer::findOrFail($id);
        $input = $r->only(CustomerActions::FIELDS);
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
        if (array_diff(array_keys($row), CustomerActions::FIELDS)) {
            throw ValidationException::withMessages(['row' => 'أعمدة غير مسموحة.']);
        }
        $code = mb_strtoupper(trim((string) ($row['code'] ?? '')));
        $r = Customer::where('code', $code)->first();
        if (($mode === 'create' && $r) || ($mode === 'update' && ! $r)) {
            throw ValidationException::withMessages(['code' => $r ? 'كود العميل مستخدم بالفعل.' : 'كود العميل غير موجود للتحديث.']);
        }
        $input = $r?->only(CustomerActions::FIELDS) ?? array_fill_keys(CustomerActions::FIELDS, null);
        $input['code'] = $code;
        foreach ($row as $field => $value) {
            if ($field === 'code' || $value === null || $value === '') {
                continue;
            }
            if (! is_scalar($value)) {
                throw ValidationException::withMessages([$field => 'استخدم نصًا في الخلية.']);
            }
            if ($value === '#CLEAR') {
                if (in_array($field, ['code', 'name', 'entity_type', 'status'])) {
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
        $r = app(CustomerActions::class)->save($actorId, $plan['input'], $plan['target_id'], $plan['version']);

        return ['id' => $r->id, 'code' => $r->code, 'operation' => $plan['operation']];
    }

    public function exportRows(int $actorId, string $mode, array $filters, array $ids): iterable
    {
        $this->authorize($actorId, 'export');
        abort_unless(in_array($mode, ['all', 'selected', 'filtered'], true), 422);
        $q = app(CustomerCatalogQuery::class)->query($actorId, $mode === 'filtered' ? $filters : []);
        if ($mode === 'selected') {
            abort_unless($ids !== [], 422);
            $q->whereIn('id', $ids);
        }
        foreach ($q->lazyById(200) as $r) {
            $this->authorize($actorId, 'export');
            yield $r->only(CustomerActions::FIELDS);
        }
    }
}

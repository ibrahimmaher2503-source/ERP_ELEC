<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\Parties\Domain\SupplierChanged;
use App\Modules\Parties\Domain\SupplierContact;
use App\Modules\Parties\Domain\SupplierDocument;
use App\Modules\Parties\Domain\SupplierNote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupplierActions
{
    public const FIELDS = ['code', 'name', 'name_en', 'trading_name', 'entity_type', 'category_id', 'supplier_type', 'status', 'registration_number', 'tax_number', 'country', 'city', 'phone', 'mobile', 'email', 'website', 'payment_terms'];

    private function access(int $actor, string $permission): void
    {
        app(SupplierAccess::class)->authorize($actor, 'M03.supplier_view');
        app(SupplierAccess::class)->authorize($actor, 'M03.'.(str_starts_with($permission, 'supplier_') ? $permission : 'supplier_'.$permission));
    }

    public function save(int $actorId, array $input, ?int $id = null, ?int $version = null, bool $validateOnly = false): Supplier
    {
        return DB::transaction(function () use ($actorId, $input, $id, $version, $validateOnly) {
            $this->access($actorId, $id === null ? 'supplier_create' : 'supplier_update');
            DB::select('SELECT pg_advisory_xact_lock(30311)');
            $record = $id === null ? new Supplier : Supplier::lockForUpdate()->findOrFail($id);
            if ($id !== null && $record->version !== $version) {
                throw ValidationException::withMessages(['code' => 'تغير السجل. أعد تحميل الصفحة قبل الحفظ.']);
            }
            foreach ($input as $k => $v) {
                if (is_string($v)) {
                    $input[$k] = \Normalizer::normalize(trim($v), \Normalizer::FORM_C);
                }
            }
            $input['code'] = mb_strtoupper($input['code'] ?? '');
            if (($input['category_id'] ?? null) === '') {
                $input['category_id'] = null;
            }
            $rules = ['code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_.-]*$/', Rule::unique('suppliers', 'code')->ignore($id)], 'name' => ['required', 'string', 'max:200'], 'entity_type' => ['required', Rule::in(['individual', 'organization'])], 'status' => ['required', Rule::in(['active', 'inactive', 'archived'])], 'email' => ['nullable', 'email', 'max:255'], 'website' => ['nullable', 'url:http,https', 'max:255']];
            $rules['category_id'] = ['nullable', 'integer', Rule::exists('supplier_categories', 'id')];
            $rules['supplier_type'] = ['required', Rule::in(['manufacturer', 'distributor', 'service_provider', 'other'])];
            foreach (array_diff(self::FIELDS, array_keys($rules)) as $f) {
                $rules[$f] = ['nullable', 'string', 'max:'.(in_array($f, ['name_en', 'trading_name']) ? 200 : 100)];
            }
            foreach ($rules as &$rule) {
                $rule[] = 'not_regex:/\x00/u';
            } unset($rule);
            $data = Validator::make($input, $rules)->validate();
            if (! empty($data['category_id']) && (int) $data['category_id'] !== (int) $record->category_id && ! SupplierCategory::findOrFail($data['category_id'])->active) {
                throw ValidationException::withMessages(['category_id' => 'التصنيف غير نشط.']);
            }
            if ($id !== null && $record->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'كود المورد ثابت ولا يمكن تغييره.']);
            }
            if (($id !== null && $record->status !== $data['status']) || ($id === null && $data['status'] !== 'active')) {
                $this->access($actorId, 'supplier_status');
            }
            $before = $id === null ? null : $record->only(['code', 'name', 'status', 'version']);
            $record->fill($data);
            if ($validateOnly) {
                return $record;
            }
            $record->version = ($id === null ? 0 : $record->version) + 1;
            $record->save();
            event(new SupplierChanged($actorId, $record->id, $id === null ? 'created' : 'updated', $before, $record->only(['code', 'name', 'status', 'version'])));

            return $record;
        });
    }

    public function changeStatus(int $actorId, int $id, int $version, string $status): Supplier
    {
        $this->access($actorId, 'supplier_status');

        return DB::transaction(function () use ($actorId, $id, $version, $status) {
            $r = Supplier::lockForUpdate()->findOrFail($id);
            Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'inactive', 'archived'])]])->validate();
            if ($r->version !== $version) {
                throw ValidationException::withMessages(['status' => 'تغير المورد. أعد التحميل.']);
            }
            $before = $r->only(['status', 'version']);
            $r->status = $status;
            $r->version++;
            $r->save();
            event(new SupplierChanged($actorId, $id, 'status_changed', $before, $r->only(['status', 'version'])));

            return $r;
        });
    }

    public function saveContact(int $actorId, int $supplierId, array $input, ?int $id = null): SupplierContact
    {
        $this->access($actorId, 'contact_manage');

        return DB::transaction(function () use ($actorId, $supplierId, $input, $id) {
            Supplier::lockForUpdate()->findOrFail($supplierId);
            $r = $id === null ? new SupplierContact : SupplierContact::where('supplier_id', $supplierId)->lockForUpdate()->findOrFail($id);
            $data = Validator::make($input, ['name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'job_title' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/u'], 'phone' => ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'], 'mobile' => ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'], 'email' => ['nullable', 'email', 'max:255', 'not_regex:/\x00/u'], 'active' => ['required', 'boolean'], 'is_primary' => ['required', 'boolean']])->validate();
            if ($data['is_primary'] && ! $data['active']) {
                throw ValidationException::withMessages(['is_primary' => 'جهة الاتصال الرئيسية يجب أن تكون نشطة.']);
            }
            if ($data['is_primary']) {
                SupplierContact::where('supplier_id', $supplierId)->where('is_primary', true)->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->update(['is_primary' => false]);
            }
            $r->fill($data);
            $r->supplier_id = $supplierId;
            $r->save();
            event(new SupplierChanged($actorId, $supplierId, 'contact_saved', null, ['contact_id' => $r->id, 'is_primary' => $r->is_primary, 'active' => $r->active]));

            return $r;
        });
    }

    public function saveAddress(int $actorId, int $supplierId, array $input, ?int $id = null): SupplierAddress
    {
        $this->access($actorId, 'address_manage');

        return DB::transaction(function () use ($actorId, $supplierId, $input, $id) {
            Supplier::lockForUpdate()->findOrFail($supplierId);
            $r = $id === null ? new SupplierAddress : SupplierAddress::where('supplier_id', $supplierId)->lockForUpdate()->findOrFail($id);
            $rules = ['label' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'purpose' => ['required', Rule::in(['registered', 'office', 'pickup', 'other'])], 'line' => ['required', 'string', 'max:2000', 'not_regex:/\x00/u'], 'is_primary' => ['required', 'boolean']];
            foreach (['country', 'city', 'region', 'postal_code'] as $f) {
                $rules[$f] = ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'];
            }
            $data = Validator::make($input, $rules)->validate();
            if ($data['is_primary']) {
                SupplierAddress::where('supplier_id', $supplierId)->where('purpose', $data['purpose'])->where('is_primary', true)->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->update(['is_primary' => false]);
            }
            $r->fill($data);
            $r->supplier_id = $supplierId;
            $r->save();
            event(new SupplierChanged($actorId, $supplierId, 'address_saved', null, ['address_id' => $r->id, 'purpose' => $r->purpose, 'is_primary' => $r->is_primary]));

            return $r;
        });
    }

    public function addNote(int $actorId, int $supplierId, string $body): SupplierNote
    {
        $this->access($actorId, 'notes_update');
        $this->access($actorId, 'notes_view');
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:10000', 'not_regex:/\x00/u']])->validate();

        return DB::transaction(function () use ($actorId, $supplierId, $body) {
            Supplier::findOrFail($supplierId);
            $r = SupplierNote::create(['supplier_id' => $supplierId, 'body' => $body, 'author_id' => $actorId]);
            event(new SupplierChanged($actorId, $supplierId, 'note_added', null, ['note_id' => $r->id]));

            return $r;
        });
    }

    public function upload(int $actorId, int $supplierId, UploadedFile $file): SupplierDocument
    {
        $this->access($actorId, 'document_manage');
        Supplier::findOrFail($supplierId);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png']])->validate();
        $path = $file->store('suppliers/'.$supplierId, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'تعذر حفظ الملف.']);
        }
        try {
            return DB::transaction(function () use ($actorId, $supplierId, $file, $path) {
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200);
                $r = SupplierDocument::create(['supplier_id' => $supplierId, 'name' => $name, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize()]);
                event(new SupplierChanged($actorId, $supplierId, 'document_added', null, ['document_id' => $r->id, 'mime' => $r->mime]));

                return $r;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}

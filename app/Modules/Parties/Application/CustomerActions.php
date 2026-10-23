<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use App\Modules\Parties\Domain\CustomerChanged;
use App\Modules\Parties\Domain\CustomerContact;
use App\Modules\Parties\Domain\CustomerDocument;
use App\Modules\Parties\Domain\CustomerNote;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerActions
{
    public const FIELDS = ['code', 'name', 'name_en', 'trading_name', 'entity_type', 'segment', 'status', 'registration_number', 'tax_number', 'country', 'city', 'phone', 'mobile', 'email', 'website'];

    private function access(int $actor, string $permission): void
    {
        app(CustomerAccess::class)->authorize($actor, 'M03.customer_view');
        app(CustomerAccess::class)->authorize($actor, 'M03.'.$permission);
    }

    public function save(int $actorId, array $input, ?int $id = null, ?int $version = null, bool $validateOnly = false): Customer
    {
        return DB::transaction(function () use ($actorId, $input, $id, $version, $validateOnly) {
            $this->access($actorId, $id === null ? 'customer_create' : 'customer_update');
            DB::select('SELECT pg_advisory_xact_lock(30301)');
            $record = $id === null ? new Customer : Customer::lockForUpdate()->findOrFail($id);
            if ($id !== null && $record->version !== $version) {
                throw ValidationException::withMessages(['code' => 'تغير السجل. أعد تحميل الصفحة قبل الحفظ.']);
            }
            foreach ($input as $k => $v) {
                if (is_string($v)) {
                    $input[$k] = \Normalizer::normalize(trim($v), \Normalizer::FORM_C);
                }
            }
            $input['code'] = mb_strtoupper($input['code'] ?? '');
            $rules = ['code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_.-]*$/', Rule::unique('customers', 'code')->ignore($id)], 'name' => ['required', 'string', 'max:200'], 'entity_type' => ['required', Rule::in(['individual', 'organization'])], 'status' => ['required', Rule::in(['active', 'inactive', 'archived'])], 'email' => ['nullable', 'email', 'max:255'], 'website' => ['nullable', 'url:http,https', 'max:255']];
            foreach (array_diff(self::FIELDS, array_keys($rules)) as $f) {
                $rules[$f] = ['nullable', 'string', 'max:'.(in_array($f, ['name_en', 'trading_name']) ? 200 : 100)];
            }
            foreach ($rules as &$rule) {
                $rule[] = 'not_regex:/\x00/u';
            } unset($rule);
            $data = Validator::make($input, $rules)->validate();
            if ($id !== null && $record->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'كود العميل ثابت ولا يمكن تغييره.']);
            }
            if (($id !== null && $record->status !== $data['status']) || ($id === null && $data['status'] !== 'active')) {
                $this->access($actorId, 'customer_status');
            }
            $before = $id === null ? null : $record->only(['code', 'name', 'status', 'version']);
            $record->fill($data);
            if ($validateOnly) {
                return $record;
            }
            $record->version = ($id === null ? 0 : $record->version) + 1;
            $record->save();
            event(new CustomerChanged($actorId, $record->id, $id === null ? 'created' : 'updated', $before, $record->only(['code', 'name', 'status', 'version'])));

            return $record;
        });
    }

    public function changeStatus(int $actorId, int $id, int $version, string $status): Customer
    {
        $this->access($actorId, 'customer_status');

        return DB::transaction(function () use ($actorId, $id, $version, $status) {
            $r = Customer::lockForUpdate()->findOrFail($id);
            Validator::make(['status' => $status], ['status' => ['required', Rule::in(['active', 'inactive', 'archived'])]])->validate();
            if ($r->version !== $version) {
                throw ValidationException::withMessages(['status' => 'تغير العميل. أعد التحميل.']);
            }
            $before = $r->only(['status', 'version']);
            $r->status = $status;
            $r->version++;
            $r->save();
            event(new CustomerChanged($actorId, $id, 'status_changed', $before, $r->only(['status', 'version'])));

            return $r;
        });
    }

    public function saveContact(int $actorId, int $customerId, array $input, ?int $id = null): CustomerContact
    {
        $this->access($actorId, 'contact_manage');

        return DB::transaction(function () use ($actorId, $customerId, $input, $id) {
            Customer::lockForUpdate()->findOrFail($customerId);
            $r = $id === null ? new CustomerContact : CustomerContact::where('customer_id', $customerId)->lockForUpdate()->findOrFail($id);
            $data = Validator::make($input, ['name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'job_title' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/u'], 'phone' => ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'], 'mobile' => ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'], 'email' => ['nullable', 'email', 'max:255', 'not_regex:/\x00/u'], 'active' => ['required', 'boolean'], 'is_primary' => ['required', 'boolean']])->validate();
            if ($data['is_primary'] && ! $data['active']) {
                throw ValidationException::withMessages(['is_primary' => 'جهة الاتصال الرئيسية يجب أن تكون نشطة.']);
            }
            if ($data['is_primary']) {
                CustomerContact::where('customer_id', $customerId)->where('is_primary', true)->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->update(['is_primary' => false]);
            }
            $r->fill($data);
            $r->customer_id = $customerId;
            $r->save();
            event(new CustomerChanged($actorId, $customerId, 'contact_saved', null, ['contact_id' => $r->id, 'is_primary' => $r->is_primary, 'active' => $r->active]));

            return $r;
        });
    }

    public function saveAddress(int $actorId, int $customerId, array $input, ?int $id = null): CustomerAddress
    {
        $this->access($actorId, 'address_manage');

        return DB::transaction(function () use ($actorId, $customerId, $input, $id) {
            Customer::lockForUpdate()->findOrFail($customerId);
            $r = $id === null ? new CustomerAddress : CustomerAddress::where('customer_id', $customerId)->lockForUpdate()->findOrFail($id);
            $rules = ['label' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'purpose' => ['required', Rule::in(['billing', 'shipping', 'other'])], 'line' => ['required', 'string', 'max:2000', 'not_regex:/\x00/u'], 'is_primary' => ['required', 'boolean']];
            foreach (['country', 'city', 'region', 'postal_code'] as $f) {
                $rules[$f] = ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'];
            }
            $data = Validator::make($input, $rules)->validate();
            if ($data['is_primary']) {
                CustomerAddress::where('customer_id', $customerId)->where('purpose', $data['purpose'])->where('is_primary', true)->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->update(['is_primary' => false]);
            }
            $r->fill($data);
            $r->customer_id = $customerId;
            $r->save();
            event(new CustomerChanged($actorId, $customerId, 'address_saved', null, ['address_id' => $r->id, 'purpose' => $r->purpose, 'is_primary' => $r->is_primary]));

            return $r;
        });
    }

    public function addNote(int $actorId, int $customerId, string $body): CustomerNote
    {
        $this->access($actorId, 'notes_update');
        $this->access($actorId, 'notes_view');
        Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:10000', 'not_regex:/\x00/u']])->validate();

        return DB::transaction(function () use ($actorId, $customerId, $body) {
            Customer::findOrFail($customerId);
            $r = CustomerNote::create(['customer_id' => $customerId, 'body' => $body, 'author_id' => $actorId]);
            event(new CustomerChanged($actorId, $customerId, 'note_added', null, ['note_id' => $r->id]));

            return $r;
        });
    }

    public function upload(int $actorId, int $customerId, UploadedFile $file): CustomerDocument
    {
        $this->access($actorId, 'document_manage');
        Customer::findOrFail($customerId);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png']])->validate();
        $path = $file->store('customers/'.$customerId, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'تعذر حفظ الملف.']);
        }
        try {
            return DB::transaction(function () use ($actorId, $customerId, $file, $path) {
                $name = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200);
                $r = CustomerDocument::create(['customer_id' => $customerId, 'name' => $name, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize()]);
                event(new CustomerChanged($actorId, $customerId, 'document_added', null, ['document_id' => $r->id, 'mime' => $r->mime]));

                return $r;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}

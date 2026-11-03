<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ServiceContractActions
{
    public function lock(): void
    {
        // Shared security/financial gate precedes M07/M08/M09 and M10 source rows.
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(11001)');
        DB::select('select pg_advisory_xact_lock(101001)');
    }

    public function contract(int $actor, int $id, string $permission, ?int $version = null): object
    {
        $this->lock();
        $c = DB::table('service_contracts')->lockForUpdate()->find($id);
        abort_unless($c, 404);
        app(ServiceContractAccess::class)->authorize($actor, (int) $c->branch_id, $permission);
        abort_unless($version === null || (int) $c->version === $version, 409, 'تغير العقد. أعد تحميل الصفحة.');

        return $c;
    }

    public function audit(int $actor, object $c, string $action, array $details = []): void
    {
        DB::table('service_contract_activities')->insert(['contract_id' => $c->id, 'revision_id' => $c->current_revision_id, 'actor_id' => $actor, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
        app(RecordAudit::class)->execute($actor, 'service_contract', $c->id, $action, null, $details + ['revision_id' => $c->current_revision_id], 'succeeded', 'ServiceContracts');
    }

    public function save(int $actor, ?int $id, int $version, string $creationKey, array $data): int
    {
        return $this->saveDraft($actor, $id, $version, $creationKey, $data);
    }

    private function saveDraft(int $actor, ?int $id, int $version, string $creationKey, array $data, ?int $renewedFrom = null): int
    {
        Validator::make(['key' => $creationKey], ['key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $id, $version, $creationKey, $data, $renewedFrom) {
            $this->lock();
            $c = $id ? $this->contract($actor, $id, 'contract_edit', $version) : null;
            app(ServiceContractAccess::class)->authorize($actor, $c?->branch_id ?? (int) ($data['branch_id'] ?? 0), $id ? 'contract_edit' : 'contract_create');
            $d = app(ServiceContractTerms::class)->validate($actor, $data);
            $json = json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (! $id) {
                $existing = DB::table('service_contracts')->where('creation_key', $creationKey)->first();
                if ($existing) {
                    $r = DB::table('service_contract_revisions')->where('contract_id', $existing->id)->where('revision', 1)->first();
                    abort_unless($existing->created_by === $actor && $existing->renewed_from_id === $renewedFrom && $this->sameTerms($r->data, $d), 409, 'مفتاح الإنشاء مستخدم لطلب مختلف.');

                    return (int) $existing->id;
                }
                $id = DB::table('service_contracts')->insertGetId(['creation_key' => $creationKey, 'branch_id' => $d['branch_id'], 'customer_id' => $d['customer_id'], 'responsible_employee_id' => $d['responsible_employee_id'], 'created_by' => $actor, 'renewed_from_id' => $renewedFrom]);
                $revision = DB::table('service_contract_revisions')->insertGetId(['contract_id' => $id, 'revision' => 1, 'creation_key' => $creationKey, 'data' => $json, 'created_by' => $actor]);
                DB::table('service_contracts')->where('id', $id)->update(['current_revision_id' => $revision]);
                $c = DB::table('service_contracts')->find($id);
                $this->audit($actor, $c, 'contract.created');
            } else {
                $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
                abort_unless($r->status === 'draft', 409, 'الشروط المقدمة للاعتماد لا تعدل؛ أنشئ مراجعة.');
                $this->identity($c, $d);
                DB::table('service_contract_revisions')->where('id', $r->id)->update(['data' => $json]);
                DB::table('service_contracts')->where('id', $id)->update(['responsible_employee_id' => $d['responsible_employee_id'], 'version' => $version + 1, 'updated_at' => now()]);
                $this->audit($actor, $c, 'contract.draft_updated', ['version' => $version + 1]);
            }

            return (int) $id;
        }, 3);
    }

    private function sameTerms(string $stored, array $data): bool
    {
        return json_decode($stored, true, 512, JSON_THROW_ON_ERROR) == $data;
    }

    private function identity(object $c, array $d): void
    {
        abort_unless((int) $c->branch_id === (int) $d['branch_id'] && (int) $c->customer_id === (int) $d['customer_id'], 409, 'هوية العميل والفرع ثابتة.');
    }

    public function submit(int $actor, int $id, int $version): void
    {
        $this->transition($actor, $id, $version, 'contract_edit', 'draft', 'submitted');
    }

    public function approve(int $actor, int $id, int $version): void
    {
        $this->transition($actor, $id, $version, 'contract_approve', 'submitted', 'approved');
    }

    public function sign(int $actor, int $id, int $version, int $documentId, string $signedOn): void
    {
        Validator::make(['date' => $signedOn], ['date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Africa/Cairo')->toDateString()])->validate();
        DB::transaction(function () use ($actor, $id, $version, $documentId, $signedOn) {
            $c = $this->contract($actor, $id, 'contract_sign', $version);
            $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
            abort_unless($r->status === 'approved', 409);
            $d = DB::table('service_contract_documents')->where('contract_id', $id)->where('revision_id', $r->id)->where('classification', 'signature')->find($documentId);
            abort_unless($d, 422, 'أرفق دليل توقيع يخص هذه المراجعة.');
            abort_unless(hash_equals($d->terms_sha256, hash('sha256', $r->data)), 409, 'دليل التوقيع سابق لتعديل الشروط. أرفق الدليل الحالي.');
            app(ServiceContractDocuments::class)->verify($d);
            DB::table('service_contract_revisions')->where('id', $r->id)->update(['status' => 'signed', 'signed_by' => $actor, 'signed_on' => $signedOn, 'signature_document_id' => $documentId]);
            $this->touch($actor, $c, 'signed', ['signature_document_id' => $documentId, 'signed_on' => $signedOn]);
        }, 3);
    }

    public function activate(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            $c = $this->contract($actor, $id, 'contract_activate', $version);
            abort_if(in_array($c->status, ['terminated', 'completed']), 409);
            $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
            abort_unless($r->status === 'signed', 409, 'يلزم اعتماد وتوقيع فعليان.');
            $d = app(ServiceContractTerms::class)->validate($actor, json_decode($r->data, true, 512, JSON_THROW_ON_ERROR));
            $today = now('Africa/Cairo')->toDateString();
            abort_unless($d['starts_on'] <= $today && $d['ends_on'] >= $today, 422, 'التفعيل داخل مدة العقد فقط.');
            app(ServiceContractBilling::class)->validateRevision($id, $d);
            app(OrganizationApi::class)->employee($actor, (int) $d['responsible_employee_id'], (int) $c->branch_id, $today);
            app(ServiceContractDocuments::class)->verify(DB::table('service_contract_documents')->find($r->signature_document_id));
            if ($c->active_revision_id) {
                DB::table('service_contract_revisions')->where('id', $c->active_revision_id)->update(['status' => 'superseded']);
            }
            DB::table('service_contract_revisions')->where('id', $r->id)->update(['status' => 'active']);
            DB::table('service_contracts')->where('id', $id)->update(['active_revision_id' => $r->id, 'status' => 'active', 'responsible_employee_id' => $d['responsible_employee_id'], 'version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, $c, 'contract.activated', ['active_revision_id' => $r->id, 'version' => $version + 1]);
        }, 3);
    }

    private function transition(int $actor, int $id, int $version, string $permission, string $from, string $to): void
    {
        DB::transaction(function () use ($actor, $id, $version, $permission, $from, $to) {
            $c = $this->contract($actor, $id, $permission, $version);
            abort_if(in_array($c->status, ['terminated', 'completed']), 409);
            $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
            abort_unless($r->status === $from, 409);
            if ($to === 'approved') {
                abort_unless((int) $r->created_by !== $actor, 403, 'يلزم اعتماد مستقل عن منشئ المراجعة.');
            }
            app(ServiceContractTerms::class)->validate($actor, json_decode($r->data, true, 512, JSON_THROW_ON_ERROR));
            DB::table('service_contract_revisions')->where('id', $r->id)->update(['status' => $to] + ($to === 'approved' ? ['approved_by' => $actor] : []));
            $this->touch($actor, $c, $to);
        }, 3);
    }

    private function touch(int $actor, object $c, string $status, array $details = []): void
    {
        DB::table('service_contracts')->where('id', $c->id)->update(['status' => $c->active_revision_id ? $c->status : $status, 'version' => $c->version + 1, 'updated_at' => now()]);
        $this->audit($actor, $c, 'contract.'.$status, $details + ['version' => $c->version + 1]);
    }

    public function amend(int $actor, int $id, int $version, string $creationKey, array $data): int
    {
        Validator::make(['key' => $creationKey], ['key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $id, $version, $creationKey, $data) {
            $c = $this->contract($actor, $id, 'contract_edit');
            $d = app(ServiceContractTerms::class)->validate($actor, $data);
            $this->identity($c, $d);
            $prior = DB::table('service_contract_revisions')->where('creation_key', $creationKey)->first();
            if ($prior) {
                abort_unless((int) $prior->contract_id === $id && (int) $prior->created_by === $actor && $this->sameTerms($prior->data, $d), 409);

                return (int) $prior->id;
            }
            abort_unless((int) $c->version === $version && in_array($c->status, ['approved', 'signed', 'active', 'suspended']), 409);
            $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
            abort_unless(in_array($r->status, ['approved', 'signed', 'active']), 409, 'توجد مراجعة قيد الإعداد.');
            $revision = DB::table('service_contract_revisions')->insertGetId(['contract_id' => $id, 'revision' => $r->revision + 1, 'creation_key' => $creationKey, 'data' => json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by' => $actor]);
            DB::table('service_contracts')->where('id', $id)->update(['current_revision_id' => $revision, 'status' => $c->active_revision_id ? $c->status : 'draft', 'version' => $version + 1, 'updated_at' => now()]);
            $c->current_revision_id = $revision;
            $this->audit($actor, $c, 'contract.amended', ['previous_revision_id' => $r->id, 'version' => $version + 1]);

            return $revision;
        }, 3);
    }

    public function renew(int $actor, int $id, int $version, string $creationKey, array $data): int
    {
        return DB::transaction(function () use ($actor, $id, $version, $creationKey, $data) {
            $c = $this->contract($actor, $id, 'contract_edit', $version);
            $this->identity($c, $data);
            $r = DB::table('service_contract_revisions')->find($c->active_revision_id ?? $c->current_revision_id);
            $terms = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
            if (($data['starts_on'] ?? '') <= $terms['ends_on']) {
                throw ValidationException::withMessages(['starts_on' => 'التجديد يبدأ بعد نهاية العقد الأصلي.']);
            }

            return $this->saveDraft($actor, null, 0, $creationKey, $data, $id);
        }, 3);
    }

    public function state(int $actor, int $id, int $version, string $status, string $reason): void
    {
        Validator::make(compact('status', 'reason'), ['status' => 'required|in:suspended,active,terminated,completed', 'reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $status, $reason) {
            $c = $this->contract($actor, $id, 'contract_activate', $version);
            abort_unless($c->active_revision_id && in_array($c->status, ['active', 'suspended']) && $c->status !== $status, 409);
            if ($status === 'active') {
                $r = DB::table('service_contract_revisions')->find($c->active_revision_id);
                $d = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
                abort_unless($d['starts_on'] <= now('Africa/Cairo')->toDateString() && $d['ends_on'] >= now('Africa/Cairo')->toDateString(), 422);
            }
            DB::table('service_contracts')->where('id', $id)->update(['status' => $status, 'version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, $c, 'contract.'.$status, ['reason' => $reason, 'version' => $version + 1]);
        }, 3);
    }
}

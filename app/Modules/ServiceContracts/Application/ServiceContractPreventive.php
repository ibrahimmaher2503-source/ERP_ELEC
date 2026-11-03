<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ServiceContractPreventive
{
    public function dispatch(int $actor, int $contract, string $code, int $responsibleUserId, string $key): int
    {
        Validator::make(compact('code', 'responsibleUserId', 'key'), ['code' => 'required|string|max:50', 'responsibleUserId' => 'required|integer|min:1', 'key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $contract, $code, $responsibleUserId, $key) {
            $actions = app(ServiceContractActions::class);
            $c = $actions->contract($actor, $contract, 'contract_edit');
            abort_unless($c->status === 'active' && $c->active_revision_id, 409);
            $r = DB::table('service_contract_revisions')->find($c->active_revision_id);
            $terms = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(now('Africa/Cairo')->toDateString() <= $terms['ends_on'], 422, 'انتهت مدة العقد؛ لا يمكن إصدار عمل وقائي جديد.');
            $visit = collect($terms['preventive_schedule'] ?? [])->firstWhere('code', $code);
            abort_unless($visit, 422, 'الموعد غير موجود في الجدول الموقع.');
            $old = DB::table('service_contract_preventive_dispatches')->where('creation_key', $key)->orWhere(fn ($q) => $q->where('contract_id', $contract)->where('revision_id', $r->id)->where('schedule_code', $code))->first();
            if ($old) {
                abort_unless((int) $old->contract_id === $contract && (int) $old->revision_id === (int) $r->id && $old->schedule_code === $code
                    && (int) $old->created_by === $actor && (int) $old->responsible_id === $responsibleUserId, 409);

                return (int) $old->work_order_id;
            }
            $work = app(FieldMaintenanceApi::class)->createContractPreventiveDraft($actor, $contract, $key, [
                'branch_id' => (int) $c->branch_id, 'customer_id' => (int) $c->customer_id, 'site_id' => $visit['site_id'],
                'responsible_id' => $responsibleUserId, 'description' => $visit['description'], 'equipment_ref' => $visit['equipment_ref'], 'service_date' => $visit['due_date'],
            ]);
            DB::table('service_contract_preventive_dispatches')->insert(['contract_id' => $contract, 'revision_id' => $r->id, 'schedule_code' => $code,
                'work_order_id' => $work, 'responsible_id' => $responsibleUserId, 'creation_key' => $key, 'created_by' => $actor]);
            $actions->audit($actor, $c, 'preventive.dispatched', ['schedule_code' => $code, 'work_order_id' => $work]);

            return $work;
        }, 3);
    }
}

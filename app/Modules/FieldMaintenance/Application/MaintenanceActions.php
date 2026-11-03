<?php

namespace App\Modules\FieldMaintenance\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintenanceActions
{
    public function linkContract(int $actor, int $work, int $version, int $contract, string $equipmentRef, string $date, string $kind): int
    {
        Validator::make(compact('equipmentRef', 'date', 'kind'), ['equipmentRef' => 'required|string|max:160', 'date' => 'required|date_format:Y-m-d', 'kind' => 'required|in:preventive,corrective'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $work, $version, $contract, $equipmentRef, $date, $kind) {
            $w = $this->work($actor, $work, 'workorder_manage');
            if ($w->contract_id !== null) {
                abort_unless((int) $w->contract_id === $contract && $w->contract_equipment_ref === mb_strtoupper(trim($equipmentRef)) && $w->contract_service_date === $date && $w->contract_service_kind === $kind, 409);

                return app(ServiceContractsApi::class)->reserveWork($actor, $contract, 'FieldMaintenance', $work);
            }
            abort_unless((int) $w->version === $version && $w->status === 'draft' && ! $w->billable, 409, 'اربط عقداً بمسودة خدمة غير مفوترة فقط.');
            $this->touch($actor, $w, 'contract.linked', ['contract_id' => $contract], ['contract_id' => $contract, 'contract_equipment_ref' => mb_strtoupper(trim($equipmentRef)), 'contract_service_date' => $date, 'contract_service_kind' => $kind]);

            return app(ServiceContractsApi::class)->reserveWork($actor, $contract, 'FieldMaintenance', $work);
        }, 3);
    }

    public function draft(int $actor, int $id, int $version, array $input): void
    {
        $d = Validator::make($input, ['instructions' => 'required|string|max:10000', 'priority' => 'required|in:low,normal,high,urgent', 'billable' => 'required|boolean', 'acknowledgment_required' => 'required|boolean', 'estimated_cost' => ['nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'], 'estimated_duration_minutes' => 'nullable|integer|min:1|max:525600'], [], __('maintenance.fields', [], 'ar'))->validate();
        DB::transaction(function () use ($actor, $id, $version, $d) {
            $w = $this->work($actor, $id, 'workorder_manage', $version);
            abort_unless($w->status === 'draft', 409);
            $this->project($actor, $w);
            $this->touch($actor, $w, 'workorder.draft_updated', [], $d);
        }, 5);
    }

    public function lock(): void
    {
        // Same lock order as Inventory/Projects; never lock M08 before financial owners.
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(70701)');
        DB::select('select pg_advisory_xact_lock(80801)');
    }

    public function audit(int $actor, string $type, int $id, string $action, ?array $before, array $after): void
    {
        DB::table('maintenance_activities')->insert(['entity_type' => $type, 'entity_id' => $id, 'actor_id' => $actor, 'action' => $action, 'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
        app(RecordAudit::class)->execute($actor, $type, $id, $action, $before, $after, 'succeeded', 'FieldMaintenance');
    }

    public function work(int $actor, int $id, string $permission, ?int $version = null): object
    {
        $this->lock();
        $w = DB::table('maintenance_work_orders')->lockForUpdate()->find($id);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize($actor, $w->branch_id, $permission);
        abort_unless($version === null || (int) $w->version === $version, 409, 'تغير أمر العمل. أعد فتح الصفحة.');

        return $w;
    }

    public function executable(object $w): void
    {
        abort_unless(in_array($w->status, ['draft', 'scheduled', 'assigned', 'in_progress']), 409, 'أمر العمل لا يسمح بالتنفيذ.');
    }

    public function project(int $actor, object|array $input): void
    {
        $d = (array) $input;
        if (! empty($d['project_id'])) {
            app(ProjectsApi::class)->maintenanceProject($actor, (int) $d['project_id'], (int) $d['branch_id'], (int) $d['customer_id']);
        }
    }

    public function touch(int $actor, object $w, string $action, array $details = [], array $changes = []): void
    {
        DB::table('maintenance_work_orders')->where('id', $w->id)->update($changes + ['version' => $w->version + 1, 'updated_at' => now()]);
        $this->audit($actor, 'maintenance_work_order', $w->id, $action, ['status' => $w->status, 'version' => $w->version], $details + $changes + ['version' => $w->version + 1]);
        if ($w->contract_id !== null && in_array($action, ['visit.recorded', 'labor.recorded', 'parts.used'])) {
            app(ServiceContractsApi::class)->recordWorkUsage($actor, 'FieldMaintenance', (int) $w->id);
        }
    }

    private function identity(int $actor, array $input): array
    {
        $d = Validator::make($input, ['branch_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1', 'site_id' => 'nullable|integer|min:1', 'contact_id' => 'nullable|integer|min:1', 'product_id' => 'nullable|integer|min:1', 'project_id' => 'nullable|integer|min:1', 'project_task_id' => 'nullable|integer|min:1', 'project_milestone_id' => 'nullable|integer|min:1', 'responsible_id' => 'required|integer|min:1', 'description' => 'required|string|min:5|max:10000', 'priority' => 'required|in:low,normal,high,urgent'], [], __('maintenance.fields', [], 'ar'))->validate();
        $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $d['customer_id']));
        $d['customer_name'] = $customer->name;
        app(CustomersApi::class)->maintenanceContext($actor, $customer->id, empty($d['site_id']) ? null : (int) $d['site_id'], empty($d['contact_id']) ? null : (int) $d['contact_id']);
        abort_unless(in_array((int) $d['responsible_id'], array_column(app(OrganizationApi::class)->projectAssignees($actor, (int) $d['branch_id']), 'id'), true), 422, 'المسؤول خارج الفرع أو غير نشط.');
        if (! empty($d['product_id'])) {
            app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $d['product_id']));
        }
        $this->project($actor, $d);
        if (! empty($d['project_task_id']) || ! empty($d['project_milestone_id'])) {
            abort_unless(! empty($d['project_id']), 422);
            $planning = app(ProjectsApi::class)->maintenancePlanning($actor, (int) $d['project_id'], (int) $d['branch_id'], (int) $d['customer_id']);
            abort_unless(empty($d['project_task_id']) || in_array((int) $d['project_task_id'], array_column($planning['tasks'], 'id'), true), 422);
            abort_unless(empty($d['project_milestone_id']) || in_array((int) $d['project_milestone_id'], array_column($planning['milestones'], 'id'), true), 422);
        }

        return $d;
    }

    public function saveRequest(int $actor, ?int $id, int $version, string $key, array $input): int
    {
        Validator::make($input, ['branch_id' => 'required|integer|min:1'], [], __('maintenance.fields', [], 'ar'))->validate();
        Validator::make(compact('key'), ['key' => 'required|uuid'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $id, $version, $key, $input) {
            $this->lock();
            $r = $id ? DB::table('maintenance_requests')->lockForUpdate()->find($id) : DB::table('maintenance_requests')->where('creation_key', $key)->first();
            abort_unless(! $id || $r, 404);
            $branch = $r?->branch_id ?? (int) ($input['branch_id'] ?? 0);
            app(MaintenanceAccess::class)->authorize($actor, $branch, $r ? 'request_edit' : 'request_create');
            if ($r && ! $id) {
                abort_unless($r->created_by === $actor, 403);

                return $r->id;
            }
            abort_unless(! $r || ($r->status === 'draft' && $r->version === $version && $branch == ($input['branch_id'] ?? null)), 409);
            $d = $this->identity($actor, $input);
            $extra = Validator::make($input, ['reported_at' => 'required|date', 'preferred_date' => 'nullable|date_format:Y-m-d'], [], __('maintenance.fields', [], 'ar'))->validate();
            $extra['reported_at'] = CarbonImmutable::parse($extra['reported_at'])->utc();
            abort_if($extra['reported_at']->isFuture(), 422, 'وقت البلاغ في المستقبل.');
            $d += $extra;
            if ($r) {
                DB::table('maintenance_requests')->where('id', $id)->update($d + ['version' => $version + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('maintenance_requests')->insertGetId($d + ['creation_key' => $key, 'number' => app(OfficialDocuments::class)->nextMaintenanceReference('maintenance_request', now('Africa/Cairo')->toDateString()), 'created_by' => $actor]);
            }
            $this->audit($actor, 'maintenance_request', $id, 'request.saved', $r ? (array) $r : null, $d);

            return $id;
        }, 5);
    }

    public function requestState(int $actor, int $id, int $version, string $action, string $reason = ''): void
    {
        DB::transaction(function () use ($actor, $id, $version, $action, $reason) {
            $this->lock();
            $r = DB::table('maintenance_requests')->lockForUpdate()->find($id);
            abort_unless($r, 404);
            $permission = match ($action) {
                'accept','reject' => 'request_approve','reopen' => 'reopen',default => 'request_edit'
            };
            app(MaintenanceAccess::class)->authorize($actor, $r->branch_id, $permission);
            abort_unless($r->version === $version, 409);
            $to = match ($action) {
                'submit' => $r->status === 'draft' ? 'submitted' : null,'accept' => $r->status === 'submitted' ? 'accepted' : null,'reject' => $r->status === 'submitted' ? 'rejected' : null,'cancel' => in_array($r->status, ['draft', 'submitted', 'accepted']) ? 'cancelled' : null,'reopen' => in_array($r->status, ['rejected', 'cancelled']) ? 'draft' : null,default => null
            };
            abort_unless($to, 409);
            if (in_array($action, ['reject', 'cancel', 'reopen'])) {
                Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();
            }
            $this->project($actor, $r);
            DB::table('maintenance_requests')->where('id', $id)->update(['status' => $to, 'version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, 'maintenance_request', $id, 'request.'.$action, ['status' => $r->status], ['status' => $to, 'reason' => $reason]);
        }, 5);
    }

    public function createWorkOrder(int $actor, ?int $request, int $requestVersion, string $key, array $input = []): int
    {
        if ($request === null) {
            Validator::make($input, ['branch_id' => 'required|integer|min:1'], [], __('maintenance.fields', [], 'ar'))->validate();
        }
        Validator::make(compact('key'), ['key' => 'required|uuid'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $request, $requestVersion, $key, $input) {
            $this->lock();
            $r = $request ? DB::table('maintenance_requests')->lockForUpdate()->find($request) : null;
            abort_unless(! $request || $r, 404);
            $branch = $r?->branch_id ?? (int) ($input['branch_id'] ?? 0);
            app(MaintenanceAccess::class)->authorize($actor, $branch, 'workorder_manage');
            if (! $request) {
                app(MaintenanceAccess::class)->authorize($actor, $branch, 'standalone_create');
            }
            $old = DB::table('maintenance_work_orders')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->created_by === $actor && $old->request_id === $request && $old->branch_id === $branch, 409);

                return $old->id;
            }
            if ($r) {
                abort_unless($r->status === 'accepted' && $r->version === $requestVersion, 409);
            }
            $d = $this->identity($actor, $r ? (array) $r : $input);
            $extra = Validator::make($input, ['instructions' => 'sometimes|string|max:10000', 'billable' => 'sometimes|boolean', 'acknowledgment_required' => 'sometimes|boolean', 'estimated_cost' => ['sometimes', 'nullable', 'regex:/^\d{1,16}(\.\d{1,2})?$/']], [], __('maintenance.fields', [], 'ar'))->validate();
            $id = DB::table('maintenance_work_orders')->insertGetId($d + $extra + ['creation_key' => $key, 'number' => app(OfficialDocuments::class)->nextMaintenanceReference('maintenance_work_order', now('Africa/Cairo')->toDateString()), 'request_id' => $request, 'created_by' => $actor]);
            if ($r) {
                DB::table('maintenance_requests')->where('id', $request)->update(['status' => 'work_order_created', 'version' => $r->version + 1, 'updated_at' => now()]);
                $this->audit($actor, 'maintenance_request', $request, 'request.converted', null, ['work_order_id' => $id]);
            }
            $this->audit($actor, 'maintenance_work_order', $id, 'workorder.created', null, $d);

            return $id;
        }, 5);
    }

    public function state(int $actor, int $id, int $version, string $action, string $reason = ''): void
    {
        DB::transaction(function () use ($actor, $id, $version, $action, $reason) {
            $permission = match ($action) {
                'verify' => 'verify','close' => 'close','reopen' => 'reopen',default => 'workorder_manage'
            };
            $w = $this->work($actor, $id, $permission, $version);
            $this->project($actor, $w);
            $to = match ($action) {
                'start' => $w->status === 'assigned' ? 'in_progress' : null,'complete' => $w->status === 'in_progress' ? 'work_completed' : null,'verify' => $w->status === 'work_completed' ? 'verified' : null,'close' => $w->status === 'verified' ? 'closed' : null,'hold' => in_array($w->status, ['scheduled', 'assigned', 'in_progress']) ? 'on_hold' : null,'resume' => $w->status === 'on_hold' ? $w->hold_from : null,'cancel' => in_array($w->status, ['draft', 'scheduled', 'assigned']) ? 'cancelled' : null,'reopen' => $w->status === 'cancelled' ? 'draft' : (in_array($w->status, ['closed', 'verified', 'work_completed']) ? 'in_progress' : null),default => null
            };
            abort_unless($to, 409, 'انتقال حالة غير صالح.');
            Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:10000'], [], __('maintenance.fields', [], 'ar'))->validate();
            if ($action === 'start') {
                abort_unless(DB::table('maintenance_assignments')->where('work_order_id', $id)->where('technician_id', $actor)->where('active', true)->exists() || app(MaintenanceAccess::class)->allows($actor, $w->branch_id, 'assign'), 403);
            }
            if (in_array($action, ['complete', 'verify', 'close'])) {
                app(MaintenanceExecution::class)->completionGuards($actor, $w);
            }
            if ($action === 'cancel') {
                abort_if(DB::table('maintenance_visits')->where('work_order_id', $id)->where('status', 'recorded')->where('outcome', '<>', 'cancelled')->exists() || DB::table('maintenance_part_issues')->whereIn('requirement_id', DB::table('maintenance_part_requirements')->where('work_order_id', $id)->select('id'))->exists(), 409, 'يلزم تسوية التنفيذ والحجوزات أولاً.');
                app(MaintenanceParts::class)->reconciled($actor, $id);
                foreach (DB::table('maintenance_visits')->where('work_order_id', $id)->where('status', 'planned')->get() as $visit) {
                    DB::table('maintenance_visits')->where('id', $visit->id)->update(['status' => 'recorded', 'outcome' => 'cancelled', 'cancellation_reason' => $reason, 'recorded_by' => $actor, 'recorded_at' => now()->format('Y-m-d H:i:s.uP'), 'version' => $visit->version + 1]);
                }
            }
            $changes = ['status' => $to];
            if ($action === 'hold') {
                $changes['hold_from'] = $w->status;
            }
            if ($action === 'complete') {
                $changes['completion_notes'] = $reason;
            }
            if ($action === 'verify') {
                if ($w->contract_id !== null) {
                    app(ServiceContractsApi::class)->consumeWork($actor, 'FieldMaintenance', $id);
                }
                app(MaintenanceExecution::class)->verify($actor, $w, $reason);
            }
            if ($action === 'close') {
                $changes['closed_at'] = now();
                abort_unless(DB::table('maintenance_completions')->where('work_order_id', $id)->exists(), 409);
                if ($w->billable) {
                    abort_unless(app(MaintenanceBilling::class)->handoff($actor, $id) !== null, 409, 'يلزم إظهار إحالة فوترة الخدمة.');
                }
            }
            if ($action === 'reopen') {
                abort_if($w->contract_id !== null, 409, 'أمر العمل التعاقدي المثبت يحتفظ باستهلاكه؛ أنشئ متابعة جديدة.');
                $changes['closed_at'] = null;
            }
            if (in_array($action, ['close', 'cancel'])) {
                DB::table('maintenance_assignments')->where('work_order_id', $id)->where('active', true)->update(['active' => false, 'ended_at' => now()]);
            }
            $this->touch($actor, $w, 'workorder.'.$action, ['reason' => $reason], $changes);
            if ($action === 'cancel' && $w->contract_id !== null) {
                app(ServiceContractsApi::class)->releaseWork($actor, 'FieldMaintenance', $id);
            }
        }, 5);
    }
}

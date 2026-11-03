<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
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

final class WorkshopActions
{
    public function lock(): void
    {
        foreach ([10401, 70701, 80801, 90901] as $key) {
            DB::select('select pg_advisory_xact_lock(?)', [$key]);
        }
    }

    public function audit(int $actor, ?int $intake, ?int $job, string $action, array $details): void
    {
        DB::table('workshop_activities')->insert(['intake_id' => $intake, 'job_id' => $job, 'actor_id' => $actor, 'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR)]);
        app(RecordAudit::class)->execute($actor, $job ? 'workshop_job' : 'workshop_intake', $job ?? $intake ?? 0, $action, null, $details, 'succeeded', 'WorkshopRepairs');
    }

    public function work(int $actor, int $id, string $permission, ?int $version = null): object
    {
        $this->lock();
        $w = DB::table('workshop_jobs')->lockForUpdate()->find($id);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, $w->branch_id, $permission);
        abort_unless($version === null || $w->version === $version, 409, 'تغير أمر الإصلاح. أعد فتح الصفحة.');

        return $w;
    }

    public function project(int $actor, object $w): void
    {
        if ($w->project_id) {
            app(ProjectsApi::class)->maintenanceProject($actor, $w->project_id, $w->branch_id, $w->customer_id);
        }
    }

    public function executable(object $w): void
    {
        abort_if($w->pending_transfer_id !== null, 409, 'المعدة قيد النقل بين الورش.');
        app(WorkshopEstimates::class)->authorized($w);
        abort_unless(in_array($w->status, ['approved', 'repair', 'waiting_parts']), 409, 'أمر الإصلاح غير مؤهل للتنفيذ.');
    }

    public function touch(int $actor, object $w, string $action, array $details = [], array $changes = []): void
    {
        DB::table('workshop_jobs')->where('id', $w->id)->update($changes + ['version' => $w->version + 1, 'updated_at' => now()]);
        $this->audit($actor, $w->intake_id, $w->id, $action, $details + $changes + ['version' => $w->version + 1]);
        if ($w->contract_id !== null && in_array($action, ['work.recorded', 'parts.used'])) {
            app(ServiceContractsApi::class)->recordWorkUsage($actor, 'WorkshopRepairs', (int) $w->id);
        }
    }

    public function saveWorkshop(int $actor, ?int $id, int $version, array $input): int
    {
        $d = Validator::make($input, ['code' => 'required|string|max:40', 'name' => 'required|string|max:200', 'specialty' => 'required|string|max:2000', 'branch_id' => 'required|integer', 'supervisor_id' => 'required|integer', 'warehouse_id' => 'nullable|integer', 'capacity' => 'required|integer|min:1|max:10000', 'schedule' => 'required|array|min:1', 'schedule.*.day' => 'required|integer|between:0,6', 'schedule.*.start' => 'required|date_format:H:i', 'schedule.*.end' => 'required|date_format:H:i|after:schedule.*.start', 'active' => 'required|boolean'])->validate();

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $this->lock();
            app(WorkshopAccess::class)->authorize($actor, (int) $d['branch_id'], 'manage');
            $old = $id ? DB::table('workshops')->lockForUpdate()->find($id) : null;
            abort_unless(! $id || ($old && $old->version === $version && $old->branch_id === (int) $d['branch_id']), 409);
            abort_unless(in_array((int) $d['supervisor_id'], array_column(app(OrganizationApi::class)->projectAssignees($actor, (int) $d['branch_id']), 'id'), true), 422);
            if (! empty($d['warehouse_id'])) {
                $warehouse = app(InventoryApi::class)->warehouse((int) $d['warehouse_id']);
                abort_unless($warehouse && $warehouse['active'] && $warehouse['branch_id'] === (int) $d['branch_id'], 422);
                abort_unless(in_array((int) $d['warehouse_id'], array_column(app(InventoryApi::class)->reservationWarehouses($actor, (int) $d['branch_id']), 'id'), true), 403);
            }
            $d['schedule'] = json_encode($d['schedule'], JSON_THROW_ON_ERROR);
            if ($old) {
                if (! $d['active']) {
                    abort_if(DB::table('workshop_jobs')->where('workshop_id', $id)->whereNotIn('status', ['delivered', 'returned_unrepaired'])->exists(), 409, 'توجد إصلاحات مفتوحة.');
                }
                DB::table('workshops')->where('id', $id)->update($d + ['version' => $version + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('workshops')->insertGetId($d + ['created_by' => $actor]);
            }
            $this->audit($actor, null, null, 'workshop.saved', ['workshop_id' => $id, 'code' => $d['code']]);

            return $id;
        }, 5);
    }

    public function member(int $actor, int $workshop, int $version, int $technician, bool $active): void
    {
        DB::transaction(function () use ($actor, $workshop, $version, $technician, $active) {
            $this->lock();
            $w = DB::table('workshops')->lockForUpdate()->find($workshop);
            abort_unless($w, 404);
            app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'assign');
            abort_unless($w->version === $version, 409);
            if ($active) {
                abort_unless(in_array($technician, array_column(app(OrganizationApi::class)->projectAssignees($actor, $w->branch_id), 'id'), true), 422);
                app(WorkshopAccess::class)->authorize($technician, $w->branch_id, 'execute');
            } else {
                abort_unless(DB::table('workshop_members')->where('workshop_id', $workshop)->where('technician_id', $technician)->exists(), 404);
            }
            if (! $active) {
                abort_if(DB::table('workshop_jobs')->where('workshop_id', $workshop)->where('technician_id', $technician)->whereNotIn('status', ['delivered', 'returned_unrepaired'])->exists(), 409);
            }
            $old = DB::table('workshop_members')->where('workshop_id', $workshop)->where('technician_id', $technician)->first();
            if ($old) {
                DB::table('workshop_members')->where('id', $old->id)->update(['active' => $active]);
            } else {
                DB::table('workshop_members')->insert(['workshop_id' => $workshop, 'technician_id' => $technician, 'active' => $active, 'created_by' => $actor]);
            }
            DB::table('workshops')->where('id', $workshop)->update(['version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, null, null, 'workshop.member', ['workshop_id' => $workshop, 'technician_id' => $technician, 'active' => $active]);
        }, 5);
    }

    public function saveIntake(int $actor, ?int $id, int $version, string $key, array $input): int
    {
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();
        $d = Validator::make($input, ['branch_id' => 'required|integer', 'customer_id' => 'nullable|integer', 'company_serial_id' => 'nullable|integer', 'company_movement_id' => 'nullable|integer', 'site_id' => 'nullable|integer', 'contact_id' => 'nullable|integer', 'product_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'maintenance_work_order_id' => 'nullable|integer', 'maintenance_visit_id' => 'nullable|integer', 'equipment_description' => 'required|string|min:5|max:5000', 'model' => 'nullable|string|max:200', 'serial_number' => 'nullable|string|max:200', 'accessories' => 'required|string|max:5000', 'reported_fault' => 'required|string|min:5|max:5000', 'arrival_condition' => 'required|string|min:5|max:5000', 'customer_owned' => 'required|boolean', 'location' => 'required|string|max:300'])->validate();
        $d['customer_owned'] = (bool) $d['customer_owned'];
        $d['company_serial_id'] = ($d['company_serial_id'] ?? null) ?: null;
        $d['serial_number'] = trim($d['serial_number'] ?? '') ?: null;
        foreach (['customer_id', 'company_serial_id', 'company_movement_id', 'site_id', 'contact_id', 'product_id', 'project_id', 'maintenance_work_order_id', 'maintenance_visit_id'] as $field) {
            $d[$field] = empty($d[$field]) ? null : (int) $d[$field];
        }
        $payload = $d;
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($actor, $id, $version, $key, $d, $hash) {
            $this->lock();
            app(WorkshopAccess::class)->authorize($actor, (int) $d['branch_id'], 'intake_manage');
            $old = $id ? DB::table('workshop_intakes')->lockForUpdate()->find($id) : DB::table('workshop_intakes')->where('creation_key', $key)->first();
            if ($old && ! $id) {
                abort_unless($old->created_by === $actor && $old->branch_id === (int) $d['branch_id']
                    && (int) $old->customer_id === (int) ($d['customer_id'] ?? 0)
                    && (int) $old->company_serial_id === (int) $d['company_serial_id'] && (int) $old->company_movement_id === (int) $d['company_movement_id'] && $old->creation_payload_hash === $hash, 409, 'مفتاح الاستلام مرتبط بمحتوى مختلف أو قديم؛ افتح السجل من القائمة.');

                return $old->id;
            }
            abort_unless(! $id || ($old && $old->version === $version && $old->status === 'draft'), 409);
            if ($old) {
                foreach (['branch_id', 'customer_id', 'company_serial_id', 'company_movement_id', 'project_id', 'maintenance_work_order_id', 'maintenance_visit_id'] as $field) {
                    abort_unless((string) ($old->$field ?? '') === (string) ($d[$field] ?? ''), 409, 'هوية مصدر الاستلام ثابتة؛ أنشئ مسودة جديدة للهوية الأخرى.');
                }
                abort_unless($old->customer_owned === $d['customer_owned'], 409);
            }
            if ($d['customer_owned']) {
                abort_unless(! $d['company_serial_id'] && ! $d['company_movement_id'] && ! empty($d['customer_id']), 422);
                $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $d['customer_id']));
                app(CustomersApi::class)->maintenanceContext($actor, $customer->id, empty($d['site_id']) ? null : (int) $d['site_id'], empty($d['contact_id']) ? null : (int) $d['contact_id']);
                $d['customer_name'] = $customer->name;
            } else {
                abort_unless(((bool) $d['company_serial_id'] !== (bool) $d['company_movement_id']) && empty($d['customer_id']) && empty($d['project_id'])
                    && empty($d['site_id']) && empty($d['contact_id']) && empty($d['maintenance_work_order_id']) && empty($d['maintenance_visit_id']), 422);
                $equipment = $d['company_serial_id'] ? app(InventoryApi::class)->repairEquipment($actor, (int) $d['company_serial_id']) : app(InventoryApi::class)->repairEquipmentFromReceipt($actor, (int) $d['company_movement_id']);
                abort_unless($d['company_serial_id'] || $d['serial_number'], 422, 'سجل رقم المعدة الفعلي أو كودها المميز.');
                abort_unless($equipment['branch_id'] === (int) $d['branch_id'], 422);
                $d['product_id'] = $equipment['product_id'];
                $d['serial_number'] = $equipment['serial'] ?? $d['serial_number'];
                $d['customer_id'] = null;
                $d['customer_name'] = 'معدات الشركة';
            }
            if (! empty($d['product_id'])) {
                app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $d['product_id']));
            }
            if (! empty($d['project_id'])) {
                app(ProjectsApi::class)->maintenanceProject($actor, (int) $d['project_id'], (int) $d['branch_id'], $customer->id);
            }
            if (! empty($d['maintenance_work_order_id'])) {
                app(FieldMaintenanceApi::class)->workshopTransfer($actor, (int) $d['maintenance_work_order_id'], (int) ($d['maintenance_visit_id'] ?? 0), (int) $d['branch_id'], $customer->id);
            } else {
                abort_if(! empty($d['maintenance_visit_id']), 422);
            }
            if ($old) {
                DB::table('workshop_intakes')->where('id', $id)->update($d + ['version' => $version + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('workshop_intakes')->insertGetId($d + ['creation_key' => $key, 'creation_payload_hash' => $hash, 'number' => app(OfficialDocuments::class)->nextOperationalReference('workshop_intake', now('Africa/Cairo')->toDateString()), 'created_by' => $actor]);
            }
            $this->audit($actor, $id, null, 'intake.saved', ['version' => $old ? $version + 1 : 1]);

            return $id;
        }, 5);
    }

    public function receive(int $actor, int $id, int $version, string $key, string $receivedAt, string $acknowledgment): void
    {
        Validator::make(compact('key', 'receivedAt', 'acknowledgment'), ['key' => 'required|uuid', 'receivedAt' => 'required|date', 'acknowledgment' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $key, $receivedAt, $acknowledgment) {
            $this->lock();
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($id);
            abort_unless($i, 404);
            app(WorkshopAccess::class)->authorize($actor, $i->branch_id, 'custody');
            $this->project($actor, $i);
            $old = DB::table('workshop_custody_events')->where('event_key', $key)->first();
            if ($old) {
                abort_unless($old->intake_id === $id && $old->status === 'received' && $old->responsible_id === $actor
                    && $i->acknowledgment === $acknowledgment && CarbonImmutable::parse($i->received_at)->equalTo(CarbonImmutable::parse($receivedAt)), 409);

                return;
            }
            abort_unless($i->status === 'draft' && $i->version === $version, 409);
            if ($i->customer_owned && $i->serial_number !== null && trim($i->serial_number) !== '') {
                abort_if(DB::table('workshop_intakes')->where('customer_id', $i->customer_id)->where('serial_number', $i->serial_number)->where('id', '<>', $id)->whereNotIn('status', ['draft', 'delivered', 'returned_unrepaired'])->exists(), 409, 'المعدة بالرقم المسلسل نفسه موجودة بالفعل في العهدة.');
            }
            $date = CarbonImmutable::parse($receivedAt)->utc();
            abort_if($date->isFuture(), 422);
            $identity = $i->customer_owned ? 'customer:'.$i->customer_id.':'.($i->serial_number ? mb_strtoupper(trim($i->serial_number)) : 'unidentified:'.$i->creation_key) : ($i->company_serial_id ? 'inventory-serial:'.$i->company_serial_id : 'inventory-product:'.$i->product_id.':'.mb_strtoupper(trim($i->serial_number)));
            abort_if(DB::table('workshop_intakes')->where('equipment_identity', $identity)->where('id', '<>', $id)->whereNotIn('status', ['draft', 'delivered', 'returned_unrepaired'])->exists(), 409, 'المعدة موجودة بالفعل في عهدة مفتوحة.');
            $isolation = null;
            if (! $i->customer_owned) {
                $previous = DB::table('workshop_intakes')->where('equipment_identity', $identity)->where('id', '<>', $id)->whereNotNull('isolation_id')->orderByDesc('id')->first();
                if ($previous) {
                    $hold = app(InventoryApi::class)->repairIsolation($actor, $previous->isolation_id);
                    abort_unless(bccomp($hold['remaining'], '0', 8) === 0, 409, 'المعدة ما زالت معزولة في M06 من استلام سابق؛ يلزم حسم حالة العزل الأصلية أولاً.');
                }
                $equipment = $i->company_serial_id ? app(InventoryApi::class)->repairEquipment($actor, $i->company_serial_id) : app(InventoryApi::class)->repairEquipmentFromReceipt($actor, $i->company_movement_id);
                abort_unless(($equipment['state'] ?? 'available') === 'available' && $equipment['branch_id'] === $i->branch_id && $equipment['product_id'] === $i->product_id, 409);
                $held = app(InventoryApi::class)->isolateStock($actor, ['warehouse_id' => $equipment['warehouse_id'], 'product_id' => $equipment['product_id'], 'source_movement_id' => $equipment['source_movement_id'], 'quantity' => '1', 'serials' => $i->company_serial_id ? [$equipment['serial']] : [], 'reason' => 'إصلاح معدات الشركة '.$i->number, 'idempotency_key' => $key]);
                $isolation = $held['id'];
            }
            DB::table('workshop_intakes')->where('id', $id)->update(['equipment_identity' => $identity, 'isolation_id' => $isolation, 'received_at' => $date, 'received_by' => $actor, 'acknowledgment' => $acknowledgment, 'status' => 'received', 'version' => $version + 1, 'updated_at' => now()]);
            $this->custody($actor, $i, $i->location, 'received', 'استلام عهدة موثق', $key, $i->arrival_condition);
        }, 5);
    }

    public function custody(int $actor, object $i, string $destination, string $status, string $reason, string $key, string $condition): void
    {
        DB::table('workshop_custody_events')->insert(['intake_id' => $i->id, 'event_key' => $key, 'source_location' => $i->location, 'destination_location' => $destination, 'responsible_id' => $actor, 'condition' => $condition, 'reason' => $reason, 'status' => $status]);
        $this->audit($actor, $i->id, null, 'custody.'.$status, ['from' => $i->location, 'to' => $destination, 'reason' => $reason]);
    }

    public function createJob(int $actor, int $intake, int $version, string $key, int $workshop, string $priority, string $billingMode, ?int $contract = null, ?string $serviceDate = null): int
    {
        Validator::make(compact('key', 'priority', 'billingMode'), ['key' => 'required|uuid', 'priority' => 'required|in:low,normal,high,urgent', 'billingMode' => 'required|in:billable,partial,warranty,nonbillable,contract'])->validate();
        abort_unless(($billingMode === 'contract') === ($contract !== null), 422, 'حدد العقد للخدمة التعاقدية فقط.');
        if ($contract !== null) {
            Validator::make(['date' => $serviceDate], ['date' => 'required|date_format:Y-m-d'])->validate();
        }
        $payload = [$actor, $intake, $workshop, $priority, $billingMode];
        if ($contract !== null) {
            $payload[] = [$contract, $serviceDate];
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $intake, $version, $key, $workshop, $priority, $billingMode, $hash, $contract, $serviceDate) {
            $this->lock();
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($intake);
            abort_unless($i, 404);
            app(WorkshopAccess::class)->authorize($actor, $i->branch_id, 'job_manage');
            $this->project($actor, $i);
            $old = DB::table('workshop_jobs')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->intake_id === $intake && $old->created_by === $actor && $old->creation_payload_hash === $hash, 409);

                return $old->id;
            }
            abort_unless($i->status === 'received' && $i->version === $version, 409);
            $w = DB::table('workshops')->find($workshop);
            abort_unless($w && $w->active && $w->branch_id === $i->branch_id, 422);
            abort_unless($i->customer_owned || $billingMode === 'nonbillable', 422, 'إصلاح معدات الشركة غير قابل لفاتورة عميل.');
            $id = DB::table('workshop_jobs')->insertGetId(['creation_key' => $key, 'creation_payload_hash' => $hash, 'number' => app(OfficialDocuments::class)->nextOperationalReference('workshop_job', now('Africa/Cairo')->toDateString()), 'intake_id' => $intake, 'branch_id' => $i->branch_id, 'customer_id' => $i->customer_id, 'customer_name' => $i->customer_name, 'project_id' => $i->project_id, 'workshop_id' => $workshop, 'priority' => $priority, 'billing_mode' => $billingMode, 'contract_id' => $contract, 'contract_service_date' => $serviceDate, 'created_by' => $actor]);
            if ($contract !== null) {
                app(ServiceContractsApi::class)->reserveWork($actor, $contract, 'WorkshopRepairs', $id);
            }
            DB::table('workshop_intakes')->where('id', $intake)->update(['workshop_id' => $workshop, 'location' => $w->name, 'status' => 'inspection', 'version' => $version + 1, 'updated_at' => now()]);
            $this->custody($actor, $i, $w->name, 'inspection', 'إسناد للفحص', $key, $i->arrival_condition);
            $this->audit($actor, $intake, $id, 'job.created', ['workshop_id' => $workshop]);

            return $id;
        }, 5);
    }

    public function assign(int $actor, int $job, int $version, int $workshop, int $technician, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $workshop, $technician, $reason) {
            $w = $this->work($actor, $job, 'assign', $version);
            abort_if($w->pending_transfer_id !== null, 409);
            $this->project($actor, $w);
            abort_if(in_array($w->status, ['verified', 'delivered', 'returned_unrepaired']), 409);
            $s = DB::table('workshops')->find($workshop);
            abort_unless($s && $s->active && $s->branch_id === $w->branch_id, 422);
            app(WorkshopAccess::class)->authorize($technician, $w->branch_id, 'execute');
            abort_unless(app(OrganizationApi::class)->projectBranchMember($technician, $w->branch_id) && DB::table('workshop_members')->where('workshop_id', $workshop)->where('technician_id', $technician)->where('active', true)->exists(), 422);
            abort_if($w->workshop_id !== $workshop, 409, 'استخدم نقل العهدة ثم استلام الورشة الوجهة.');
            $this->touch($actor, $w, 'job.assigned', ['previous_technician' => $w->technician_id, 'previous_workshop' => $w->workshop_id, 'reason' => $reason], ['technician_id' => $technician, 'workshop_id' => $workshop]);
        }, 5);
    }

    public function diagnose(int $actor, int $job, int $version, array $input): void
    {
        $d = Validator::make($input, ['required_tests' => 'required|array|min:1|max:50', 'required_tests.*.name' => 'required|string|max:200|distinct', 'required_tests.*.required' => 'required|boolean', 'fault_classification' => 'nullable|string|max:200', 'diagnosis' => 'required|string|min:5|max:10000', 'planned_work' => 'required|string|min:5|max:10000', 'estimated_minutes' => 'nullable|integer|min:1', 'estimated_completion' => 'nullable|date'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $d) {
            $w = $this->work($actor, $job, 'execute', $version);
            $this->project($actor, $w);
            app(WorkshopAccess::class)->technician($actor, $w);
            abort_unless($w->status === 'inspection', 409);
            if (! empty($d['estimated_completion'])) {
                $d['estimated_completion'] = CarbonImmutable::parse($d['estimated_completion'])->utc();
            }$d['required_tests'] = json_encode($d['required_tests'], JSON_THROW_ON_ERROR);
            $this->touch($actor, $w, 'job.diagnosed', [], $d);
        }, 5);
    }
}

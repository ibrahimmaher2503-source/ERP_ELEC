<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Attendance\Application\AttendanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\EmployeeActions;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SetUserWarehouseScope;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Sales\Application\QuotationActions;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\WorkshopRepairs\Application\RepairOffers;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use Carbon\CarbonImmutable;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/** Explicitly authorized local simulation through owning actions; never activates company finance. */
class OperationalWorkflowDemoSeeder extends Seeder
{
    private const NOTE = 'DEMO-EG-OPS — محاكاة محلية مصطنعة بإذن المالك، ليست أعمالًا أو إقرارًا أو مستندًا قانونيًا حقيقيًا';

    private int $maker;

    private int $reviewer;

    private array $branches;

    private CarbonImmutable $today;

    private bool $canIssue;

    public function run(): void
    {
        $db = DB::connection();
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || $db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== $expected
            || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true) || (string) env('ERP_DEMO_WORKFLOWS') !== '1') {
            throw new \RuntimeException('Explicit local operational demo requires ERP_DEMO_WORKFLOWS=1.');
        }
        $owner = User::findOrFail((int) env('ERP_DEMO_ACTOR_ID', 2));
        app(RbacGuard::class)->authorize($owner);
        $this->maker = $this->identity('maker');
        $this->reviewer = $this->identity('reviewer');
        $this->branches = DB::table('user_branch_scopes as s')->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.user_id', $this->maker)->where('b.code', 'like', 'DEMO-EG%')->where('b.active', true)
            ->whereIn('b.id', DB::table('user_branch_scopes')->where('user_id', $this->reviewer)->select('branch_id'))
            ->whereIn('b.id', DB::table('user_branch_scopes')->where('user_id', $owner->id)->select('branch_id'))
            ->orderBy('b.id')->limit(5)->pluck('b.id')->all();
        if ($this->branches === []) {
            throw new \RuntimeException('Run the authorized project/purchasing workflow demo first.');
        }
        $this->today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        try {
            app(OrganizationApi::class)->documentCompany($owner->id);
            $this->canIssue = true;
        } catch (ContractException $e) {
            $this->canIssue = false;
            $this->command?->warn('Official completion/delivery documents blocked by existing company setup: '.$e->getMessage());
        }
        $this->permissions($owner);
        $customers = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->limit(150)->get();
        $products = DB::table('products')->where('sku', 'like', 'DEMO-EG%')->where('kind', 'bulk')->where('active', true)->orderBy('id')->limit(300)->get();
        $service = DB::table('products')->where('sku', 'like', 'DEMO-%')->where('kind', 'service')->where('active', true)->value('id');
        if ($customers->isEmpty() || $products->count() < 8 || ! $service) {
            throw new \RuntimeException('Existing synthetic customers, stock products and a service product are required.');
        }
        $this->sales($customers, $products);
        $this->maintenance($customers);
        $this->workshops($customers);
        $employees = $this->employees($owner->id);
        $this->contracts($customers, $employees, (int) $service);
        $this->attendance($employees);
        $this->tenders($customers);
        $this->supply($owner, $products);
        $this->command?->info('Operational demo ready; finance activation and stock/GL posting remain unchanged.');
    }

    private function key(string $name): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'DEMO-EG-OPS-'.$name)->toString();
    }

    private function identity(string $kind): int
    {
        $user = User::where('email', 'workflow.'.$kind.'@egypt-market.example.test')->firstOrFail();
        if (! $user->active || ! $user->email_verified_at || ! DB::table('access_audits')->where('subject_user_id', $user->id)
            ->where('action', 'local_cli.demo_identity_authorized')->where('after_snapshot->dataset', 'DEMO-EG-FLOW')->exists()) {
            throw new \RuntimeException('Authorized existing workflow identities are required; owner edits are never overwritten.');
        }

        return $user->id;
    }

    private function permissions(User $owner): void
    {
        $shared = ['M01.employee_view', 'M04.sales_order_view', 'M04.sales_order_history', 'M08.view', 'M09.view', 'M10.contract_view', 'M15.view'];
        $maker = ['M04.sales_order_create', 'M04.sales_order_submit', 'M04.sales_order_cancel', 'M04.quotation_view', 'M04.quotation_create', 'M04.quotation_cancel',
            'M08.request_create', 'M08.request_edit', 'M08.workorder_manage', 'M08.standalone_create', 'M08.assign', 'M08.visit_record', 'M08.checklist_manage', 'M08.labor_record',
            'M09.manage', 'M09.intake_manage', 'M09.custody', 'M09.job_manage', 'M09.assign', 'M09.execute', 'M09.labor_record',
            'M10.contract_create', 'M10.contract_edit', 'M10.document_manage',
            'M15.shift_manage', 'M15.schedule_manage', 'M15.punch_manage', 'M15.adjustment_request',
            'M07.tender_view', 'M07.tender_manage'];
        $reviewer = ['M04.sales_order_reject', 'M04.sales_order_approve', 'M08.request_approve', 'M08.verify', 'M08.close',
            'M09.nonbillable_approve', 'M09.verify', 'M09.deliver', 'M10.contract_approve', 'M10.contract_sign', 'M10.contract_activate', 'M15.adjustment_approve'];
        DB::transaction(function () use ($owner, $shared, $maker, $reviewer) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($owner);
            foreach ([$this->maker => $maker, $this->reviewer => $reviewer] as $user => $codes) {
                foreach (array_unique(array_merge($shared, $codes)) as $code) {
                    $scope = PermissionCatalog::DEFINITIONS[$code][1] ?? null;
                    if (! in_array($scope, ['company', 'branch'], true)) {
                        throw new \RuntimeException('Unknown operational demo permission: '.$code);
                    }
                    foreach ($scope === 'company' ? [null] : $this->branches as $branch) {
                        if (DB::table('access_grants')->where('user_id', $user)->where('permission_code', $code)->where('branch_id', $branch)->exists()) {
                            continue;
                        }
                        if (DB::table('access_audits')->where('subject_user_id', $user)->where('action', 'local_cli.operational_demo_permission_granted')
                            ->where('after_snapshot->permission_code', $code)->where('after_snapshot->branch_id', $branch)->exists()) {
                            throw new \RuntimeException('A previously authorized demo grant was revoked; refusing to restore it.');
                        }
                        DB::table('access_grants')->insert(['user_id' => $user, 'permission_code' => $code, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
                        $guard->audit($owner, $user, 'local_cli.operational_demo_permission_granted', [], ['dataset' => 'DEMO-EG-OPS', 'permission_code' => $code, 'branch_id' => $branch]);
                    }
                }
            }
        });
        PermissionAccess::forgetRequestSnapshot();
    }

    private function sales($customers, $products): void
    {
        for ($i = 0; $i < 240; $i++) {
            $key = $this->key('SO-'.$i);
            if (SalesOrder::where('draft_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $key, $customers, $products) {
                $lines = [];
                for ($j = 0; $j < 4 + $i % 3; $j++) {
                    $p = $products[($i * 6 + $j) % $products->count()];
                    $lines[] = ['product_id' => $p->id, 'unit_id' => $p->base_unit_id, 'quantity' => (5 + ($i + $j) % 50).'.00000000',
                        'requested_price' => (75 + ($i * 11 + $j * 31) % 1500).'.00000000', 'discount_mode' => 'fixed', 'discount_value' => '0',
                        'override_reason' => self::NOTE, 'description' => $p->name.' — محاكاة'];
                }
                $action = app(SalesOrderActions::class);
                $order = $action->save($this->maker, ['creation_key' => $key, 'branch_id' => $this->branches[$i % count($this->branches)],
                    'customer_id' => $customers[$i % $customers->count()]->id, 'source_type' => 'direct', 'order_date' => $this->today->toDateString(),
                    'requested_delivery_date' => $this->today->addDays(7 + $i % 30)->toDateString(), 'project_name' => 'محاكاة توريد تجهيزات كهربائية '.($i + 1),
                    'customer_reference' => 'DEMO-EG-OPS-SO-'.($i + 1), 'discount_mode' => 'fixed', 'discount_value' => '0',
                    'payment_terms' => 'شروط سداد تجريبية غير ملزمة', 'delivery_terms' => 'تسليم افتراضي دون التزام', 'notes' => self::NOTE, 'lines' => $lines]);
                if ($i % 4 === 2) {
                    $action->cancel($this->maker, $order->id, $order->version, self::NOTE.' — إلغاء سيناريو غير منفذ');
                } elseif ($i % 4 !== 0) {
                    $action->submit($this->maker, $order->id, $order->version);
                    if ($i % 4 === 3) {
                        $action->reject($this->reviewer, $order->id, $order->fresh()->version, self::NOTE.' — إعادة للمسودة بعد رفض المراجعة');
                    }
                }
                if ($i < 120) {
                    $reference = 'DEMO-EG-OPS-QUOTE-'.($i + 1);
                    if (! Quotation::where('rfq_reference', $reference)->exists()) {
                        $quote = app(QuotationActions::class)->save($this->maker, ['customer_id' => $order->customer_id, 'quotation_date' => $this->today->toDateString(),
                            'valid_until' => $this->today->addDays(30)->toDateString(), 'project_name' => $order->project_name, 'rfq_reference' => $reference,
                            'discount_mode' => 'fixed', 'discount_value' => '0', 'payment_terms' => $order->payment_terms, 'delivery_terms' => $order->delivery_terms,
                            'notes' => self::NOTE, 'exception_reason' => self::NOTE, 'lines' => $lines]);
                        if ($i % 2 === 1) {
                            app(QuotationActions::class)->cancel($this->maker, $quote->id, $quote->version, self::NOTE);
                        }
                    }
                }
            });
        }
        $this->command?->info('Sales: 240 scenario orders and 120 related quotations ready.');
    }

    private function maintenance($customers): void
    {
        $action = app(MaintenanceActions::class);
        $execution = app(MaintenanceExecution::class);
        $templates = [];
        foreach ($this->branches as $branch) {
            $code = 'DEMO-EG-OPS-CHECK-'.$branch;
            $templates[$branch] = DB::table('maintenance_checklist_templates')->where('code', $code)->value('id')
                ?? $execution->saveTemplate($this->maker, null, 0, ['branch_id' => $branch, 'code' => $code, 'title' => 'قائمة مراجعة سيناريو الديمو',
                    'items' => [['key' => 'simulation_review', 'label' => 'مراجعة سيناريو مصطنع فقط، ليس اختبار سلامة معدات', 'required' => true]], 'active' => true]);
        }
        for ($i = 0; $i < 120; $i++) {
            if (DB::table('maintenance_requests')->where('creation_key', $this->key('MR-'.$i))->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $customers, $action) {
                $id = $action->saveRequest($this->maker, null, 0, $this->key('MR-'.$i), $this->maintenanceIdentity($i, $customers)
                    + ['reported_at' => $this->today->subDays(2)->toIso8601String(), 'preferred_date' => $this->today->addDays(3)->toDateString()]);
                if ($i % 6 === 4) {
                    $action->requestState($this->maker, $id, 1, 'cancel', self::NOTE);
                } elseif ($i % 6 !== 0) {
                    $action->requestState($this->maker, $id, 1, 'submit');
                    if ($i % 6 >= 2) {
                        $action->requestState($this->reviewer, $id, 2, $i % 6 === 3 ? 'reject' : 'accept', self::NOTE);
                    }
                }
            });
        }
        for ($i = 0; $i < 90; $i++) {
            $key = $this->key('WO-'.$i);
            if (DB::table('maintenance_work_orders')->where('creation_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $key, $customers, $action, $execution, $templates) {
                $request = $i < 20 ? DB::table('maintenance_requests')->where('creation_key', $this->key('MR-'.($i * 6 + 5)))->firstOrFail() : null;
                $identity = $request ? (array) $request : $this->maintenanceIdentity($i, $customers);
                $id = $action->createWorkOrder($this->maker, $request?->id, $request?->version ?? 0, $key,
                    $identity + ['instructions' => self::NOTE, 'billable' => false, 'acknowledgment_required' => false]);
                $state = $i % 9;
                if ($state === 0) {
                    return;
                }
                if ($state === 8) {
                    $action->state($this->maker, $id, $this->version('maintenance_work_orders', $id), 'cancel', self::NOTE);

                    return;
                }
                $start = $this->today->subDays(20)->addHours($i * 2)->utc();
                $assignment = $execution->assign($this->maker, $id, $this->version('maintenance_work_orders', $id),
                    ['technician_id' => $this->maker, 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHour()->toIso8601String()]);
                if ($state === 1) {
                    return;
                }
                $visit = $execution->schedule($this->maker, $id, $this->version('maintenance_work_orders', $id), $this->key('VISIT-'.$i),
                    ['assignment_id' => $assignment, 'scheduled_at' => $start->toIso8601String(), 'template_id' => $templates[$identity['branch_id']], 'mandatory' => true]);
                if ($state === 2) {
                    return;
                }
                $action->state($this->maker, $id, $this->version('maintenance_work_orders', $id), 'start', self::NOTE);
                $execution->recordVisit($this->maker, $id, $visit, 1, ['arrival_at' => $start->toIso8601String(), 'departure_at' => $start->addMinutes(30)->toIso8601String(),
                    'work_performed' => self::NOTE.' — تنفيذ مراجعة السيناريو', 'diagnosis' => 'سيناريو توضيحي لتوقف لوحة توزيع، دون فحص حقيقي',
                    'observations' => self::NOTE, 'outcome' => 'completed'], ['simulation_review' => ['result' => 'complete', 'notes' => self::NOTE]]);
                $execution->labor($this->maker, $id, $this->key('LABOR-'.$i), ['visit_id' => $visit, 'starts_at' => $start->toIso8601String(),
                    'ends_at' => $start->addMinutes(30)->toIso8601String(), 'work_type' => 'محاكاة صيانة', 'notes' => self::NOTE]);
                if ($state === 4) {
                    $action->state($this->maker, $id, $this->version('maintenance_work_orders', $id), 'hold', self::NOTE.' — انتظار مراجعة');
                } elseif ($state >= 5) {
                    $action->state($this->maker, $id, $this->version('maintenance_work_orders', $id), 'complete', self::NOTE);
                    if ($state >= 6 && $this->canIssue) {
                        $action->state($this->reviewer, $id, $this->version('maintenance_work_orders', $id), 'verify', self::NOTE);
                    }
                    if ($state === 7 && $this->canIssue) {
                        $action->state($this->reviewer, $id, $this->version('maintenance_work_orders', $id), 'close', self::NOTE);
                    }
                }
            });
        }
        $this->command?->info('Maintenance: 120 requests, 90 work orders, assignments, visits and execution states ready.');
        if ($this->canIssue) {
            for ($i = 0; $i < 90; $i++) {
                if (! in_array($i % 9, [6, 7], true)) {
                    continue;
                }
                DB::transaction(function () use ($i, $action) {
                    $w = DB::table('maintenance_work_orders')->where('creation_key', $this->key('WO-'.$i))->firstOrFail();
                    if ($w->status === 'work_completed') {
                        $action->state($this->reviewer, $w->id, $w->version, 'verify', self::NOTE);
                    }
                    $w = DB::table('maintenance_work_orders')->find($w->id);
                    if ($i % 9 === 7 && $w->status === 'verified') {
                        $action->state($this->reviewer, $w->id, $w->version, 'close', self::NOTE);
                    }
                });
            }
        }
    }

    private function maintenanceIdentity(int $i, $customers): array
    {
        return ['branch_id' => $this->branches[$i % count($this->branches)], 'customer_id' => $customers[$i % $customers->count()]->id,
            'responsible_id' => $this->maker, 'description' => self::NOTE.' — '.['صيانة إنارة', 'مراجعة لوحة توزيع', 'بلاغ فصل متكرر', 'مراجعة توصيلات'][$i % 4].' '.($i + 1),
            'priority' => ['low', 'normal', 'high', 'urgent'][$i % 4]];
    }

    private function version(string $table, int $id): int
    {
        return (int) DB::table($table)->where('id', $id)->value('version');
    }

    private function workshops($customers): void
    {
        $action = app(WorkshopActions::class);
        $execution = app(WorkshopExecution::class);
        $shops = [];
        foreach ($this->branches as $branch) {
            $code = 'DEMO-EG-OPS-WS-'.$branch;
            $id = DB::table('workshops')->where('code', $code)->value('id');
            if (! $id) {
                $id = $action->saveWorkshop($this->maker, null, 0, ['code' => $code, 'name' => 'ورشة محاكاة كهربائية — '.$branch,
                    'specialty' => self::NOTE, 'branch_id' => $branch, 'supervisor_id' => $this->reviewer, 'capacity' => 30,
                    'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '17:00']], 'active' => true]);
                $action->member($this->maker, $id, 1, $this->maker, true);
            }
            $shops[$branch] = $id;
        }
        for ($i = 0; $i < 100; $i++) {
            if (DB::table('workshop_intakes')->where('creation_key', $this->key('INTAKE-'.$i))->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $customers, $action, $execution, $shops) {
                $branch = $this->branches[$i % count($this->branches)];
                $start = $this->today->subDays(10)->addHours($i)->utc();
                $intake = $action->saveIntake($this->maker, null, 0, $this->key('INTAKE-'.$i), ['branch_id' => $branch,
                    'customer_id' => $customers[$i % $customers->count()]->id, 'equipment_description' => 'DEMO-EG-OPS — معدة توضيحية '.($i + 1),
                    'model' => 'DEMO-PANEL-'.($i % 8 + 1), 'serial_number' => 'DEMO-EG-OPS-SERIAL-'.($i + 1),
                    'accessories' => 'ملحقات محاكاة فقط', 'reported_fault' => 'سيناريو فصل متكرر للتوضيح فقط', 'arrival_condition' => self::NOTE,
                    'customer_owned' => true, 'location' => 'استقبال ورشة المحاكاة']);
                $state = $i % 10;
                if ($state === 0) {
                    return;
                }
                $action->receive($this->maker, $intake, 1, $this->key('CUSTODY-'.$i), $start->subHour()->toIso8601String(), self::NOTE);
                if ($state === 1) {
                    return;
                }
                $job = $action->createJob($this->maker, $intake, 2, $this->key('JOB-'.$i), $shops[$branch], 'normal', 'nonbillable');
                $action->assign($this->maker, $job, 1, $shops[$branch], $this->maker, self::NOTE);
                $action->diagnose($this->maker, $job, $this->version('workshop_jobs', $job), ['required_tests' => [['name' => 'مراجعة توثيق المحاكاة، ليست معايرة أو اختبار سلامة', 'required' => true]],
                    'fault_classification' => 'سيناريو توضيحي', 'diagnosis' => self::NOTE, 'planned_work' => 'استكمال خطوات محاكاة الإصلاح فقط']);
                if ($state === 2) {
                    return;
                }
                if ($state === 9) {
                    $execution->unrepairable($this->reviewer, $job, $this->version('workshop_jobs', $job), self::NOTE.' — سيناريو تعذر الإصلاح');
                    if ($this->canIssue) {
                        $execution->deliver($this->reviewer, $job, $this->version('workshop_jobs', $job), $this->key('DELIVERY-'.$i),
                            $this->delivery($start->addMinutes(45), true));
                    }

                    return;
                }
                app(RepairOffers::class)->nonbillable($this->reviewer, $job, $this->version('workshop_jobs', $job), 'goodwill', null, self::NOTE.' — محاكاة خدمة مجاملة بلا قيمة مالية');
                if ($state === 3) {
                    return;
                }
                $execution->state($this->maker, $job, $this->version('workshop_jobs', $job), 'start', self::NOTE);
                if ($state === 4) {
                    return;
                }
                if ($state === 5) {
                    $execution->state($this->maker, $job, $this->version('workshop_jobs', $job), 'waiting_parts', self::NOTE.' — انتظار قطعة افتراضية دون طلب مخزني');

                    return;
                }
                $execution->record($this->maker, $job, $this->version('workshop_jobs', $job), $this->key('REPAIR-'.$i),
                    ['started_at' => $start->toIso8601String(), 'ended_at' => $start->addMinutes(30)->toIso8601String(),
                        'work_performed' => self::NOTE, 'observations' => self::NOTE, 'work_type' => 'محاكاة توثيق إصلاح']);
                $execution->state($this->maker, $job, $this->version('workshop_jobs', $job), 'testing', self::NOTE);
                if ($state === 6) {
                    return;
                }
                $execution->test($this->maker, $job, $this->version('workshop_jobs', $job), ['name' => 'مراجعة توثيق المحاكاة، ليست معايرة أو اختبار سلامة',
                    'outcome' => 'passed', 'results' => self::NOTE.' — نجاح تسلسل السيناريو فقط؛ لا نتائج معدات حقيقية']);
                if ($this->canIssue) {
                    $execution->verify($this->reviewer, $job, $this->version('workshop_jobs', $job), self::NOTE, 'لا أعطال حقيقية جرى تقييمها');
                }
                if ($state === 8 && $this->canIssue) {
                    $execution->deliver($this->reviewer, $job, $this->version('workshop_jobs', $job), $this->key('DELIVERY-'.$i), $this->delivery($start->addMinutes(45), false));
                }
            });
        }
        if ($this->canIssue) {
            for ($i = 0; $i < 100; $i++) {
                if (! in_array($i % 10, [7, 8, 9], true)) {
                    continue;
                }
                DB::transaction(function () use ($i, $execution) {
                    $job = DB::table('workshop_jobs')->where('creation_key', $this->key('JOB-'.$i))->firstOrFail();
                    if ($i % 10 !== 9 && $job->status === 'testing') {
                        $execution->verify($this->reviewer, $job->id, $job->version, self::NOTE, 'لا أعطال حقيقية جرى تقييمها');
                    }
                    $job = DB::table('workshop_jobs')->find($job->id);
                    if (($i % 10 === 8 && $job->status === 'verified') || ($i % 10 === 9 && $job->status === 'rejected')) {
                        $execution->deliver($this->reviewer, $job->id, $job->version, $this->key('DELIVERY-'.$i),
                            $this->delivery($this->today->subDays(10)->addHours($i)->addMinutes(45)->utc(), $i % 10 === 9));
                    }
                });
            }
        }
        $this->command?->info('Workshop: 100 intake scenarios and 80 repair jobs ready; official stages follow company setup.');
    }

    private function delivery(CarbonImmutable $time, bool $unrepaired): array
    {
        return ['recipient' => 'مستلم تجريبي غير حقيقي', 'accessories' => 'ملحقات المحاكاة', 'final_condition' => self::NOTE,
            'acknowledgment' => self::NOTE, 'delivered_at' => $time->toIso8601String(), 'unrepaired' => $unrepaired];
    }

    private function employees(int $owner): array
    {
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $code = 'DEMO-EG-OPS-EMP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $branch = $this->branches[$i % count($this->branches)];
            $ids[] = ['id' => DB::table('organization_employees')->where('code', $code)->value('id')
                ?? app(EmployeeActions::class)->save($owner, null, 0, ['code' => $code, 'name' => EgyptianMarket::person($i).' — موظف محاكاة',
                    'branch_id' => $branch, 'starts_on' => $this->today->subYear()->toDateString(), 'active' => true, 'job_title' => 'موظف تجريبي، لا حساب دخول أو رواتب']), 'branch' => $branch];
        }

        return $ids;
    }

    private function contracts($customers, array $employees, int $service): void
    {
        $action = app(ServiceContractActions::class);
        $fixture = tempnam(sys_get_temp_dir(), 'erp-demo-sign-');
        $im = imagecreatetruecolor(900, 120);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagestring($im, 5, 20, 30, 'DEMO-EG-OPS: SIMULATION ONLY. NO LEGAL SIGNATURE OR AGREEMENT.', imagecolorallocate($im, 180, 20, 20));
        imagepng($im, $fixture);
        imagedestroy($im);
        try {
            for ($i = 0; $i < 64; $i++) {
                if (DB::table('service_contracts')->where('creation_key', $this->key('SC-'.$i))->exists()) {
                    continue;
                }
                $files = [];
                try {
                    DB::transaction(function () use ($i, $customers, $employees, $service, $action, $fixture, &$files) {
                        $employee = $employees[$i % count($employees)];
                        $amount = (12000 + $i * 1000).'.00';
                        $installments = [];
                        for ($j = 0; $j < 4; $j++) {
                            $installments[] = ['code' => 'Q'.($j + 1), 'due_date' => $this->today->addDays($j * 80)->toDateString(),
                                'amount' => bcdiv($amount, '4', 2), 'description' => 'قسط محاكاة غير مفوتر'];
                        }
                        $id = $action->save($this->maker, null, 0, $this->key('SC-'.$i), ['branch_id' => $employee['branch'],
                            'customer_id' => $customers[$i % $customers->count()]->id, 'responsible_employee_id' => $employee['id'],
                            'title' => 'DEMO-EG-OPS — عقد صيانة افتراضي '.($i + 1), 'starts_on' => $this->today->subDays(30)->toDateString(),
                            'ends_on' => $this->today->addDays(335)->toDateString(), 'value' => $amount, 'service_product_id' => $service,
                            'billing_mode' => 'installments', 'terms' => self::NOTE, 'sla_response_minutes' => 240, 'sla_resolution_minutes' => 1440,
                            'covered_labor_minutes' => 960, 'coverage' => [['equipment_ref' => 'DEMO-EG-OPS-PANEL-'.($i + 1), 'site_id' => null, 'preventive_visits' => 4, 'corrective_visits' => 2]],
                            'preventive_schedule' => [['code' => 'PV1', 'equipment_ref' => 'DEMO-EG-OPS-PANEL-'.($i + 1), 'site_id' => null,
                                'due_date' => $this->today->addDays(14)->toDateString(), 'description' => self::NOTE]],
                            'parts' => [], 'excluded_parts' => [], 'boq' => [], 'schedule' => $installments]);
                        $state = $i % 8;
                        if ($state === 0) {
                            return;
                        }
                        $action->submit($this->maker, $id, $this->version('service_contracts', $id));
                        if ($state === 1) {
                            return;
                        }
                        $action->approve($this->reviewer, $id, $this->version('service_contracts', $id));
                        if ($state === 2) {
                            return;
                        }
                        $document = app(ServiceContractDocuments::class)->store($this->maker, $id,
                            new UploadedFile($fixture, 'DEMO-NO-LEGAL-SIGNATURE.png', 'image/png', null, true), 'signature', 'DEMO-EG-OPS — دليل محاكاة فقط بلا توقيع');
                        $files[] = DB::table('service_contract_documents')->where('id', $document)->value('path');
                        $action->sign($this->reviewer, $id, $this->version('service_contracts', $id), $document, $this->today->toDateString());
                        if ($state === 3) {
                            return;
                        }
                        $action->activate($this->reviewer, $id, $this->version('service_contracts', $id));
                        if ($state >= 5) {
                            $action->state($this->reviewer, $id, $this->version('service_contracts', $id), [5 => 'suspended', 6 => 'terminated', 7 => 'completed'][$state], self::NOTE);
                        }
                    });
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete($files);
                    throw $e;
                }
            }
        } finally {
            unlink($fixture);
        }
        $this->command?->info('Service contracts: 64 revision/approval/signature-simulation/activation/state graphs ready.');
    }

    private function attendance(array $employees): void
    {
        $action = app(AttendanceActions::class);
        $shifts = [];
        foreach ($this->branches as $branch) {
            $code = 'DEMO-EG-OPS-SHIFT-'.$branch;
            $shifts[$branch] = DB::table('attendance_shifts')->where('code', $code)->value('id')
                ?? $action->saveShift($this->maker, null, 0, ['branch_id' => $branch, 'code' => $code, 'name' => 'وردية محاكاة من 9 إلى 17',
                    'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30, 'grace_minutes' => 0]);
        }
        foreach ($employees as $i => $employee) {
            for ($j = 1; $j <= 20; $j++) {
                $key = $this->key('ATT-'.$i.'-'.$j);
                if (DB::table('attendance_schedules')->where('creation_key', $key)->exists()) {
                    continue;
                }
                DB::transaction(function () use ($action, $i, $j, $key, $employee, $shifts) {
                    $date = $this->today->subDays(21 - $j);
                    $schedule = $action->schedule($this->maker, $employee['id'], $shifts[$employee['branch']], $date->toDateString(), $key);
                    if (($i + $j) % 5 === 0) {
                        $adjustment = $action->requestAdjustment($this->maker, $schedule, 'leave', ['reason' => self::NOTE], $this->key('LEAVE-'.$i.'-'.$j));
                        if ($i % 3 !== 0) {
                            $action->approveAdjustment($this->reviewer, $adjustment, 1, $i % 3 === 1, self::NOTE);
                        }

                        return;
                    }
                    $start = $date->setTime(9, ($i + $j) % 3 === 0 ? 15 : 0);
                    foreach ([['in', $start], ['break_start', $date->setTime(13, 0)], ['break_end', $date->setTime(13, 30)], ['out', $date->setTime(17, 0)]] as $n => [$kind, $time]) {
                        if ($kind === 'out' && ($i + $j) % 7 === 0) {
                            continue;
                        }
                        $action->punch($this->maker, $employee['id'], $time->toIso8601String(), $kind, $this->key('PUNCH-'.$i.'-'.$j.'-'.$n));
                    }
                });
            }
        }
        $this->command?->info('Attendance: 30 synthetic employees, 600 schedules, varied attendance and reviewed leave requests ready.');
    }

    private function tenders($customers): void
    {
        $action = app(TenderActions::class);
        for ($i = 0; $i < 60; $i++) {
            $key = $this->key('TENDER-'.$i);
            if (DB::table('project_tenders')->where('creation_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $key, $customers, $action) {
                $id = $action->save($this->maker, null, 0, $key, ['external_reference' => 'DEMO-EG-OPS-TN-'.($i + 1),
                    'title' => 'مناقصة محاكاة '.['توريد لوحات', 'تركيب إنارة', 'صيانة مواقع', 'توريد وتركيب'][$i % 4].' '.($i + 1),
                    'issuer' => 'جهة افتراضية — DEMO-EG-OPS', 'description' => self::NOTE, 'customer_id' => $customers[$i % $customers->count()]->id,
                    'category' => ['supply', 'project', 'service', 'mixed'][$i % 4], 'branch_id' => $this->branches[$i % count($this->branches)],
                    'responsible_id' => $this->maker, 'published_on' => $this->today->toDateString(), 'requirements' => self::NOTE,
                    'deadlines' => ['final_at' => $this->today->addDays(30)->toIso8601String()]]);
                $state = $i % 6;
                if ($state === 0) {
                    return;
                }
                $action->transition($this->maker, $id, $this->version('project_tenders', $id), 'under_review', self::NOTE);
                if ($state === 1) {
                    return;
                }
                if ($state === 2 || $state === 3) {
                    $action->transition($this->maker, $id, $this->version('project_tenders', $id), $state === 2 ? 'no_bid' : 'cancelled', self::NOTE);
                } else {
                    $action->transition($this->maker, $id, $this->version('project_tenders', $id), 'preparing_technical', self::NOTE);
                    if ($state === 5) {
                        $action->transition($this->maker, $id, $this->version('project_tenders', $id), 'withdrawn', self::NOTE);
                    }
                }
            });
        }
        $this->command?->info('Tenders: 60 sourcing/review/preparation/no-bid/withdrawal scenarios ready.');
    }

    private function supply(User $owner, $products): void
    {
        $warehouses = DB::table('inventory_warehouses')->whereIn('branch_id', $this->branches)->where('active', true)
            ->whereIn('id', DB::table('user_warehouse_scopes')->where('user_id', $owner->id)->select('warehouse_id'))->orderBy('id')->get();
        if ($warehouses->count() < 2) {
            throw new \RuntimeException('Two existing owner-scoped demo warehouses are required.');
        }
        DB::transaction(function () use ($owner, $warehouses) {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($owner);
            foreach ($warehouses as $warehouse) {
                if (! DB::table('user_warehouse_scopes')->where('user_id', $this->reviewer)->where('warehouse_id', $warehouse->id)->exists()) {
                    abort_if(DB::table('access_audits')->where('subject_user_id', $this->reviewer)->where('action', 'scope.warehouse_revoked')
                        ->whereJsonContains('before_snapshot->warehouses', $warehouse->id)->exists(), 409, 'A demo warehouse scope was revoked; it will not be restored.');
                    app(SetUserWarehouseScope::class)->execute($owner, $this->reviewer, $warehouse->id, true);
                }
                foreach (['receipt_approve', 'issue_approve', 'transfer_approve', 'adjustment_approve'] as $action) {
                    $code = 'M06.'.$action;
                    if (! isset(PermissionCatalog::DEFINITIONS[$code])) {
                        throw new \RuntimeException('Unknown warehouse approval permission.');
                    }
                    if (DB::table('access_grants')->where('user_id', $this->reviewer)->where('branch_id', $warehouse->branch_id)->where('permission_code', $code)->exists()) {
                        continue;
                    }
                    abort_if(DB::table('access_audits')->where('subject_user_id', $this->reviewer)->where('action', 'local_cli.operational_demo_permission_granted')
                        ->where('after_snapshot->permission_code', $code)->where('after_snapshot->branch_id', $warehouse->branch_id)->exists(), 409, 'A demo approval grant was revoked.');
                    DB::table('access_grants')->insert(['user_id' => $this->reviewer, 'permission_code' => $code, 'branch_id' => $warehouse->branch_id,
                        'created_at' => now(), 'updated_at' => now()]);
                    $guard->audit($owner, $this->reviewer, 'local_cli.operational_demo_permission_granted', [],
                        ['dataset' => 'DEMO-EG-OPS', 'permission_code' => $code, 'branch_id' => $warehouse->branch_id]);
                }
            }
        });
        PermissionAccess::forgetRequestSnapshot();
        $receiving = app(PurchaseReceiving::class);
        $orders = DB::table('purchasing_orders')->whereIn('branch_id', $warehouses->pluck('branch_id'))->where('status', 'approved')
            ->where('notes', 'like', 'DEMO-EG-FLOW%')->orderBy('id')->get();
        foreach ($orders as $i => $order) {
            $key = $this->key('PURCHASE-RECEIPT-'.$order->id);
            if (DB::table('purchasing_receipts')->where('creation_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($receiving, $order, $key, $warehouses, $owner, $i) {
                $lines = DB::table('purchasing_order_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
                    ->where('l.order_id', $order->id)->orderBy('l.id')->get(['l.id', 'l.quantity', 'p.tracking']);
                $payload = [];
                foreach ($lines as $line) {
                    $serials = [];
                    if ($line->tracking === 'serial') {
                        for ($j = 1; $j <= (int) $line->quantity; $j++) {
                            $serials[] = 'DEMO-EG-OPS-RCV-'.$order->id.'-'.$line->id.'-'.$j;
                        }
                    }
                    $payload[] = ['order_line_id' => $line->id, 'quantity' => $line->quantity, 'serials' => $serials];
                }
                $id = $receiving->save($owner->id, ['creation_key' => $key, 'order_id' => $order->id,
                    'warehouse_id' => $warehouses->firstWhere('branch_id', $order->branch_id)->id,
                    'receipt_date' => $this->today->toDateString(), 'delivery_reference' => 'DEMO-EG-OPS-RCV-'.$order->id,
                    'notes' => self::NOTE.' — طلب استلام دون حركة مخزون', 'lines' => $payload], null, 0);
                if ($i % 2 === 1) {
                    $receiving->submit($owner->id, $id, 1);
                }
            });
        }
        $documents = app(InventoryDocuments::class);
        $kinds = ['opening', 'issue', 'transfer', 'adjustment', 'count'];
        for ($i = 0; $i < 100; $i++) {
            $key = $this->key('STOCK-DOC-'.$i);
            if (DB::table('inventory_documents')->where('creation_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $key, $documents, $warehouses, $products, $owner, $kinds) {
                $kind = $kinds[intdiv($i, 20)];
                $warehouse = $warehouses[$i % $warehouses->count()];
                $lines = [];
                for ($j = 0; $j < 4; $j++) {
                    $product = $products[($i * 4 + $j) % $products->count()];
                    $lines[] = ['product_id' => $product->id, 'quantity' => $kind === 'count' ? '0' : (10 + $j).'.00000000']
                        + ($kind === 'adjustment' ? ['unit_cost' => '75.00', 'cost_reference' => self::NOTE.' — مقترح تقييم غير مرحل'] : []);
                }
                $doc = $documents->save($owner->id, $kind, ['creation_key' => $key, 'warehouse_id' => $warehouse->id,
                    'destination_id' => $kind === 'transfer' ? $warehouses[($i + 1) % $warehouses->count()]->id : null,
                    'reason' => self::NOTE, 'lines' => $lines]);
                $state = $i % 4;
                if ($state === 3) {
                    $documents->transition($owner->id, $doc['id'], 'cancel', 1);
                } elseif ($state > 0) {
                    $documents->transition($owner->id, $doc['id'], 'submit', 1);
                    if ($state === 2 && $kind !== 'count') {
                        $documents->transition($this->reviewer, $doc['id'], 'approve', 2);
                    }
                }
            });
        }
        $this->command?->info('Supply: linked purchase receipt requests and 100 stock-document review scenarios ready; no physical/GL posting.');
    }
}

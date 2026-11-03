<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SaveUser;
use App\Modules\Organization\Application\SetUserBranchScope;
use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\ProjectExecution;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Domain\Project;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\RequisitionActions;
use App\Modules\Purchasing\Application\RfqActions;
use App\Modules\Purchasing\Application\SupplierOffers;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Owner-authorized simulated workflows, explicitly invoked separately from ordinary demo seeding. */
class ProjectsPurchasingWorkflowDemoSeeder extends Seeder
{
    private const NOTE = 'DEMO-EG-FLOW — محاكاة تجريبية محلية بإذن المالك، لا تمثل أعمالًا أو التزامًا تجاريًا حقيقيًا';

    private const READ = ['M02.product_view', 'M03.customer_view', 'M03.supplier_view', 'M05.requisition_view',
        'M05.order_view', 'M05.rfq_view', 'M05.offer_view', 'M07.project_view', 'M07.budget_view'];

    private const MAKER = ['M05.requisition_create', 'M05.requisition_submit', 'M05.order_create', 'M05.order_submit',
        'M05.rfq_create', 'M05.rfq_issue', 'M05.offer_create', 'M05.offer_update', 'M05.award_propose',
        'M07.project_create', 'M07.project_manage', 'M07.project_activate', 'M07.project_close', 'M07.budget_manage',
        'M07.financial_view', 'M05.receipt_view', 'M11.journal_view', 'M12.ap_view', 'M12.ar_view'];

    private const REVIEWER = ['M05.requisition_approve', 'M05.order_approve', 'M05.award_approve', 'M07.budget_approve'];

    public function run(): void
    {
        $connection = DB::connection();
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== $expected || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
            || (string) env('ERP_DEMO_WORKFLOWS') !== '1') {
            throw new \RuntimeException('Explicit local/testing workflow authorization requires ERP_DEMO_WORKFLOWS=1.');
        }
        $owner = User::findOrFail((int) env('ERP_DEMO_ACTOR_ID', 2));
        app(RbacGuard::class)->authorize($owner);
        $scopes = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($owner->id, 'M07.project_view'));
        $purchaseScopes = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($owner->id, 'M05.order_view'));
        $branches = DB::table('branches')->whereIn('id', array_intersect(array_column($scopes, 'id'), array_column($purchaseScopes, 'id')))
            ->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->limit(5)->pluck('id')->all();
        $customers = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->limit(100)->get();
        $suppliers = DB::table('suppliers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->limit(100)->get();
        $products = DB::table('products')->where('sku', 'like', 'DEMO-EG%')->where('kind', 'bulk')->where('active', true)->orderBy('id')->limit(300)->get();
        if ($branches === [] || $customers->isEmpty() || $suppliers->count() < 3 || $products->count() < 8) {
            throw new \RuntimeException('Existing scoped Egyptian demo masters are required.');
        }
        [$maker, $reviewer] = DB::transaction(function () use ($owner, $branches) {
            app(RbacGuard::class)->lock();

            return [
                $this->identity($owner, 'maker', 'مُعد سيناريوهات الديمو', array_merge(self::READ, self::MAKER), $branches),
                $this->identity($owner, 'reviewer', 'مراجع سيناريوهات الديمو', array_merge(self::READ, self::REVIEWER), $branches),
            ];
        });

        $created = ['projects' => 0, 'requisitions' => 0, 'orders' => 0, 'rfqs' => 0, 'offers' => 0];
        $types = ['توريد فقط', 'تركيب فقط', 'توريد وتركيب', 'صيانة كهربائية', 'إحلال وتجديد', 'إنارة داخلية وخارجية', 'لوحات توزيع وتحكم', 'شبكات كابلات', 'أنظمة تيار خفيف', 'طاقة شمسية'];
        $states = ['draft', 'planned', 'active', 'in_progress', 'on_hold', 'completed', 'closed', 'cancelled'];
        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        for ($i = 0; $i < 80; $i++) {
            $code = 'DEMO-EG-FLOW-PRJ-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            if (Project::where('code', $code)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $code, $maker, $reviewer, $types, $states, $today, $branches, $customers) {
                $actions = app(ProjectActions::class);
                $planning = app(ProjectPlanning::class);
                $execution = app(ProjectExecution::class);
                $customer = $customers[$i % $customers->count()];
                $type = $types[intdiv($i, 8)];
                $state = $states[$i % 8];
                $start = $today->subDays(60 + $i % 30);
                $end = $today->addDays(30 + $i % 30);
                $p = $actions->save($maker, null, 0, $this->key($code), [
                    'code' => $code, 'name' => 'محاكاة '.$type.' — '.$customer->name.' — '.($i + 1),
                    'project_type' => $type, 'priority' => ['normal', 'high', 'urgent', 'low'][$i % 4],
                    'branch_id' => $branches[$i % count($branches)], 'customer_id' => $customer->id,
                    'manager_id' => $maker, 'members' => [$reviewer],
                    'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
                    'description' => self::NOTE.' — '.$type, 'notes' => self::NOTE,
                ]);
                foreach (['المعاينة والتخطيط', 'الأعمال الكهربائية التجريبية', 'المراجعة والتسليم التجريبي'] as $title) {
                    $actions->phase($maker, $p->id, $p->fresh()->version, ['title' => $title,
                        'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
                    $phase = DB::table('project_phases')->where('project_id', $p->id)->orderByDesc('id')->first();
                    foreach (['إعداد ومراجعة الخطة', 'تنفيذ خطوة محاكاة'] as $task) {
                        $actions->task($maker, $p->id, $p->fresh()->version, ['phase_id' => $phase->id,
                            'title' => $task.' — '.$title, 'assigned_to' => $maker,
                            'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(), 'notes' => self::NOTE]);
                    }
                    $execution->milestone($maker, $p->id, $p->fresh()->version, ['title' => 'إتمام محاكاة '.$title,
                        'target_date' => $end->toDateString(), 'responsible_id' => $maker, 'required' => true]);
                }
                if ($state === 'draft') {
                    return;
                }
                if ($state === 'cancelled') {
                    $planning->transition($maker, $p->id, $p->fresh()->version, 'cancelled', self::NOTE.' — إلغاء السيناريو قبل التنفيذ');

                    return;
                }
                $budget = (150000 + $i * 12500).'.00';
                $planning->propose($maker, $p->id, $p->fresh()->version, $this->key($code.'-budget'), self::NOTE,
                    ['currency' => 'EGP', 'total' => $budget, 'scope' => self::NOTE.' — '.$type,
                        'categories' => [['name' => 'ميزانية تخطيط المحاكاة', 'amount' => $budget]], 'phases' => []]);
                $change = DB::table('project_changes')->where('project_id', $p->id)->where('status', 'pending')->value('id');
                $planning->decide($reviewer, $p->id, $p->fresh()->version, $change, true, self::NOTE.' — مراجعة مستقلة');
                $planning->transition($maker, $p->id, $p->fresh()->version, 'planned', self::NOTE);
                if ($state === 'planned') {
                    return;
                }
                $planning->transition($maker, $p->id, $p->fresh()->version, 'active', self::NOTE);
                if ($state === 'active') {
                    return;
                }
                $planning->transition($maker, $p->id, $p->fresh()->version, 'in_progress', self::NOTE);
                foreach (DB::table('project_tasks')->where('project_id', $p->id)->orderBy('id')->get() as $j => $task) {
                    $status = in_array($state, ['completed', 'closed'], true) || $j < 2 ? 'completed' : ($j === 2 ? 'in_progress' : 'pending');
                    if ($status !== 'pending') {
                        $actions->taskStatus($maker, $p->id, $task->id, $task->version, $status, self::NOTE, $status === 'in_progress' ? 50 : null);
                    }
                }
                if ($state === 'on_hold') {
                    $planning->transition($maker, $p->id, $p->fresh()->version, 'on_hold', self::NOTE.' — انتظار مراجعة جدول المحاكاة');

                    return;
                }
                if ($state === 'in_progress') {
                    return;
                }
                foreach (DB::table('project_milestones')->where('project_id', $p->id)->get() as $milestone) {
                    $execution->milestoneStatus($maker, $p->id, $milestone->id, $milestone->version, 'completed', self::NOTE.' — إنجاز سيناريو المحاكاة فقط');
                }
                $execution->handover($maker, $p->id, $p->fresh()->version, self::NOTE.' — تسليم تجريبي داخل النظام دون أعمال ميدانية');
                $planning->transition($maker, $p->id, $p->fresh()->version, 'completed', self::NOTE);
                if ($state === 'closed') {
                    $planning->transition($maker, $p->id, $p->fresh()->version, 'closed', self::NOTE.' — إغلاق سيناريو خال من الالتزامات');
                }
            });
            $created['projects']++;
            if (($i + 1) % 20 === 0) {
                $this->command?->info('Project scenarios ready: '.($i + 1));
            }
        }

        for ($i = 0; $i < 160; $i++) {
            $branch = $branches[$i % count($branches)];
            $lines = [];
            for ($j = 0; $j < 4 + $i % 3; $j++) {
                $product = $products[($i * 6 + $j) % $products->count()];
                $lines[] = ['product_id' => $product->id, 'quantity' => (10 + ($i + $j) % 70).'.00000000',
                    'unit_price' => (50 + ($i * 7 + $j * 19) % 900).'.00000000', 'discount_amount' => '0.00000000'];
            }
            $reqKey = $this->key('DEMO-EG-FLOW-REQ-'.($i + 1));
            if (! DB::table('purchase_requisitions')->where('creation_key', $reqKey)->exists()) {
                DB::transaction(function () use ($i, $maker, $reviewer, $today, $branch, $lines, $reqKey) {
                    $action = app(RequisitionActions::class);
                    $req = $action->save($maker, ['creation_key' => $reqKey, 'branch_id' => $branch,
                        'request_date' => $today->toDateString(), 'required_date' => $today->addDays(14)->toDateString(),
                        'department' => 'محاكاة تجهيز المواقع', 'purpose' => 'DEMO-EG-FLOW-REQ-'.($i + 1).' — احتياجات سيناريو أعمال كهربائية',
                        'notes' => self::NOTE, 'lines' => array_map(fn ($line) => array_intersect_key($line, array_flip(['product_id', 'quantity'])) + ['notes' => self::NOTE], $lines)], null, 0);
                    if ($i % 4 !== 0) {
                        $req = $action->transition($maker, $req->id, $req->version, 'submit', self::NOTE);
                        if ($i % 4 >= 2) {
                            $action->transition($reviewer, $req->id, $req->version, $i % 4 === 2 ? 'approve' : 'reject', self::NOTE.' — قرار مستقل لسيناريو الديمو');
                        }
                    }
                });
                $created['requisitions']++;
            }
            $orderKey = $this->key('DEMO-EG-FLOW-PO-'.($i + 1));
            if (! DB::table('purchasing_orders')->where('creation_key', $orderKey)->exists()) {
                DB::transaction(function () use ($i, $maker, $reviewer, $today, $branch, $suppliers, $lines, $orderKey) {
                    $action = app(PurchaseOrders::class);
                    $order = $action->save($maker, ['creation_key' => $orderKey, 'branch_id' => $branch,
                        'supplier_id' => $suppliers[$i % $suppliers->count()]->id, 'order_date' => $today->toDateString(),
                        'expected_date' => $today->addDays(21)->toDateString(), 'currency' => 'EGP',
                        'payment_terms' => 'محاكاة شروط توريد غير ملزمة', 'notes' => self::NOTE.' — أمر '.($i + 1), 'lines' => $lines], null, 0);
                    if ($i % 4 !== 0) {
                        $action->transition($maker, $order, 1, 'submit', self::NOTE);
                        if ($i % 4 >= 2) {
                            $action->transition($reviewer, $order, 2, $i % 4 === 2 ? 'approve' : 'reject', self::NOTE.' — قرار مستقل لسيناريو الديمو');
                        }
                    }
                });
                $created['orders']++;
            }
            if (($i + 1) % 40 === 0) {
                $this->command?->info('Purchasing scenarios ready: '.($i + 1));
            }
        }

        for ($i = 0; $i < 40; $i++) {
            $req = DB::table('purchase_requisitions')->where('creation_key', $this->key('DEMO-EG-FLOW-REQ-'.($i * 4 + 3)))->firstOrFail();
            $key = $this->key('DEMO-EG-FLOW-RFQ-'.($i + 1));
            if (DB::table('purchasing_rfqs')->where('creation_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($i, $key, $req, $maker, $reviewer, $today, $suppliers, &$created) {
                $rfqAction = app(RfqActions::class);
                $offerAction = app(SupplierOffers::class);
                $recipients = [];
                for ($j = 0; $j < 3; $j++) {
                    $recipients[] = $suppliers[($i * 3 + $j) % $suppliers->count()]->id;
                }
                $rfq = $rfqAction->save($maker, ['creation_key' => $key, 'requisition_id' => $req->id,
                    'subject' => 'DEMO-EG-FLOW — مقارنة توريد تجهيزات كهربائية '.($i + 1),
                    'request_date' => $today->toDateString(), 'deadline' => $today->addDays(10)->toDateString(),
                    'requirements' => self::NOTE, 'supplier_ids' => $recipients,
                    'lines' => DB::table('purchase_requisition_lines')->where('requisition_id', $req->id)->orderBy('id')
                        ->get()->map(fn ($line) => ['requisition_line_id' => $line->id, 'quantity' => $line->quantity])->all()], null, 0);
                if ($i % 4 === 0) {
                    return;
                }
                $rfqAction->issue($maker, $rfq->id, $rfq->version);
                foreach ($recipients as $j => $supplier) {
                    $offer = $offerAction->save($maker, ['creation_key' => $this->key('DEMO-EG-FLOW-OFFER-'.($i + 1).'-'.$j),
                        'rfq_id' => $rfq->id, 'supplier_id' => $supplier, 'supplier_reference' => 'DEMO-EG-FLOW-OFFER-'.($i + 1).'-'.($j + 1),
                        'document_date' => $today->toDateString(), 'valid_until' => $today->addDays(60)->toDateString(),
                        'currency' => 'EGP', 'lead_days' => 7 + $j * 3, 'payment_terms' => 'شروط افتراضية غير ملزمة', 'notes' => self::NOTE,
                        'lines' => DB::table('purchasing_rfq_lines')->where('rfq_id', $rfq->id)->orderBy('id')->get()
                            ->map(fn ($line) => ['rfq_line_id' => $line->id, 'quantity' => $line->quantity,
                                'unit_price' => (100 + $i * 7 + $j * 15).'.00000000', 'discount_amount' => '0.00000000', 'specification' => self::NOTE])->all()], null, 0);
                    $created['offers']++;
                    if ($j === 2) {
                        continue;
                    }
                    $offerAction->freeze($maker, $offer, 1);
                    if ($j === 0) {
                        $award = $offerAction->propose($maker, $offer, self::NOTE.' — مقارنة العرض ضمن سيناريو محلي');
                        if ($i % 3 !== 0) {
                            $offerAction->decide($reviewer, $award, 1, $i % 3 === 1);
                        }
                    }
                }
            });
            $created['rfqs']++;
        }
        $awards = DB::table('purchasing_awards as a')->join('purchasing_offers as o', 'o.id', '=', 'a.offer_id')
            ->where('a.status', 'approved')->where('o.supplier_reference', 'like', 'DEMO-EG-FLOW-OFFER-%')
            ->where('a.created_by', $maker)->get(['a.id', 'a.offer_id', 'a.branch_id', 'o.supplier_id', 'o.payment_terms', 'o.reported_tax']);
        foreach ($awards as $award) {
            $key = $this->key('DEMO-EG-FLOW-AWARDED-PO-'.$award->id);
            if (DB::table('purchasing_orders')->where('creation_key', $key)->exists()) {
                continue;
            }
            DB::transaction(function () use ($award, $key, $maker, $reviewer, $today) {
                $lines = DB::table('purchasing_offer_lines as l')->join('purchasing_rfq_product_lines as r', 'r.id', '=', 'l.rfq_line_id')
                    ->where('l.offer_id', $award->offer_id)->orderBy('l.id')->get(['r.product_id', 'l.quantity', 'l.unit_price', 'l.discount_amount'])
                    ->map(fn ($line) => (array) $line)->all();
                $action = app(PurchaseOrders::class);
                $order = $action->save($maker, ['creation_key' => $key, 'branch_id' => $award->branch_id,
                    'supplier_id' => $award->supplier_id, 'award_id' => $award->id, 'order_date' => $today->toDateString(),
                    'currency' => 'EGP', 'payment_terms' => $award->payment_terms, 'reported_tax' => $award->reported_tax,
                    'notes' => self::NOTE.' — أمر مرتبط بترسية محاكاة معتمدة', 'lines' => $lines], null, 0);
                $action->transition($maker, $order, 1, 'submit', self::NOTE);
                $action->transition($reviewer, $order, 2, 'approve', self::NOTE.' — مراجعة مستقلة لمطابقة الترسية');
            });
            $created['orders']++;
        }
        $this->command?->info('Workflow demo added: '.json_encode($created));
    }

    private function key(string $identity): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $identity)->toString();
    }

    private function identity(User $owner, string $kind, string $name, array $permissions, array $branches): int
    {
        $email = 'workflow.'.$kind.'@egypt-market.example.test';
        $existing = User::where('email', $email)->first();
        if ($existing) {
            $provisioned = DB::table('access_audits')->where('subject_user_id', $existing->id)
                ->where('action', 'local_cli.demo_identity_authorized')->where('after_snapshot->dataset', 'DEMO-EG-FLOW')->exists();
            if (! $provisioned || $existing->name !== 'DEMO-EG-FLOW — '.$name || ! $existing->active || ! $existing->email_verified_at) {
                throw new \RuntimeException('Existing workflow identity was changed; refusing to overwrite owner edits.');
            }

            return $existing->id;
        }
        // Owner explicitly authorized two scoped simulation identities; no reusable credentials are retained.
        $password = 'Demo!9'.bin2hex(random_bytes(32));
        $user = app(SaveUser::class)->execute($owner, ['name' => 'DEMO-EG-FLOW — '.$name, 'gender' => 'male',
            'email' => $email, 'branch_id' => $branches[0], 'password' => $password, 'password_confirmation' => $password]);
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => now()]);
        app(RbacGuard::class)->audit($owner, $user->id, 'local_cli.demo_identity_authorized', [],
            ['dataset' => 'DEMO-EG-FLOW', 'synthetic_identity' => true, 'verification' => 'owner-authorized local simulation; no email was sent']);
        foreach ($branches as $branch) {
            app(SetUserBranchScope::class)->execute($owner, $user->id, $branch, true);
        }
        foreach ($permissions as $code) {
            $scope = PermissionCatalog::DEFINITIONS[$code][1] ?? null;
            if (! in_array($scope, ['company', 'branch'], true)) {
                throw new \RuntimeException('Unknown workflow permission: '.$code);
            }
            foreach ($scope === 'company' ? [null] : $branches as $branch) {
                DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $code,
                    'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
                app(RbacGuard::class)->audit($owner, $user->id, 'local_cli.demo_permission_granted', [],
                    ['permission_code' => $code, 'branch_id' => $branch, 'dataset' => 'DEMO-EG-FLOW']);
            }
        }
        app(RecordAudit::class)->execute($owner->id, 'user', $user->id, 'demo.workflow_identity_created', null,
            ['dataset' => 'DEMO-EG-FLOW', 'purpose' => $kind, 'branches' => $branches], 'succeeded', 'Organization');
        PermissionAccess::forgetRequestSnapshot();

        return $user->id;
    }
}

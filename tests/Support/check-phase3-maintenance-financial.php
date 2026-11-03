<?php

// Source-driven M08 parts, labor and service-invoice checks. All writes roll back.
use App\Application\PostPurchaseReceipt;
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceBilling;
use App\Modules\FieldMaintenance\Application\MaintenanceCostProposals;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\FieldMaintenance\Application\MaintenanceParts;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\ProjectExecution;
use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$gate1MaintenanceHistory = getenv('GATE1_MAINTENANCE_HISTORY_CHECK') === '1';
$port = $gate1MaintenanceHistory ? '49685' : (getenv('PHASE3_FINANCIAL_PORT') ?: '55441');
$commitFixtures = getenv('PHASE3_FINANCIAL_COMMIT_FIXTURES') === '1';
if ($commitFixtures && ($port !== '55441' || $gate1MaintenanceHistory)) {
    throw new RuntimeException('ABORT before writes: committed race fixtures are allowed only on disposable port 55441.');
}
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.$port);
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = $port;
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
if ($gate1MaintenanceHistory) {
    $privateStorage = $root.'/.tmp/master-remediation-20261101/m08-history-storage-'.getmypid();
    foreach (['', '/app', '/framework/cache', '/framework/sessions', '/framework/views', '/logs', '/app/private/mpdf'] as $suffix) {
        $path = $privateStorage.$suffix;
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create the private M08 history probe storage path.');
        }
    }
    $app->useStoragePath($privateStorage);
}
$app->make(Kernel::class)->bootstrap();
Carbon::setTestNow(CarbonImmutable::now('Africa/Cairo')->addYear()->startOfDay()->subDay()->addHours(12));
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => (int) $port,
    'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$conn = DB::selectOne('select current_database() db, inet_server_addr()::text address, inet_server_port() port');
if (DB::connection()->getDriverName() !== 'pgsql' || $conn->db !== 'erp_elec_new_test' || ! in_array($conn->address, ['127.0.0.1', '127.0.0.1/32'], true) || (string) $conn->port !== (string) $port || (! $gate1MaintenanceHistory && ! in_array($port, ['55439', '55441'], true)) || ($gate1MaintenanceHistory && $port !== '49685')) {
    throw new RuntimeException('ABORT before writes: require disposable erp_elec_new_test PostgreSQL on loopback port 55439 or 55441.');
}
$gate1MaintenanceIdentity = null;
if ($gate1MaintenanceHistory) {
    require_once __DIR__.'/Gate1FinancialHistory.php';
    $gate1MaintenanceIdentity = Gate1FinancialHistory::assertOwnedDatabase($root);
}
$assert = 0;
$passed = [];
$evidence = [];
$maintenanceReturnHistoryEvidence = null;
$pass = static function (bool $ok, string $message) use (&$assert, &$passed): void {
    if (! $ok) {
        throw new RuntimeException($message);
    } $assert++;
    $passed[] = $message;
    fwrite(STDOUT, "PASS {$message}\n");
};
$reject = static function (callable $work, string $message, int $status) use ($pass): void {
    try {
        $work();
    } catch (Throwable $e) {
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === $status) {
            $pass(true, $message);

            return;
        } throw $e;
    } throw new RuntimeException('Expected denial: '.$message);
};
$uuid = static fn (): string => (string) Str::uuid();
$date = CarbonImmutable::now('Africa/Cairo')->addDay()->toDateString();
$maintenanceIssueDate = CarbonImmutable::parse($date, 'Africa/Cairo')->addDay()->toDateString();
$maintenanceReturnDate = CarbonImmutable::parse($maintenanceIssueDate, 'Africa/Cairo')->addDay()->toDateString();
$laborDate = $gate1MaintenanceHistory ? $maintenanceReturnDate : $date;
$billingDate = $gate1MaintenanceHistory ? $maintenanceReturnDate : $date;
$grant = static function (User $u, array $codes, ?int $branch = null): void {
    foreach ($codes as $c) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $u->id, 'permission_code' => $c, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
DB::beginTransaction();
$failure = null;
try {
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $approver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $tech = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $unratedTech = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $company = DB::table('companies')->where('id', 1)->first();
    if (! $company) {
        $grant($maker, ['M01.company_update']);
        app(SaveCompany::class)->execute($maker, ['code' => 'M08-FIN-TEST', 'name' => 'Synthetic M08 financial acceptance',
            'legal_name' => 'Synthetic M08 financial acceptance', 'tax_registration' => 'TEST-M08-FIN',
            'address' => 'Synthetic test-only address'], null);
    } elseif (! $company->setup_complete || ! $company->legal_name || ! $company->address) {
        throw new RuntimeException('Existing company setup is incomplete; M08 financial fixture will not modify it.');
    }
    $branch = Branch::factory()->create(['code' => 'DEMO-EG-M08F-'.strtoupper(Str::random(6))]);
    $customer = Customer::factory()->create(['status' => 'active']);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $stock = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'active' => true]);
    $activation = DB::table('accounting_activation')->where('id', 1)->first();
    if ((! $activation || $activation->activated_at === null) && $gate1MaintenanceHistory) {
        $gate1GoLiveDate = (string) DB::selectOne("select (now() at time zone 'Africa/Cairo')::date as date")->date;
        $grant($maker, ['M11.setup_manage', 'M11.account_manage', 'M11.tax_manage', 'M11.period_open', 'M11.opening_manage', 'M11.financial_activate']);
        $grant($approver, ['M11.opening_approve', 'M11.financial_activate']);
        $mapsForActivation = [];
        $setupActions = app(FinancialActivation::class);
        $accountActions = app(AccountingActions::class);
        foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
            $mapsForActivation[$purpose] = $accountActions->account((int) $maker->id, null, 0, [
                'code' => 'M08H-'.strtoupper($purpose).'-'.strtoupper(Str::random(4)), 'name' => 'Synthetic M08 history '.$label,
                'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true,
            ]);
        }
        $activationVersion = (int) ($activation->version ?? 1);
        $setupActions->save((int) $maker->id, $activationVersion, ['go_live_date' => $gate1GoLiveDate, 'mappings' => $mapsForActivation]);
        $activationVersion++;
        foreach (['sale', 'purchase'] as $taxType) {
            $setupActions->addTaxRule((int) $maker->id, $activationVersion, ['code' => 'M08H-'.strtoupper($taxType).'-'.strtoupper(Str::random(4)),
                'name' => 'Synthetic M08 history '.$taxType, 'transaction_type' => $taxType, 'product_id' => null, 'rate' => '14',
                'starts_on' => $gate1GoLiveDate, 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2,
                'legal_reference' => 'Rollback-only Gate1 M08 chronology fixture', 'purchase_recoverable' => $taxType === 'purchase']);
            $activationVersion++;
        }
        $setupActions->validateTaxTreatment((int) $maker->id, $activationVersion, 'Rollback-only Gate1 M08 chronology tax fixture');
        $activationVersion++;
        $periodYear = (int) substr($gate1GoLiveDate, 0, 4);
        $periodStart = $periodYear.'-01-01';
        $periodEnd = $periodYear.'-12-31';
        if (! DB::table('accounting_periods')->where('starts_on', '<=', $periodEnd)->where('ends_on', '>=', $periodStart)->exists()) {
            $accountActions->period((int) $maker->id, ['name' => 'Synthetic M08 history '.$periodYear, 'starts_on' => $periodStart, 'ends_on' => $periodEnd]);
        }
        Carbon::setTestNow(CarbonImmutable::parse($gate1GoLiveDate, 'Africa/Cairo')->startOfDay()->addHours(12));
        $zeroBalanceOpening = app(OpeningBalances::class);
        $openingBatch = $zeroBalanceOpening->save((int) $maker->id, $activationVersion, $uuid(), [], true,
            'Explicit synthetic no-balances declaration for rollback-only M08 chronology test.');
        $zeroBalanceOpening->advance((int) $maker->id, $openingBatch, 1, 'submit');
        $zeroBalanceOpening->advance((int) $approver->id, $openingBatch, 2, 'approve');
        app(ActivateFinancialPosting::class)->execute((int) $approver->id, $openingBatch, 3);
        $activation = DB::table('accounting_activation')->where('id', 1)->first();
    }
    if (! $activation || $activation->activated_at === null) {
        throw new RuntimeException('This financial check expects activated M11 mappings in the disposable cluster.');
    }
    $base = ['M01.branch_view', 'M03.customer_view', 'M03.supplier_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_create', 'M05.order_submit', 'M05.order_issue', 'M05.order_approve', 'M05.receipt_view', 'M05.receipt_create', 'M05.receipt_post', 'M06.receipt_create', 'M06.receipt_post', 'M06.reservation_manage', 'M06.reservation_view', 'M06.movement_view', 'M06.balance_view', 'M11.tax_calculate', 'M11.source_post', 'M11.account_manage', 'M11.journal_create', 'M11.journal_submit', 'M11.journal_post', 'M11.journal_approve', 'M11.journal_view', 'M11.report_view', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ar_view'];
    foreach ([$maker, $approver, $tech, $unratedTech] as $u) {
        DB::table('user_branch_scopes')->insert(['user_id' => $u->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($maker, [...$base, 'M05.receipt_create', 'M06.receipt_create', 'M06.warehouse_create', 'M06.reservation_manage', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.correction_execute', 'M11.tax_manage', 'M11.period_open', 'M11.journal_view', 'M08.workorder_manage', 'M08.standalone_create', 'M08.parts_request', 'M08.parts_issue', 'M08.parts_return', 'M08.billing_request', 'M08.cost_view', 'M08.labor_record', 'M08.assign', 'M08.schedule_override', 'M08.checklist_manage', 'M08.visit_record', 'M08.rate_approve', 'M08.billing_approve', 'M08.verify', 'M08.close', 'M08.reopen', 'M08.view', 'M07.project_create', 'M07.project_view', 'M07.project_update', 'M07.project_manage', 'M07.budget_manage', 'M07.project_activate', 'M07.project_close', 'M07.financial_view'], (int) $branch->id);
    $grant($approver, [...$base, 'M11.receipt_post', 'M08.rate_approve', 'M08.billing_approve', 'M08.verify', 'M08.close', 'M08.cost_view', 'M08.view', 'M07.project_view', 'M07.budget_approve'], (int) $branch->id);
    $grant($tech, ['M03.customer_view', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.movement_view', 'M06.reservation_view', 'M11.source_post', 'M11.journal_post', 'M08.workorder_manage', 'M08.labor_record', 'M08.visit_record', 'M08.parts_issue', 'M08.billing_request', 'M08.view', 'M07.project_view'], (int) $branch->id);
    $grant($unratedTech, ['M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.reservation_view', 'M06.reservation_manage', 'M06.movement_view', 'M11.source_post', 'M11.journal_post', 'M08.workorder_manage', 'M08.labor_record', 'M08.visit_record', 'M08.parts_request', 'M08.parts_issue'], (int) $branch->id);
    $grant($denied, ['M03.customer_view', 'M02.product_view', 'M06.movement_view'], (int) $branch->id);
    $grant($maker, ['M01.branch_view', 'M02.product_view', 'M03.customer_view', 'M03.supplier_view', 'M06.warehouse_create', 'M11.setup_manage', 'M11.tax_manage', 'M11.period_open', 'M11.account_manage', 'M11.tax_calculate', 'M11.source_post', 'M11.journal_create', 'M11.journal_submit', 'M11.journal_post', 'M11.journal_approve']);
    $grant($approver, ['M02.product_view', 'M03.customer_view', 'M03.supplier_view', 'M11.tax_calculate', 'M11.source_post', 'M11.journal_create', 'M11.journal_submit', 'M11.journal_post', 'M11.journal_approve']);
    $accounts = app(AccountingActions::class);
    $maps = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose')->all();
    $optional = app(FinancialActivation::class);
    foreach (['maintenance_labor' => ['Synthetic maintenance labor expense', 'expense'], 'maintenance_labor_accrual' => ['Synthetic maintenance labor accrual', 'liability']] as $purpose => [$label,$kind]) {
        if (! isset($maps[$purpose])) {
            $account = $accounts->account((int) $maker->id, null, 0, ['code' => 'M08F-'.strtoupper($purpose).'-'.strtoupper(Str::random(4)), 'name' => $label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
            $optional->saveOptionalMapping((int) $maker->id, $purpose, $account);
            $maps[$purpose] = $account;
        }
    }
    $periodYears = array_unique([(int) substr($date, 0, 4), (int) substr($laborDate, 0, 4)]);
    foreach ($periodYears as $periodYear) {
        $start = $periodYear.'-01-01';
        $end = $periodYear.'-12-31';
        if (! DB::table('accounting_periods')->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)->exists()) {
            $accounts->period((int) $maker->id, ['name' => 'Synthetic M08 '.$periodYear, 'starts_on' => $start, 'ends_on' => $end]);
        }
    }
    $setup = app(FinancialActivation::class);
    $version = (int) $activation->version;
    if (! DB::table('accounting_tax_rules')->where('transaction_type', 'purchase')->where('product_id', $stock->id)->exists()) {
        $setup->addTaxRule((int) $maker->id, $version, ['code' => 'M08F-PURCHASE-'.strtoupper(Str::random(5)), 'name' => 'Synthetic maintenance part VAT', 'transaction_type' => 'purchase', 'product_id' => $stock->id, 'rate' => '14', 'starts_on' => $date, 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic isolated M08 financial acceptance', 'purchase_recoverable' => true]);
        $version++;
    }
    Carbon::setTestNow(CarbonImmutable::parse($date, 'Africa/Cairo')->startOfDay()->addHours(12));
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $maker->id, ['code' => 'M08F-WH-'.strtoupper(Str::random(6)), 'name' => 'Synthetic maintenance stock warehouse', 'branch_id' => $branch->id, 'active' => true]);
    foreach ([$maker, $approver, $tech, $unratedTech] as $u) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $u->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $order = app(PurchaseOrders::class)->save((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'order_date' => $date, 'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => 'Synthetic', 'notes' => 'maintenance FIFO fixture', 'tax_mode' => 'exclusive', 'reported_tax' => null, 'lines' => [['product_id' => $stock->id, 'quantity' => '5.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00']]], null, 0);
    app(PurchaseOrders::class)->transition((int) $maker->id, $order, 1, 'submit');
    app(PurchaseOrders::class)->transition((int) $approver->id, $order, 2, 'approve');
    app(PurchaseOrders::class)->issue((int) $approver->id, $order);
    $line = (int) DB::table('purchasing_order_lines')->where('order_id', $order)->value('id');
    $receipt = app(PurchaseReceiving::class)->save((int) $maker->id, ['creation_key' => $uuid(), 'order_id' => $order, 'warehouse_id' => $warehouse['id'], 'receipt_date' => $date, 'delivery_reference' => 'M08F-RECEIPT', 'notes' => 'Synthetic stocked maintenance parts', 'lines' => [['order_line_id' => $line, 'quantity' => '5.00000000']]], null, 0);
    app(PurchaseReceiving::class)->submit((int) $maker->id, $receipt, 1);
    app(PostPurchaseReceipt::class)->execute((int) $approver->id, $receipt, 2);
    $projectEnd = CarbonImmutable::parse($date)->addDays(30)->toDateString();
    $project = app(ProjectActions::class)->save((int) $maker->id, null, 0, $uuid(), ['code' => 'M08F-PRJ-'.strtoupper(Str::random(6)), 'name' => 'Synthetic maintenance source attribution', 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'manager_id' => $maker->id, 'members' => [], 'start_date' => $date, 'end_date' => $projectEnd, 'description' => 'Project-linked M08 financial attribution fixture', 'notes' => '']);
    $planning = app(ProjectPlanning::class);
    $planning->propose((int) $maker->id, $project->id, $project->version, $uuid(), 'Synthetic approved project baseline', ['scope' => 'Electrical maintenance integration', 'currency' => 'EGP', 'total' => '100.00', 'categories' => [['name' => 'Maintenance services', 'amount' => '100.00']], 'phases' => [], 'structure' => []]);
    $project->refresh();
    $change = (int) DB::table('project_changes')->where('project_id', $project->id)->value('id');
    $planning->decide((int) $approver->id, $project->id, $project->version, $change, true, 'Independent synthetic budget approval.');
    $project->refresh();
    $planning->transition((int) $maker->id, $project->id, $project->version, 'planned', 'Approved synthetic budget baseline.');
    $project->refresh();
    $planning->transition((int) $maker->id, $project->id, $project->version, 'active', 'Synthetic project work begins.');
    $actions = app(MaintenanceActions::class);
    $work = $actions->createWorkOrder((int) $maker->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $tech->id, 'description' => 'Synthetic electrical service with stocked parts', 'priority' => 'normal', 'product_id' => null, 'project_id' => $project->id, 'billable' => true]);
    $assignment = app(MaintenanceExecution::class)->assign((int) $maker->id, $work, 1, ['technician_id' => $tech->id, 'starts_at' => now('UTC')->subHours(2)->toIso8601String(), 'ends_at' => now('UTC')->addHours(2)->toIso8601String()]);
    $execution = app(MaintenanceExecution::class);
    $template = $execution->saveTemplate((int) $maker->id, null, 0, ['branch_id' => $branch->id, 'code' => 'M08F-'.strtoupper(Str::random(6)), 'title' => 'Synthetic electrical checks', 'active' => true, 'items' => [['key' => 'safe', 'label' => 'Safe test', 'required' => true]]]);
    $workRow = DB::table('maintenance_work_orders')->find($work);
    $visit = $execution->schedule((int) $maker->id, $work, (int) $workRow->version, $uuid(), ['assignment_id' => $assignment, 'scheduled_at' => now('UTC')->subHour()->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $actions->state((int) $tech->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, 'start', 'Synthetic technician begins scheduled work.');
    $parts = app(MaintenanceParts::class);
    $req = $parts->requirement((int) $maker->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, ['product_id' => $stock->id, 'warehouse_id' => $warehouse['id'], 'quantity' => '2.00000000']);
    $wrong = app(InventoryActions::class)->saveWarehouse((int) $maker->id, ['code' => 'M08F-OTHER-'.strtoupper(Str::random(5)), 'name' => 'Synthetic wrong branch warehouse', 'branch_id' => $branch->id, 'active' => true]);
    $otherReq = DB::table('maintenance_part_requirements')->insertGetId(['work_order_id' => $work, 'product_id' => $stock->id, 'warehouse_id' => $wrong['id'], 'quantity' => '1.00000000', 'created_by' => $maker->id]);
    $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $stock->id)->first();
    $layers = DB::table('inventory_fifo_layers')->where('warehouse_id', $warehouse['id'])->where('product_id', $stock->id)->get();
    $pass($balance && bccomp($balance->on_hand, '5.00000000', 8) === 0 && $layers->sum('original_quantity') >= 5, 'posted M06 receipt creates five real on-hand FIFO units');
    $cancelWork = $actions->createWorkOrder((int) $maker->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $tech->id, 'description' => 'Unexecuted reservation cancellation reconciliation', 'priority' => 'normal', 'billable' => false]);
    $cancelAssignment = $execution->assign((int) $maker->id, $cancelWork, 1, ['technician_id' => $tech->id, 'starts_at' => now('UTC')->addHours(4)->toIso8601String(), 'ends_at' => now('UTC')->addHours(6)->toIso8601String()]);
    $cancelWorkVersion = (int) DB::table('maintenance_work_orders')->find($cancelWork)->version;
    $execution->schedule((int) $maker->id, $cancelWork, $cancelWorkVersion, $uuid(), ['assignment_id' => $cancelAssignment, 'scheduled_at' => now('UTC')->addHours(4)->addMinutes(5)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $cancelVersion = (int) DB::table('maintenance_work_orders')->find($cancelWork)->version;
    $cancelRequirement = $parts->requirement((int) $maker->id, $cancelWork, $cancelVersion, ['product_id' => $stock->id, 'warehouse_id' => $warehouse['id'], 'quantity' => '1.00000000']);
    $cancelReservation = $parts->reserve((int) $maker->id, $cancelWork, $cancelRequirement, '1.00000000', $uuid());
    $blockedCancelVersion = (int) DB::table('maintenance_work_orders')->find($cancelWork)->version;
    $reject(fn () => $actions->state((int) $maker->id, $cancelWork, $blockedCancelVersion, 'cancel', 'Customer canceled before field work.'), 'unexecuted work-order cancellation requires outstanding reservation release', 409);
    $pass(DB::table('maintenance_work_orders')->where('id', $cancelWork)->where('status', 'assigned')->where('version', $blockedCancelVersion)->exists() && bccomp(DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $stock->id)->value('on_hand'), '5.00000000', 8) === 0, 'blocked cancellation preserves assignment, version, and on-hand stock');
    $parts->release((int) $maker->id, $cancelWork, (int) $cancelReservation['id'], $uuid());
    $releasedVersion = (int) DB::table('maintenance_work_orders')->find($cancelWork)->version;
    $actions->state((int) $maker->id, $cancelWork, $releasedVersion, 'cancel', 'Customer canceled before field work.');
    $pass(DB::table('maintenance_work_orders')->where('id', $cancelWork)->where('status', 'cancelled')->exists() && bccomp(DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $stock->id)->value('on_hand'), '5.00000000', 8) === 0 && bccomp(DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $stock->id)->value('reserved'), '0', 8) === 0, 'released reservation allows cancellation without changing on-hand stock');
    $reject(fn () => $actions->state((int) $maker->id, $cancelWork, $releasedVersion, 'cancel', 'Customer canceled before field work.'), 'stale cancellation retry is rejected after the work-order version advances', 409);
    $reserve = $parts->reserve((int) $maker->id, $work, $req, '2.00000000', $uuid());
    $maintenanceBeforeIssueSnapshot = null;
    $maintenanceIssueSnapshot = null;
    $maintenanceReturnSnapshot = null;
    if ($gate1MaintenanceHistory) {
        $maintenanceBeforeIssueSnapshot = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        $beforeIssueValue = $maintenanceBeforeIssueSnapshot['inventory_fifo'];
        if (bccomp($beforeIssueValue, '0.00', 2) <= 0) {
            throw new RuntimeException('Maintenance history pre-issue cutoff must include the genuine dated purchase receipt.');
        }
        Carbon::setTestNow(CarbonImmutable::parse($maintenanceIssueDate, 'Africa/Cairo')->startOfDay()->addHours(12));
    }
    $issueKey = $uuid();
    $issue = $parts->issue((int) $tech->id, $work, (int) $reserve['id'], '2.00000000', $issueKey);
    $replay = $parts->issue((int) $tech->id, $work, (int) $reserve['id'], '2.00000000', $issueKey);
    $pass($issue === $replay && DB::table('inventory_movements')->where('id', $issue['movement_id'])->where('operation', 'maintenance_issue')->exists(), 'maintenance issue posts one FIFO movement and same-key replay returns identical evidence');
    if ($gate1MaintenanceHistory) {
        $maintenanceIssueSnapshot = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $maintenanceIssueDate);
        $expectedAfterIssue = bcsub($maintenanceBeforeIssueSnapshot['inventory_fifo'], (string) $issue['amount'], 2);
        if ($maintenanceIssueSnapshot['inventory_fifo'] !== $expectedAfterIssue) {
            throw new RuntimeException('Maintenance issue cutoff does not recognize the exact FIFO issue amount on its posting date.');
        }
    }
    $issueCount = DB::table('maintenance_part_issues')->count();
    $movementCount = DB::table('inventory_movements')->where('operation', 'maintenance_issue')->count();
    try {
        $parts->issue((int) $tech->id, $work, (int) $reserve['id'], '3.00000000', $uuid());
        throw new RuntimeException('Over-reservation issue unexpectedly posted.');
    } catch (ValidationException) {
        $pass(true, 'over-reservation issue is rejected inside the owner transaction');
    }
    $pass(DB::table('maintenance_part_issues')->count() === $issueCount && DB::table('inventory_movements')->where('operation', 'maintenance_issue')->count() === $movementCount, 'failed issue rolls back the maintenance issue row and all inventory effects');
    $movement = DB::table('inventory_movements')->find($issue['movement_id']);
    $pass($movement->source_module === 'FieldMaintenance' && $movement->source_id === (string) $work && $movement->source_line === (string) $req && (int) $movement->warehouse_id === (int) $warehouse['id'], 'FIFO issue attributes actual maintenance work and requirement source');
    $immutable = false;
    try {
        DB::transaction(fn () => DB::table('maintenance_part_issues')->where('issue_key', $issueKey)->update(['quantity' => '3.00000000']));
    } catch (QueryException) {
        $immutable = true;
    }
    $pass($immutable, 'migration 186 prevents edits to posted maintenance issue evidence');
    $identityLocked = false;
    try {
        DB::transaction(fn () => DB::table('maintenance_work_orders')->where('id', $work)->update(['project_id' => null]));
    } catch (QueryException) {
        $identityLocked = true;
    }
    $pass($identityLocked, 'migration 186/187 prevents reassignment of work-order project source identity');
    $pass(DB::table('accounting_project_dimensions')->where('journal_id', $issue['journal_id'])->where('project_id', $project->id)->exists(), 'maintenance FIFO issue journal carries actual linked M07 project dimension');
    $reject(fn () => $parts->reserve((int) $maker->id, $work, $otherReq, '1.00000000', $uuid()), 'warehouse scope prevents reserving from an unavailable warehouse', 403);
    $returnKey = $uuid();
    $issueRow = (int) DB::table('maintenance_part_issues')->where('issue_key', $issueKey)->value('id');
    if ($gate1MaintenanceHistory) {
        Carbon::setTestNow(CarbonImmutable::parse($maintenanceReturnDate, 'Africa/Cairo')->startOfDay()->addHours(12));
    }
    $returned = $parts->returnUnused((int) $maker->id, $work, $issueRow, '1.00000000', $returnKey, 'Unused sealed replacement part');
    $parts->returnUnused((int) $maker->id, $work, $issueRow, '1.00000000', $returnKey, 'Unused sealed replacement part');
    $pass(DB::table('inventory_movements')->where('id', $returned['movement_id'])->where('operation', 'maintenance_return')->where('reversal_id', $issue['movement_id'])->exists(), 'unused maintenance part return posts exact-source FIFO reversal');
    $pass(DB::table('accounting_project_dimensions')->where('journal_id', $returned['journal_id'])->where('project_id', $project->id)->exists(), 'maintenance return journal preserves the original source project dimension through AccountingApi');
    $pass(DB::table('maintenance_part_returns')->where('return_key', $returnKey)->count() === 1, 'same-key return replay does not duplicate return evidence');
    if ($gate1MaintenanceHistory) {
        $maintenanceReturnSnapshot = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $maintenanceReturnDate);
        $returnMovement = DB::table('inventory_movements')->find($returned['movement_id']);
        $issueAllocation = DB::table('inventory_fifo_allocations')->where('movement_id', $issue['movement_id'])->orderBy('id')->first();
        $sourceLayer = $issueAllocation ? DB::table('inventory_fifo_layers')->find($issueAllocation->layer_id) : null;
        $returnLayer = DB::table('inventory_fifo_layers')->where('movement_id', $returned['movement_id'])->first();
        $expectedAfterReturn = bcadd($maintenanceIssueSnapshot['inventory_fifo'], (string) $returned['amount'], 2);
        if ($maintenanceReturnSnapshot['inventory_fifo'] !== $expectedAfterReturn
            || ! $returnMovement || CarbonImmutable::parse($returnMovement->posted_at)->setTimezone('Africa/Cairo')->toDateString() !== $maintenanceReturnDate
            || ! $sourceLayer || ! $returnLayer || $returnLayer->received_on !== $sourceLayer->received_on
            || $maintenanceBeforeIssueSnapshot['inventory_fifo'] === $maintenanceIssueSnapshot['inventory_fifo']) {
            throw new RuntimeException('Unused maintenance return must preserve original FIFO age while recognizing its reversal only on the next business date.');
        }
        Gate1FinancialHistory::sameCutoff($maintenanceBeforeIssueSnapshot,
            Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date), 'Maintenance issue/return after receipt cutoff');
        Gate1FinancialHistory::sameCutoff($maintenanceIssueSnapshot,
            Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $maintenanceIssueDate), 'Next-day unused maintenance return after issue cutoff');
        $maintenanceReturnHistoryEvidence = [
            'status' => 'PASS', 'before_issue_cutoff' => $date, 'issue_cutoff' => $maintenanceIssueDate,
            'return_posted_on' => $maintenanceReturnDate, 'return_cutoff' => $maintenanceReturnDate,
            'fifo_before_issue' => $maintenanceBeforeIssueSnapshot['inventory_fifo'],
            'issue_amount' => (string) $issue['amount'], 'fifo_after_issue' => $maintenanceIssueSnapshot['inventory_fifo'],
            'return_amount' => (string) $returned['amount'], 'fifo_after_return' => $maintenanceReturnSnapshot['inventory_fifo'],
            'original_fifo_received_on' => $sourceLayer->received_on, 'return_layer_received_on' => $returnLayer->received_on,
            'issue_cutoff_stable_after_next_day_return' => true, 'reconciliations_match_at_each_cutoff' => true,
        ];
        $pass(true, 'as-of history recognizes unused maintenance return next day, retains original FIFO age, and preserves prior cutoffs with GL/source reconciliation');
    }
    // Separate serialized M08 work keeps this acceptance isolated from the existing bulk-cost/project regression.
    Carbon::setTestNow(CarbonImmutable::parse($maintenanceIssueDate, 'Africa/Cairo')->startOfDay()->addHours(12));
    $serialWork = $actions->createWorkOrder((int) $maker->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id,
        'responsible_id' => $tech->id, 'description' => 'Serialized field maintenance part issue, use and unused return', 'priority' => 'normal',
        'product_id' => null, 'project_id' => null, 'billable' => false]);
    $serialWorkRow = DB::table('maintenance_work_orders')->find($serialWork);
    $serialAssignment = $execution->assign((int) $maker->id, $serialWork, (int) $serialWorkRow->version,
        ['technician_id' => $tech->id, 'starts_at' => now('UTC')->subHours(2)->toIso8601String(), 'ends_at' => now('UTC')->addHours(2)->toIso8601String()]);
    $serialWorkRow = DB::table('maintenance_work_orders')->find($serialWork);
    $serialVisit = $execution->schedule((int) $maker->id, $serialWork, (int) $serialWorkRow->version, $uuid(),
        ['assignment_id' => $serialAssignment, 'scheduled_at' => now('UTC')->subHour()->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $actions->state((int) $tech->id, $serialWork, (int) DB::table('maintenance_work_orders')->find($serialWork)->version,
        'start', 'Begin serialized maintenance parts acceptance work.');
    $serialRequirement = $parts->requirement((int) $maker->id, $serialWork,
        (int) DB::table('maintenance_work_orders')->find($serialWork)->version,
        ['product_id' => (int) $serialStock->id, 'warehouse_id' => (int) $warehouse['id'], 'quantity' => '2.00000000']);
    $serialReservation = $parts->reserve((int) $maker->id, $serialWork, $serialRequirement, '2.00000000', $uuid());
    $serialIssueKey = $uuid();
    $serialIssue = $parts->issue((int) $tech->id, $serialWork, (int) $serialReservation['id'], '2.00000000', $serialIssueKey);
    $issuedSerials = DB::table('inventory_serial_events as e')->join('inventory_serials as s', 's.id', '=', 'e.serial_id')
        ->where('e.movement_id', $serialIssue['movement_id'])->orderBy('s.normalized')->pluck('s.normalized')->all();
    $serialIssueMappings = DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
        ->where('a.movement_id', $serialIssue['movement_id'])->orderBy('s.normalized')->get(['s.normalized', 'a.amount']);
    $pass($issuedSerials === $serials && $serialIssueMappings->count() === 2
        && DB::table('inventory_movements')->where('id', $serialIssue['movement_id'])->where('operation', 'maintenance_issue')
            ->where('source_module', 'FieldMaintenance')->where('source_id', (string) $serialWork)->where('source_line', (string) $serialRequirement)->exists(),
        'M08 issues the exact two serials from a real valued receipt through its own reservation and source line.');
    $serialArrival = CarbonImmutable::now('UTC')->subMinutes(30)->startOfMinute();
    $serialDeparture = $serialArrival->addMinutes(15);
    $serialVisitRow = DB::table('maintenance_visits')->find($serialVisit);
    $execution->recordVisit((int) $tech->id, $serialWork, $serialVisit, (int) $serialVisitRow->version,
        ['arrival_at' => $serialArrival->toIso8601String(), 'departure_at' => $serialDeparture->toIso8601String(),
            'work_performed' => 'Recorded serialized replacement work.', 'diagnosis' => 'Serial source test diagnosis.',
            'observations' => 'Recorded part-use acceptance.', 'acknowledgment' => 'Synthetic customer acknowledgment.', 'outcome' => 'completed'],
        ['safe' => ['result' => 'complete']]);
    $usedSerial = $issuedSerials[0];
    $returnedSerial = $issuedSerials[1];
    $parts->usage((int) $tech->id, $serialWork, $serialVisit, $serialRequirement, '1.00000000', [$usedSerial]);
    $usageRow = DB::table('maintenance_part_usage')->where('visit_id', $serialVisit)->where('requirement_id', $serialRequirement)->firstOrFail();
    $sourceSerialCost = (string) DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
        ->where('a.movement_id', $serialIssue['movement_id'])->where('s.normalized', $returnedSerial)->value('a.amount');
    $denialSnapshot = [DB::table('inventory_movements')->count(), DB::table('inventory_serial_events')->count(),
        DB::table('inventory_corrections')->count(), DB::table('maintenance_part_returns')->count(),
        DB::table('maintenance_part_serial_usage')->count(), DB::table('accounting_journals')->count()];
    $reject(fn () => $parts->usage((int) $tech->id, $serialWork, $serialVisit, $serialRequirement, '1.00000000', [$usedSerial]),
        'M08 prevents reusing an already consumed serial for a second part usage', 422);
    $reject(fn () => $parts->returnUnused((int) $maker->id, $serialWork, (int) DB::table('maintenance_part_issues')->where('issue_key', $serialIssueKey)->value('id'),
        '1.00000000', $uuid(), 'Reject returning a serial already consumed by the technician.', [$usedSerial]),
        'M08 cannot return a serial already linked to recorded part usage', 422);
    $reject(fn () => app(\App\Modules\Inventory\Application\Contracts\InventoryApi::class)->returnMaintenanceIssue((int) $maker->id,
        (int) $serialIssue['movement_id'], '1.00000000', $uuid(), 'Reject direct M06 return without unused owner proof.', [$usedSerial]),
        'Direct M06 serialized return still requires an M08 unused-owner proof', 422);
    $pass($denialSnapshot === [DB::table('inventory_movements')->count(), DB::table('inventory_serial_events')->count(),
        DB::table('inventory_corrections')->count(), DB::table('maintenance_part_returns')->count(),
        DB::table('maintenance_part_serial_usage')->count(), DB::table('accounting_journals')->count()],
        'Duplicate-use, used-serial return, and direct M06 bypass denials leave stock, owner, FIFO, and GL rows unchanged.');
    Carbon::setTestNow(CarbonImmutable::parse($maintenanceReturnDate, 'Africa/Cairo')->startOfDay()->addHours(12));
    $serialIssueRow = (int) DB::table('maintenance_part_issues')->where('issue_key', $serialIssueKey)->value('id');
    $serialReturnKey = $uuid();
    $serialReturned = $parts->returnUnused((int) $maker->id, $serialWork, $serialIssueRow, '1.00000000', $serialReturnKey,
        'Return the distinct unused serialized unit to its original FIFO source.', [$returnedSerial]);
    $serialReturnRow = DB::table('maintenance_part_returns')->where('return_key', $serialReturnKey)->firstOrFail();
    $serialUsageSerials = json_decode($usageRow->serials, true, 512, JSON_THROW_ON_ERROR);
    $serialReturnNames = json_decode($serialReturnRow->serials, true, 512, JSON_THROW_ON_ERROR);
    $serialReceiptCost = (string) DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
        ->where('a.movement_id', $serialReceiptMovement->id)->where('s.normalized', $returnedSerial)->value('a.amount');
    $returnSerialCost = (string) DB::table('inventory_fifo_serial_allocations as a')->join('inventory_serials as s', 's.id', '=', 'a.serial_id')
        ->where('a.movement_id', $serialReturned['movement_id'])->where('s.normalized', $returnedSerial)->value('a.amount');
    $returnJournalSides = DB::table('accounting_journal_lines')->where('journal_id', $serialReturned['journal_id'])
        ->selectRaw('account_id,sum(debit) debit,sum(credit) credit')->groupBy('account_id')->get()->keyBy('account_id');
    $serialIssueAmount = (string) DB::table('inventory_fifo_serial_allocations')->where('movement_id', $serialIssue['movement_id'])->sum('amount');
    $pass($serialUsageSerials === [$usedSerial] && $serialReturnNames === [$returnedSerial]
        && DB::table('maintenance_part_serial_usage')->where('usage_id', $usageRow->id)->where('serial_id', DB::table('inventory_serials')->where('normalized', $usedSerial)->value('id'))
            ->where('movement_id', $serialIssue['movement_id'])->exists()
        && DB::table('inventory_serials')->where('normalized', $usedSerial)->where('state', 'issued')->exists()
        && DB::table('inventory_serials')->where('normalized', $returnedSerial)->where('state', 'available')->where('warehouse_id', $warehouse['id'])->exists()
        && bccomp($serialIssueAmount, (string) $serialIssue['amount'], 2) === 0
        && bccomp($serialReceiptCost, $sourceSerialCost, 2) === 0 && bccomp($returnSerialCost, $sourceSerialCost, 2) === 0
        && bccomp((string) $serialReturned['amount'], $sourceSerialCost, 2) === 0
        && isset($returnJournalSides[(int) $maps['inventory']]) && isset($returnJournalSides[(int) $maps['inventory_issue']])
        && bccomp((string) $returnJournalSides[(int) $maps['inventory']]['debit'], $sourceSerialCost, 2) === 0
        && bccomp((string) $returnJournalSides[(int) $maps['inventory_issue']]['credit'], $sourceSerialCost, 2) === 0,
        'M08 usage consumes one actual serial while the distinct unused serial returns at its original FIFO cents and GL controls.');
    $parts->reconciled((int) $maker->id, $serialWork);
    $pass(DB::table('maintenance_part_issues')->where('id', $serialIssueRow)->where('serials', json_encode($issuedSerials, JSON_THROW_ON_ERROR))->exists()
        && DB::table('maintenance_part_usage')->where('id', $usageRow->id)->where('serials', json_encode([$usedSerial], JSON_THROW_ON_ERROR))->exists()
        && DB::table('maintenance_part_returns')->where('id', $serialReturnRow->id)->where('serials', json_encode([$returnedSerial], JSON_THROW_ON_ERROR))->exists(),
        'M08 immutable issue, use, and unused-return rows retain their canonical serial evidence.');
    Carbon::setTestNow(CarbonImmutable::parse($gate1MaintenanceHistory ? $maintenanceReturnDate : $date, 'Africa/Cairo')->startOfDay()->addHours(12));

    $costs = app(MaintenanceBilling::class)->costs((int) $maker->id, $work);
    $pass(bccomp($costs['parts'], $issue['amount'], 2) < 0, 'maintenance cost removes returned exact-source value');
    $proposalInput = ['branch_id' => $branch->id, 'technician_id' => $tech->id, 'hourly_cost' => '45.00', 'effective_from' => $laborDate, 'effective_to' => $laborDate, 'reference' => 'Synthetic approved internal labor rate'];
    $proposals = app(MaintenanceCostProposals::class);
    $proposals->propose((int) $tech->id, 'labor_rate', null, $proposalInput);
    $rate = app(MaintenanceExecution::class)->approveRate((int) $maker->id, (int) $tech->id, $proposalInput);
    $pass(DB::table('maintenance_labor_rates')->where('id', $rate)->where('proposal_id', '>', 0)->exists(), 'labor rate approval consumes a separate actor submitted matching proposal');
    $from = now('UTC')->startOfMinute()->subMinutes(60);
    $to = $from->copy()->addMinutes(30);
    app(MaintenanceExecution::class)->recordVisit((int) $tech->id, $work, $visit, 1, ['arrival_at' => $from->toIso8601String(), 'departure_at' => $to->toIso8601String(), 'work_performed' => 'Replaced damaged terminal', 'diagnosis' => 'Loose terminal', 'acknowledgment' => 'Customer confirms repair', 'outcome' => 'completed'], ['safe' => ['result' => 'complete']]);
    $parts->usage((int) $tech->id, $work, $visit, $req, '1.00000000');
    $labor = app(MaintenanceExecution::class)->labor((int) $tech->id, $work, $uuid(), ['visit_id' => $visit, 'starts_at' => $from->toIso8601String(), 'ends_at' => $to->toIso8601String(), 'work_type' => 'Electrical repair']);
    $pass(DB::table('maintenance_labor_entries')->where('id', $labor)->where('rate_id', $rate)->where('cost', '22.50')->exists(), 'approved hourly rate calculates exact half-hour labor cost');
    $billing = app(MaintenanceBilling::class);
    $laborJournal = $billing->recognizeLabor((int) $maker->id, $work, $labor);
    $laborReplay = $billing->recognizeLabor((int) $maker->id, $work, $labor);
    $pass($laborJournal === $laborReplay && DB::table('accounting_project_dimensions')->where('journal_id', $laborJournal)->where('project_id', $project->id)->exists(), 'recognized maintenance labor posts once to its project dimension');
    $actions->state((int) $tech->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, 'complete', 'Synthetic repair complete with actual labor and parts reconciliation.');
    $actions->state((int) $maker->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, 'verify', 'Independent verification of the repair evidence.');
    $proposal = ['product_id' => $service->id, 'amount' => '100.00', 'date' => $billingDate, 'price_reference' => 'Synthetic signed service price approval'];
    app(MaintenanceCostProposals::class)->propose((int) $tech->id, 'service', $work, $proposal);
    $approval = app(MaintenanceBilling::class)->approve((int) $maker->id, (int) $tech->id, $work, (int) DB::table('maintenance_work_orders')->find($work)->version, $proposal);
    $commercial = DB::table('maintenance_commercial_approvals')->find($approval);
    $pass($commercial && bccomp($commercial->net_amount, '100.00', 2) === 0 && bccomp($commercial->tax_amount, '14.00', 2) === 0, 'billable service approval snapshots authorized amount and applicable 14% tax');
    $api = app(ReceivablesPayablesApi::class);
    $serviceInvoiceKey = $uuid();
    $invoice = app(MaintenanceBilling::class)->request((int) $maker->id, $work, $serviceInvoiceKey, $billingDate, date('Y-m-d', strtotime($billingDate.' +30 days')));
    $api->validateCustomerInvoice((int) $maker->id, $invoice, 1);
    $posted = $api->postCustomerInvoice((int) $approver->id, $invoice, 2);
    $api->postCustomerInvoice((int) $approver->id, $invoice, 2);
    $inv = DB::table('rp_customer_invoices')->find($invoice);
    $journal = DB::table('accounting_journals')->find($posted['journal_id']);
    $pass($inv->status === 'posted' && bccomp($inv->total_amount, '114.00', 2) === 0 && $journal && $journal->status === 'posted', 'M12 service invoice validates/posts real AR and tax journal with stable replay');
    $lines = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->selectRaw('sum(debit) d,sum(credit) c')->first();
    $pass(bccomp($lines->d, $lines->c, 2) === 0 && bccomp($lines->d, '114.00', 2) === 0, 'service invoice GL is balanced at gross invoice amount');
    $summary = $billing->costs((int) $maker->id, $work);
    $pass($summary['complete'] && $summary['labor'] === '22.50', 'maintenance job cost includes exact approved labor and complete status');
    $projectFinancials = app(ProjectFinancials::class);
    $reject(fn () => $projectFinancials->summary((int) $maker->id, $project->id), 'M07 financial projection denies missing supplier-subledger read scope', 403);
    $grant($maker, ['M12.ap_view'], (int) $branch->id);
    $projectSummary = $projectFinancials->summary((int) $maker->id, $project->id);
    $pass($projectSummary['inventory']['fifo_cost'] === $summary['parts'] && $projectSummary['accounting']['recognized_cost'] === '32.50', 'M07 reads net maintenance FIFO and labor GL once each, without double counting source inventory cost');
    $expenseAccount = $accounts->account((int) $maker->id, null, 0, ['code' => 'M08F-OTHER-'.strtoupper(Str::random(5)), 'name' => 'Synthetic approved maintenance other expense', 'kind' => 'expense', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $offsetAccount = $accounts->account((int) $maker->id, null, 0, ['code' => 'M08F-OFFSET-'.strtoupper(Str::random(5)), 'name' => 'Synthetic maintenance expense offset', 'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $expenseJournal = $accounts->journal((int) $maker->id, null, 0, $uuid(), ['project_id' => $project->id, 'branch_id' => $branch->id, 'journal_date' => $billingDate, 'description' => 'Synthetic approved maintenance expense source', 'lines' => [['account_id' => $expenseAccount, 'debit' => '10.00', 'credit' => '0.00'], ['account_id' => $offsetAccount, 'debit' => '0.00', 'credit' => '10.00']]]);
    $accounts->advance((int) $maker->id, $expenseJournal, 1, 'submit');
    $accounts->advance((int) $approver->id, $expenseJournal, 2, 'approve');
    $accounts->advance((int) $approver->id, $expenseJournal, 3, 'post');
    $billing->linkExpense((int) $maker->id, $work, $expenseJournal, 'Synthetic approved field repair expense');
    $pass(DB::table('maintenance_expense_sources')->where('work_order_id', $work)->where('journal_id', $expenseJournal)->where('amount', '10.00')->count() === 1 && DB::table('accounting_journals')->where('id', $expenseJournal)->where('status', 'posted')->count() === 1, 'approved project-dimensioned manual expense is linked without creating another journal');
    $linkedCosts = $billing->costs((int) $maker->id, $work);
    $afterExpenseSummary = $projectFinancials->summary((int) $maker->id, $project->id);
    $pass($linkedCosts['other_expenses'] === '10.00' && $afterExpenseSummary['accounting']['recognized_cost'] === '42.50' && DB::table('maintenance_expense_sources')->where('work_order_id', $work)->count() === 1, 'other expense is recognized once in maintenance and M07 project costs');
    $reject(fn () => $billing->linkExpense((int) $maker->id, $work, $laborJournal, 'Protected labor control source denied'), 'maintenance expense linker rejects protected labor/control journal', 422);
    $wrongProjectJournal = $accounts->journal((int) $maker->id, null, 0, $uuid(), ['project_id' => null, 'branch_id' => $branch->id, 'journal_date' => $billingDate, 'description' => 'Synthetic unallocated expense source', 'lines' => [['account_id' => $expenseAccount, 'debit' => '3.00', 'credit' => '0.00'], ['account_id' => $offsetAccount, 'debit' => '0.00', 'credit' => '3.00']]]);
    $accounts->advance((int) $maker->id, $wrongProjectJournal, 1, 'submit');
    $accounts->advance((int) $approver->id, $wrongProjectJournal, 2, 'approve');
    $accounts->advance((int) $approver->id, $wrongProjectJournal, 3, 'post');
    $reject(fn () => $billing->linkExpense((int) $maker->id, $work, $wrongProjectJournal, 'Wrong project expense source denied'), 'maintenance expense linker rejects wrong project attribution', 422);
    $pass(DB::table('maintenance_expense_sources')->where('work_order_id', $work)->count() === 1, 'rejected expense sources leave no maintenance linkage rows');
    $closedProject = app(ProjectActions::class)->save((int) $maker->id, null, 0, $uuid(), ['code' => 'M08F-CLOSED-'.strtoupper(Str::random(5)), 'name' => 'Synthetic closed project source guard', 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'manager_id' => $maker->id, 'members' => [], 'start_date' => $date, 'end_date' => $projectEnd, 'description' => 'Real lifecycle closed project for M08 source guard', 'notes' => '']);
    app(ProjectActions::class)->phase((int) $maker->id, $closedProject->id, $closedProject->version, ['title' => 'Completed closeout phase', 'start_date' => $date, 'end_date' => $projectEnd]);
    $closedProject->refresh();
    $closePhase = (int) DB::table('project_phases')->where('project_id', $closedProject->id)->value('id');
    app(ProjectActions::class)->task((int) $maker->id, $closedProject->id, $closedProject->version, ['phase_id' => $closePhase, 'title' => 'Complete closeout evidence', 'assigned_to' => $maker->id, 'start_date' => $date, 'end_date' => $projectEnd, 'notes' => 'Required lifecycle fixture task.', 'priority' => 'normal', 'required' => true]);
    $closedProject->refresh();
    $closeTask = (int) DB::table('project_tasks')->where('project_id', $closedProject->id)->value('id');
    $closeMilestone = app(ProjectExecution::class)->milestone((int) $maker->id, $closedProject->id, $closedProject->version, ['title' => 'Closeout milestone', 'target_date' => $projectEnd, 'responsible_id' => $maker->id, 'required' => true]);
    $closedProject->refresh();
    $planning->propose((int) $maker->id, $closedProject->id, $closedProject->version, $uuid(), 'Synthetic baseline for closeout project', ['scope' => 'Closeout lifecycle', 'currency' => 'EGP', 'total' => '100.00', 'categories' => [['name' => 'Closeout', 'amount' => '100.00']], 'phases' => [],
        'structure' => [['id' => $closePhase, 'title' => 'Completed closeout phase', 'start_date' => $date, 'end_date' => $projectEnd, 'cancelled' => false]]]);
    $closedProject->refresh();
    $closeChange = (int) DB::table('project_changes')->where('project_id', $closedProject->id)->value('id');
    $planning->decide((int) $approver->id, $closedProject->id, $closedProject->version, $closeChange, true, 'Independent closeout baseline approval.');
    $closedProject->refresh();
    $planning->transition((int) $maker->id, $closedProject->id, $closedProject->version, 'planned', 'Approved lifecycle baseline.');
    $closedProject->refresh();
    $planning->transition((int) $maker->id, $closedProject->id, $closedProject->version, 'active', 'Closeout project begins.');
    $closedProject->refresh();
    app(ProjectActions::class)->taskStatus((int) $maker->id, $closedProject->id, $closeTask, 1, 'completed', 'Synthetic closeout evidence completed.');
    $closedProject->refresh();
    app(ProjectExecution::class)->milestoneStatus((int) $maker->id, $closedProject->id, $closeMilestone, 1, 'completed', 'Synthetic owner evidence complete.');
    $closedProject->refresh();
    app(ProjectExecution::class)->handover((int) $maker->id, $closedProject->id, $closedProject->version, 'Synthetic signed handover for source guard project.');
    $closedProject->refresh();
    $planning->transition((int) $maker->id, $closedProject->id, $closedProject->version, 'completed', 'All required closeout evidence is complete.');
    $closedProject->refresh();
    $planning->transition((int) $maker->id, $closedProject->id, $closedProject->version, 'closed', 'Financial and execution obligations reconciled.');
    $closedBefore = DB::table('maintenance_work_orders')->count();
    $reject(fn () => $actions->createWorkOrder((int) $maker->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $tech->id, 'description' => 'Must not link to a closed project', 'priority' => 'normal', 'product_id' => null, 'project_id' => $closedProject->id, 'billable' => false]), 'closed M07 project rejects new M08 linkage', 409);
    $pass(DB::table('projects')->where('id', $closedProject->id)->where('status', 'closed')->exists() && DB::table('maintenance_work_orders')->count() === $closedBefore, 'closed-project linkage denial preserves lifecycle state and creates no M08 work order');
    $unratedWork = $actions->createWorkOrder((int) $maker->id, null, 0, $uuid(), ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $unratedTech->id, 'description' => 'Synthetic service labor without an approved internal rate', 'priority' => 'normal', 'billable' => false]);
    $oldStart = now('UTC')->subHours(5);
    $oldEnd = now('UTC')->subHours(3);
    $unratedAssignment = $execution->assign((int) $maker->id, $unratedWork, 1, ['technician_id' => $unratedTech->id, 'starts_at' => $oldStart->toIso8601String(), 'ends_at' => $oldEnd->toIso8601String()]);
    $unratedRow = DB::table('maintenance_work_orders')->find($unratedWork);
    $unratedVisit = $execution->schedule((int) $maker->id, $unratedWork, (int) $unratedRow->version, $uuid(), ['assignment_id' => $unratedAssignment, 'scheduled_at' => $oldStart->addMinutes(5)->toIso8601String(), 'template_id' => $template, 'mandatory' => true]);
    $actions->state((int) $unratedTech->id, $unratedWork, (int) DB::table('maintenance_work_orders')->find($unratedWork)->version, 'start', 'Synthetic technician begins labor without an approved rate.');
    $raceRequirement = $parts->requirement((int) $maker->id, $unratedWork, (int) DB::table('maintenance_work_orders')->find($unratedWork)->version, ['product_id' => $stock->id, 'warehouse_id' => $warehouse['id'], 'quantity' => '8.00000000']);
    $raceReservation = $parts->reserve((int) $maker->id, $unratedWork, $raceRequirement, '1.00000000', $uuid());
    $raceReservation2 = $parts->reserve((int) $maker->id, $unratedWork, $raceRequirement, '1.00000000', $uuid());
    $raceIssueKey = $uuid();
    $rollbackKey = $uuid();
    $beforeIssueRows = DB::table('maintenance_part_issues')->count();
    $beforeIssueMovements = DB::table('inventory_movements')->where('operation', 'maintenance_issue')->count();
    $injected = false;
    try {
        DB::transaction(function () use ($parts, $unratedTech, $unratedWork, $raceReservation, $rollbackKey) {
            $parts->issue((int) $unratedTech->id, $unratedWork, (int) $raceReservation['id'], '1.00000000', $rollbackKey);
            throw new RuntimeException('Injected failure after FIFO and GL posting.');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Injected failure after FIFO and GL posting.') {
            throw $e;
        } $injected = true;
    }
    $pass($injected && DB::table('maintenance_part_issues')->count() === $beforeIssueRows && DB::table('inventory_movements')->where('operation', 'maintenance_issue')->count() === $beforeIssueMovements && ! DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$rollbackKey)->exists(), 'injected post-FIFO failure rolls back M08 issue, inventory movement, FIFO allocation and GL source');
    $unratedArrival = $oldStart->addMinutes(10);
    $unratedDeparture = $unratedArrival->addMinutes(30);
    $execution->recordVisit((int) $unratedTech->id, $unratedWork, $unratedVisit, 1, ['arrival_at' => $unratedArrival->toIso8601String(), 'departure_at' => $unratedDeparture->toIso8601String(), 'work_performed' => 'Inspected and tightened a panel terminal', 'diagnosis' => 'Loose terminal found', 'outcome' => 'completed'], ['safe' => ['result' => 'complete']]);
    $unratedLabor = $execution->labor((int) $unratedTech->id, $unratedWork, $uuid(), ['visit_id' => $unratedVisit, 'starts_at' => $unratedArrival->toIso8601String(), 'ends_at' => $unratedDeparture->toIso8601String(), 'work_type' => 'Inspection']);
    $unratedCost = app(MaintenanceBilling::class)->costs((int) $maker->id, $unratedWork);
    $pass(DB::table('maintenance_labor_entries')->where('id', $unratedLabor)->whereNull('rate_id')->whereNull('cost')->exists() && ! $unratedCost['complete'] && $unratedCost['labor'] === null, 'unrated technician time remains an honest unavailable cost rather than a fabricated zero');
    $evidence = ['all_pass' => true, 'database' => $conn->db, 'postgres_port' => (int) $conn->port, 'synthetic' => true, 'assertions' => $passed,
        'gate1_database_identity' => $gate1MaintenanceIdentity,
        'maintenance_unused_return_history' => $maintenanceReturnHistoryEvidence,
        'stock' => ['product_id' => (int) $stock->id, 'warehouse_id' => (int) $warehouse['id'], 'on_hand_before_issue' => '5.00000000', 'requirement_id' => $req, 'reservation_id' => (int) $reserve['id'],
            'issue_movement_id' => (int) $issue['movement_id'], 'issue_journal_id' => (int) $issue['journal_id'], 'issue_key' => $issueKey, 'issue_amount' => $issue['amount'],
            'return_movement_id' => (int) $returned['movement_id'], 'return_key' => $returnKey, 'returned_amount' => $returned['amount'], 'wrong_warehouse' => '403'],
        'project' => ['project_id' => (int) $project->id, 'work_order_id' => (int) $work, 'fifo_cost' => $projectSummary['inventory']['fifo_cost'], 'recognized_cost_before_other_expense' => $projectSummary['accounting']['recognized_cost'], 'recognized_cost_after_other_expense' => $afterExpenseSummary['accounting']['recognized_cost'], 'other_expense' => '10.00', 'expense_journal_id' => (int) $expenseJournal, 'fifo_gl_difference' => $projectSummary['fifo_gl_difference'], 'closed_project_id' => (int) $closedProject->id, 'closed_link_rejected' => true],
        'labor' => ['rate_id' => $rate, 'entry_id' => $labor, 'journal_id' => $laborJournal, 'cost' => '22.50', 'unrated_entry_id' => $unratedLabor, 'unrated_cost' => null],
        'billing' => ['approval_id' => $approval, 'invoice_id' => $invoice, 'invoice_key' => $serviceInvoiceKey, 'invoice_total' => $inv->total_amount, 'invoice_journal_id' => (int) $journal->id, 'ar_and_tax_balanced' => true],
        'financial_date' => $billingDate, 'concurrency_fixture' => ['actor_id' => (int) $unratedTech->id, 'maker_id' => (int) $maker->id, 'tech_id' => (int) $tech->id, 'work_order_id' => (int) $unratedWork, 'requirement_id' => $raceRequirement,
            'reservation_id' => (int) $raceReservation['id'], 'reservation2_id' => (int) $raceReservation2['id'], 'issue_key' => $raceIssueKey, 'quantity' => '1.00000000', 'posting_date' => $billingDate, 'assignment_id' => (int) $unratedAssignment,
            'work_version' => (int) DB::table('maintenance_work_orders')->find($unratedWork)->version, 'main_work_order_id' => (int) $work, 'main_work_version' => (int) DB::table('maintenance_work_orders')->find($work)->version,
            'invoice_id' => (int) $invoice, 'invoice_key' => $serviceInvoiceKey, 'invoice_date' => $billingDate, 'invoice_due' => date('Y-m-d', strtotime($billingDate.' +30 days')), 'project_id' => (int) $project->id, 'warehouse_id' => (int) $warehouse['id'], 'product_id' => (int) $stock->id]];
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
} catch (Throwable $e) {
    $failure = $e;
    $evidence = ['all_pass' => false, 'database' => $conn->db, 'postgres_port' => (int) $conn->port, 'error_class' => $e::class, 'error' => $e->getMessage(), 'assertions' => $passed];
} finally {
    $committed = false;
    if (DB::transactionLevel() > 0) {
        if ($commitFixtures && $failure === null) {
            DB::commit();
            $committed = true;
        } else {
            DB::rollBack();
        }
    }
    $evidence['fixture_committed'] = $committed;
    $evidencePath = $gate1MaintenanceHistory
        ? $root.'/docs/release/remediation/evidence/gate1/historical/maintenance-unused-return.json'
        : $root.'/docs/qa/evidence/phase3-maintenance-financial.json';
    if (! is_dir(dirname($evidencePath))) {
        mkdir(dirname($evidencePath), 0770, true);
    }
    file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
}
if ($failure) {
    fwrite(STDERR, 'FAIL '.$failure::class.': '.$failure->getMessage().' at '.$failure->getFile().':'.$failure->getLine()."\n".$failure->getTraceAsString()."\n");
    exit(1);
}
fwrite(STDOUT, 'RESULT all_pass=true assertions='.$assert.' database='.$conn->db.' port='.$conn->port.' fixture_committed='.($committed ? 'true' : 'false')."\n");

<?php

declare(strict_types=1);

// One-run browser prerequisites. This helper is intentionally limited to the
// isolated automation cluster and creates operational evidence only through
// owning module Actions.
use App\Models\User;
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
$port = (int) (getenv('E2E_DB_PORT') ?: 0);
$storage = getenv('E2E_APP_STORAGE') ?: '';
$cluster = $root.'/.tmp/e2e-automation/postgres';
$markerPath = $cluster.'/codex-owned.json';
if ($port !== 55460 || getenv('E2E_DB_DATABASE') !== 'erp_elec_new_test'
    || ! str_contains(str_replace('\\', '/', $storage), '/.tmp/e2e-automation/')) {
    throw new RuntimeException('ABORT: critical fixture only accepts isolated E2E PostgreSQL 55460 and private automation storage.');
}
if (! is_file($markerPath)) throw new RuntimeException('ABORT: E2E PostgreSQL ownership marker is missing.');
$marker = json_decode((string) file_get_contents($markerPath), true, 512, JSON_THROW_ON_ERROR);
$expectedMarker = getenv('E2E_CLUSTER_MARKER') ?: '';
$expectedToken = getenv('E2E_CLUSTER_TOKEN') ?: '';
if (($marker['kind'] ?? null) !== 'codex-e2e-postgres' || ($marker['repo'] ?? null) !== realpath($root)
    || (int) ($marker['port'] ?? 0) !== $port || (int) ($marker['version'] ?? 0) !== 1
    || $expectedMarker === '' || realpath($expectedMarker) !== realpath($markerPath)
    || strlen($expectedToken) < 32 || ! hash_equals((string) ($marker['token'] ?? ''), $expectedToken)) {
    throw new RuntimeException('ABORT: E2E PostgreSQL ownership marker does not match this workspace.');
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
putenv('DB_HOST=127.0.0.1');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
putenv('DB_PORT='.$port);
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = (string) $port;
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$stage = 'database identity';
set_exception_handler(static function (Throwable $error) use (&$stage): never {
    fwrite(STDERR, json_encode(['fixture_error' => $error::class, 'stage' => $stage,
        'message' => mb_substr($error->getMessage(), 0, 600)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
});
$identity = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
$actualDataDirectory = realpath((string) DB::scalar('show data_directory'));
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || $identity->db !== 'erp_elec_new_test' || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $identity->port !== 55460 || $actualDataDirectory !== realpath($cluster)
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
    throw new RuntimeException('ABORT before writes: unexpected E2E PostgreSQL identity.');
}
$mode = getenv('E2E_FIXTURE_MODE') ?: '';
if ($mode === 'readback') {
    $workId = (int) (getenv('E2E_M08_WORK_ORDER_ID') ?: 0);
    $workshopJobId = (int) (getenv('E2E_M09_JOB_ID') ?: 0);
    $work = $workId > 0 ? DB::table('maintenance_work_orders')->find($workId) : null;
    $workshopJob = $workshopJobId > 0 ? DB::table('workshop_jobs')->find($workshopJobId) : null;
    $out = ['database' => $identity->db, 'port' => 55460];
    if ($workId > 0) {
        if (! $work) throw new RuntimeException('M08 readback work order missing.');
        $out['work_order'] = [
        'id' => (int) $work->id, 'number' => $work->number, 'status' => $work->status,
        'version' => (int) $work->version, 'closed_at' => $work->closed_at,
        'completion_count' => DB::table('maintenance_completions')->where('work_order_id', $workId)->count(),
        'visit_count' => DB::table('maintenance_visits')->where('work_order_id', $workId)->where('status', 'recorded')->count(),
        'labor_count' => DB::table('maintenance_labor_entries')->where('work_order_id', $workId)->count(),
        ];
        $completion = DB::table('maintenance_completions')->where('work_order_id', $workId)->orderByDesc('revision')->first();
        if ($completion) {
            $document = DB::table('system_official_documents')->find($completion->official_document_id);
            $out['work_order']['completion_id'] = (int) $completion->id;
            $out['work_order']['official_document_id'] = (string) $completion->official_document_id;
            $out['work_order']['document_sha256'] = $document?->sha256;
        }
    }
    $requestId = (int) (getenv('E2E_M08_REQUEST_ID') ?: 0);
    if ($requestId > 0) {
        $request = DB::table('maintenance_requests')->find($requestId);
        if (! $request) throw new RuntimeException('M08 request draft readback missing.');
        $out['maintenance_request'] = ['id' => (int) $request->id, 'number' => $request->number,
            'status' => $request->status, 'version' => (int) $request->version,
            'branch_id' => (int) $request->branch_id, 'customer_id' => (int) $request->customer_id,
            'responsible_id' => (int) $request->responsible_id, 'description' => $request->description,
            'created_by' => (int) $request->created_by,
            'work_order_count' => DB::table('maintenance_work_orders')->where('request_id', $requestId)->count(),
            'saved_audit_count' => DB::table('maintenance_activities')->where('entity_type', 'maintenance_request')
                ->where('entity_id', $requestId)->where('action', 'request.saved')->count()];
    }
    if ($workshopJobId > 0) {
        if (! $workshopJob) throw new RuntimeException('M09 readback job missing.');
        $out['workshop_job'] = ['id' => (int) $workshopJob->id, 'status' => $workshopJob->status,
            'version' => (int) $workshopJob->version, 'billing_mode' => $workshopJob->billing_mode,
            'estimate_count' => DB::table('workshop_estimates')->where('job_id', $workshopJobId)->count(),
            'pricing_approval_count' => DB::table('workshop_quote_approvals')->whereIn('estimate_id', DB::table('workshop_estimates')->where('job_id', $workshopJobId)->select('id'))->count(),
            'customer_decision_count' => DB::table('workshop_estimate_approvals')->whereIn('estimate_id', DB::table('workshop_estimates')->where('job_id', $workshopJobId)->select('id'))->count(),
            'quote_count' => DB::table('workshop_quotes')->where('job_id', $workshopJobId)->count(),
            'work_record_count' => DB::table('workshop_work_records')->where('job_id', $workshopJobId)->count(),
            'test_count' => DB::table('workshop_tests')->where('job_id', $workshopJobId)->count(),
            'delivery_count' => DB::table('workshop_deliveries')->where('job_id', $workshopJobId)->count(),
            'nonbillable_authorization_count' => DB::table('workshop_nonbillable_authorizations')->where('job_id', $workshopJobId)->count(),
            'intake_status' => DB::table('workshop_intakes')->where('id', $workshopJob->intake_id)->value('status'),
            'custody_event_count' => DB::table('workshop_custody_events')->where('intake_id', $workshopJob->intake_id)->count(),
            'invoice_count' => DB::table('rp_customer_invoices')->where('workshop_job_id', $workshopJobId)->count(),
            'job_inventory_movements' => DB::table('inventory_movements')->where('source_module', 'WorkshopRepairs')->where('source_id', (string) $workshopJobId)->count(),
        ];
    }
    if (getenv('E2E_FINANCIAL_READBACK') === '1') {
        $orderId = (int) (getenv('E2E_PURCHASE_ORDER_ID') ?: 0);
        $receiptId = (int) (getenv('E2E_RECEIPT_ID') ?: 0);
        $supplierInvoiceId = (int) (getenv('E2E_SUPPLIER_INVOICE_ID') ?: 0);
        $saleOrderId = (int) (getenv('E2E_SALES_ORDER_ID') ?: 0);
        $saleLineId = (int) (getenv('E2E_SALES_LINE_ID') ?: 0);
        $customerInvoiceId = (int) (getenv('E2E_CUSTOMER_INVOICE_ID') ?: 0);
        $warehouseId = (int) (getenv('E2E_WAREHOUSE_ID') ?: 0);
        $productId = (int) (getenv('E2E_STOCK_PRODUCT_ID') ?: 0);
        $order = DB::table('purchasing_orders')->find($orderId);
        $receipt = DB::table('purchasing_receipts')->find($receiptId);
        $supplierInvoice = DB::table('rp_supplier_invoices')->find($supplierInvoiceId);
        $customerInvoice = DB::table('rp_customer_invoices')->find($customerInvoiceId);
        $receiptMovementId = (int) DB::table('purchasing_receipt_postings')->where('receipt_id', $receiptId)->value('movement_id');
        $saleMovement = DB::table('inventory_movements')->where('operation', 'reservation_issue')->where('source_module', 'Sales')
            ->where('source_id', (string) $saleOrderId)->where('source_line', (string) $saleLineId)->first();
        if (! $order || ! $receipt || ! $supplierInvoice || ! $customerInvoice || ! $receiptMovementId || ! $saleMovement) {
            throw new RuntimeException('Financial E2E readback could not find the posted purchasing, FIFO, sales, or M12 source.');
        }
        $ap = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $supplierInvoiceId)->first();
        $ar = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $customerInvoiceId)->first();
        $purchaseJournal = DB::table('accounting_journals')->where('source_key', 'purchase-receipt:'.$receiptId)->first();
        $supplierInvoiceJournal = $supplierInvoice->journal_id ? DB::table('accounting_journals')->find($supplierInvoice->journal_id) : null;
        $saleJournalId = (int) DB::table('inventory_movement_journals')->where('movement_id', $saleMovement->id)->value('journal_id');
        $saleJournal = $saleJournalId ? DB::table('accounting_journals')->find($saleJournalId) : null;
        $customerInvoiceJournal = $customerInvoice->journal_id ? DB::table('accounting_journals')->find($customerInvoice->journal_id) : null;
        $supplierDocument = $supplierInvoice->official_document_id ? DB::table('system_official_documents')->find($supplierInvoice->official_document_id) : null;
        $customerDocument = $customerInvoice->official_document_id ? DB::table('system_official_documents')->find($customerInvoice->official_document_id) : null;
        $journalTotals = static function (?object $journal): ?array {
            if (! $journal) return null;
            $totals = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)
                ->selectRaw('coalesce(sum(debit),0)::text as debit, coalesce(sum(credit),0)::text as credit')->first();
            return ['id' => (int) $journal->id, 'status' => $journal->status, 'debit' => $totals->debit, 'credit' => $totals->credit];
        };
        $fifoLayer = DB::table('inventory_fifo_layers')->where('movement_id', $receiptMovementId)->first();
        $allocations = DB::table('inventory_fifo_allocations as a')->join('inventory_fifo_layers as f', 'f.id', '=', 'a.layer_id')
            ->where('a.movement_id', $saleMovement->id)->selectRaw('coalesce(sum(a.quantity),0)::text as quantity, coalesce(sum(a.amount),0)::text as amount')->first();
        $out['financial'] = [
            'purchase_order' => ['id' => (int) $order->id, 'status' => $order->status],
            'receipt' => ['id' => (int) $receipt->id, 'status' => $receipt->status, 'movement_id' => $receiptMovementId],
            'supplier_invoice' => ['id' => (int) $supplierInvoice->id, 'status' => $supplierInvoice->status,
                'official_number' => $supplierInvoice->official_number, 'net' => $supplierInvoice->net_amount,
                'tax' => $supplierInvoice->tax_amount, 'total' => $supplierInvoice->total_amount,
                'journal' => $journalTotals($supplierInvoiceJournal), 'document_sha256' => $supplierDocument?->sha256],
            'ap' => $ap ? ['original' => $ap->original_amount, 'remaining' => $ap->remaining_amount] : null,
            'sales_order' => ['id' => (int) $saleOrderId, 'status' => DB::table('sales_orders')->where('id', $saleOrderId)->value('status')],
            'sale_movement' => ['id' => (int) $saleMovement->id, 'operation' => $saleMovement->operation,
                'quantity' => $saleMovement->quantity, 'journal' => $journalTotals($saleJournal)],
            'customer_invoice' => ['id' => (int) $customerInvoice->id, 'status' => $customerInvoice->status,
                'official_number' => $customerInvoice->official_number, 'net' => $customerInvoice->net_amount,
                'tax' => $customerInvoice->tax_amount, 'total' => $customerInvoice->total_amount,
                'journal' => $journalTotals($customerInvoiceJournal), 'document_sha256' => $customerDocument?->sha256],
            'ar' => $ar ? ['original' => $ar->original_amount, 'remaining' => $ar->remaining_amount] : null,
            'purchase_receipt_journal' => $journalTotals($purchaseJournal),
            'inventory' => ['on_hand' => DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->value('on_hand'),
                'receipt_fifo_layer' => $fifoLayer ? ['original_quantity' => $fifoLayer->original_quantity,
                    'remaining_quantity' => $fifoLayer->remaining_quantity, 'original_value' => $fifoLayer->original_value,
                    'remaining_value' => $fifoLayer->remaining_value] : null,
                'sale_fifo_allocation' => ['quantity' => $allocations->quantity, 'amount' => $allocations->amount]],
        ];
    }
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}
if (! in_array($mode, ['smoke', 'critical', 'prepare'], true)) throw new RuntimeException('Explicit E2E_FIXTURE_MODE=smoke, critical, or readback is required.');
if (DB::table('companies')->exists() || DB::table('users')->exists() || DB::table('maintenance_work_orders')->exists()) {
    throw new RuntimeException('ABORT: E2E critical fixture requires a fresh, empty isolated database.');
}
$tag = strtoupper(Str::random(8));
$requestedGroups = trim((string) (getenv('E2E_FIXTURE_GROUPS') ?: ''));
$fixtureGroups = $requestedGroups === '' ? ['maintenance', 'workshop', 'financial'] : array_values(array_unique(array_filter(array_map('trim', explode(',', $requestedGroups)))));
$unknownGroups = array_diff($fixtureGroups, ['maintenance', 'workshop', 'financial']);
if ($unknownGroups !== []) throw new RuntimeException('Unknown E2E fixture group: '.implode(',', $unknownGroups));
$password = Str::random(48);
$company = Company::factory()->create(['code' => 'DEMO-EG-E2E-'.$tag, 'name' => 'شركة اختبار محلية '.$tag, 'legal_name' => 'شركة اختبار محلية '.$tag, 'setup_complete' => true]);
$branch = Branch::factory()->create(['code' => 'DEMO-EG-E2E-'.$tag, 'company_id' => $company->id, 'active' => true]);
$customer = Customer::factory()->create(['code' => 'DEMO-EG-E2E-'.$tag, 'name' => 'عميل صيانة تجريبي '.$tag, 'status' => 'active']);
if ($mode === 'smoke') {
    $user = User::factory()->create(['name' => 'DEMO-EG E2E smoke '.$tag,
        'email' => 'e2e-smoke-'.$tag.'@example.test', 'password' => Hash::make($password),
        'active' => true, 'email_verified_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $administratorRole = DB::table('roles')->where('code', 'Administrator')->first();
    if (! $administratorRole) throw new RuntimeException('Administrator role definition is missing from migrated RBAC.');
    DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $administratorRole->id, 'created_at' => now(), 'updated_at' => now()]);
    $warehouse = app(InventoryActions::class)->saveWarehouse($user->id, ['code' => 'DEMO-EG-E2E-SMOKE-'.$tag,
        'name' => 'مستودع اختبار الواجهة '.$tag, 'branch_id' => (int) $branch->id, 'active' => true]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'],
        'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    echo json_encode(['database' => $identity->db, 'port' => 55460, 'tag' => $tag,
        'actors' => [['key' => 'smoke', 'id' => (int) $user->id, 'email' => $user->email, 'password' => $password]],
        'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'customer_code' => $customer->code, 'warehouse_id' => (int) $warehouse['id'],
        'coverage' => ['authenticated_shell', 'authorized_core_navigation']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}
$stage = 'M08 field maintenance owner actions';
$actors = [];
foreach (['dispatcher', 'technician', 'reviewer'] as $role) {
    $user = User::factory()->create(['name' => 'DEMO-EG E2E '.$role.' '.$tag,
        'email' => 'e2e-'.$role.'-'.$tag.'@example.test', 'password' => Hash::make($password),
        'active' => true, 'email_verified_at' => now()]);
    $actors[$role] = ['id' => (int) $user->id, 'email' => $user->email, 'password' => $password];
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}
$pendingGrants = [];
$grant = static function (User $user, array $codes, ?int $branchId) use (&$pendingGrants): void {
    foreach ($codes as $code) {
        $pendingGrants[] = ['user_id' => (int) $user->id, 'permission_code' => $code, 'branch_id' => $branchId];
    }
};
$grant(User::findOrFail($actors['dispatcher']['id']), ['M01.branch_view','M08.view','M08.request_create','M08.request_edit','M08.request_approve','M08.workorder_manage','M08.assign','M08.checklist_manage','M08.rate_approve','M08.close', 'M09.view','M09.manage','M09.intake_manage','M09.custody','M09.job_manage','M09.assign','M09.deliver', 'M05.order_view','M05.order_create','M05.order_submit','M05.receipt_view','M05.receipt_create','M06.receipt_create','M06.reservation_view','M06.balance_view','M06.movement_view','M11.source_post','M11.opening_entry','M11.journal_view','M12.supplier_invoice_view','M12.supplier_invoice_manage','M12.supplier_invoice_post','M12.customer_invoice_view','M12.customer_invoice_manage','M12.customer_invoice_post','M12.ap_view','M12.ar_view','M04.sales_order_view','M04.sales_order_create','M04.sales_order_edit','M04.sales_order_submit','M04.sales_order_delivery_create'], (int) $branch->id);
$grant(User::findOrFail($actors['dispatcher']['id']), ['M03.customer_view','M03.supplier_view','M02.product_view','M02.price_view','M02.price_manage','M06.warehouse_create','M11.setup_manage','M11.tax_manage','M11.tax_calculate','M11.account_manage','M11.period_open','M11.opening_manage','M13.account_manage','M04.quotation_policy_manage'], null);
$grant(User::findOrFail($actors['technician']['id']), ['M08.view','M08.visit_record','M08.labor_record','M08.workorder_manage', 'M09.view','M09.execute','M09.labor_record'], (int) $branch->id);
$grant(User::findOrFail($actors['reviewer']['id']), ['M01.branch_view','M08.view','M08.verify','M08.close','M08.document_view','M08.reports_view','M09.view','M09.nonbillable_approve','M09.verify','M09.document_view', 'M05.order_view','M05.order_approve','M05.order_issue','M05.receipt_post','M06.receipt_post','M06.reservation_manage','M06.issue_post','M06.balance_view','M06.movement_view','M04.sales_order_view','M04.sales_order_approve','M04.sales_order_delivery_confirm','M11.source_post','M11.receipt_post','M11.journal_post','M11.journal_view','M12.supplier_invoice_post','M12.customer_invoice_post','M12.ar_view'], (int) $branch->id);
$grant(User::findOrFail($actors['reviewer']['id']), ['M11.tax_calculate','M11.account_manage','M11.period_open','M11.setup_manage','M11.opening_manage','M11.opening_approve','M11.financial_activate','M02.product_view','M02.price_view','M02.price_approve','M03.customer_view','M03.supplier_view','M13.account_manage'], null);
$grantErrors = [];
foreach ($pendingGrants as $candidate) {
    $code = $candidate['permission_code'];
    $definition = \App\Modules\Organization\Application\PermissionCatalog::DEFINITIONS[$code] ?? null;
    if (! $definition) {
        $grantErrors[] = 'unknown permission '.$code;
        continue;
    }
    $scope = $definition[1];
    $hasBranch = $candidate['branch_id'] !== null;
    if (($scope === 'company' && $hasBranch) || (in_array($scope, ['branch', 'warehouse'], true) && ! $hasBranch)) {
        $grantErrors[] = $candidate['permission_code'].' expected '.$scope.' scope';
    }
}
if ($grantErrors !== []) throw new RuntimeException('E2E permission grants rejected: '.implode('; ', array_values(array_unique($grantErrors))));
foreach ($pendingGrants as $candidate) {
    DB::table('access_grants')->insert($candidate + ['created_at' => now(), 'updated_at' => now()]);
}
$smokeAdmin = User::factory()->create(['name' => 'DEMO-EG E2E Administrator '.$tag,
    'email' => 'e2e-smoke-'.$tag.'@example.test', 'password' => Hash::make($password),
    'active' => true, 'email_verified_at' => now()]);
$actors['smoke'] = ['id' => (int) $smokeAdmin->id, 'email' => $smokeAdmin->email, 'password' => $password];
$smokeBranch = Branch::factory()->create(['code' => 'DEMO-EG-E2E-SMOKE-'.$tag, 'company_id' => $company->id, 'active' => true]);
$smokeCustomer = Customer::factory()->create(['code' => 'DEMO-EG-E2E-SMOKE-'.$tag, 'name' => 'عميل اختبار واجهة '.$tag, 'status' => 'active']);
DB::table('user_branch_scopes')->insert(['user_id' => $smokeAdmin->id, 'branch_id' => $smokeBranch->id,
    'created_at' => now(), 'updated_at' => now()]);
$administratorRole = DB::table('roles')->where('code', 'Administrator')->first();
if (! $administratorRole) throw new RuntimeException('Administrator role definition is missing from migrated RBAC.');
DB::table('user_roles')->insert(['user_id' => $smokeAdmin->id, 'role_id' => $administratorRole->id,
    'created_at' => now(), 'updated_at' => now()]);
$smokeWarehouse = app(InventoryActions::class)->saveWarehouse($smokeAdmin->id, ['code' => 'DEMO-EG-E2E-SMOKE-WH-'.$tag,
    'name' => 'مستودع اختبار الواجهة '.$tag, 'branch_id' => (int) $smokeBranch->id, 'active' => true]);
DB::table('user_warehouse_scopes')->insert(['user_id' => $smokeAdmin->id, 'warehouse_id' => $smokeWarehouse['id'],
    'branch_id' => $smokeBranch->id, 'created_at' => now(), 'updated_at' => now()]);
$outsider = User::factory()->create(['name' => 'DEMO-EG E2E outsider '.$tag, 'email' => 'e2e-outsider-'.$tag.'@example.test',
    'password' => Hash::make($password), 'active' => true, 'email_verified_at' => now()]);
$actors['outsider'] = ['id' => (int) $outsider->id, 'email' => $outsider->email, 'password' => $password];
$dispatcher = User::findOrFail($actors['dispatcher']['id']);
if (in_array('maintenance', $fixtureGroups, true)) {
$request = app(MaintenanceActions::class)->saveRequest($actors['dispatcher']['id'], null, 0, (string) Str::uuid(), [
    'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $actors['technician']['id'],
    'description' => 'بلاغ اختبار صيانة لوحة إنارة — عطل انقطاع التغذية.', 'priority' => 'normal',
    'reported_at' => now('UTC')->subMinutes(45)->toIso8601String(), 'preferred_date' => now()->toDateString(),
]);
app(MaintenanceActions::class)->requestState($actors['dispatcher']['id'], $request, 1, 'submit');
app(MaintenanceActions::class)->requestState($actors['dispatcher']['id'], $request, 2, 'accept');
$workOrder = app(MaintenanceActions::class)->createWorkOrder($actors['dispatcher']['id'], $request, 3, (string) Str::uuid(), [
    'instructions' => 'افحص التوصيلات وسجل نتيجة الاختبار الفعلية.', 'acknowledgment_required' => true,
]);
$execution = app(MaintenanceExecution::class);
$template = $execution->saveTemplate($actors['dispatcher']['id'], null, 0, ['branch_id' => $branch->id,
    'code' => 'DEMO-EG-E2E-'.$tag, 'title' => 'قائمة فحص اختبارية', 'active' => true,
    'items' => [['key' => 'safety_check', 'label' => 'اكتمل فحص السلامة', 'required' => true]]]);
$work = DB::table('maintenance_work_orders')->find($workOrder);
$assignment = $execution->assign($actors['dispatcher']['id'], $workOrder, (int) $work->version, [
    'technician_id' => $actors['technician']['id'], 'starts_at' => now('UTC')->subHour()->toIso8601String(),
    'ends_at' => now('UTC')->addHours(2)->toIso8601String(),
]);
$work = DB::table('maintenance_work_orders')->find($workOrder);
$visit = $execution->schedule($actors['dispatcher']['id'], $workOrder, (int) $work->version, (string) Str::uuid(), [
    'assignment_id' => $assignment, 'scheduled_at' => now('UTC')->subMinutes(20)->toIso8601String(),
    'template_id' => $template, 'mandatory' => true,
]);
$work = DB::table('maintenance_work_orders')->find($workOrder);
app(MaintenanceActions::class)->state($actors['technician']['id'], $workOrder, (int) $work->version, 'start', 'فني الاختبار يبدأ التنفيذ.');
$arrive = now('UTC')->startOfMinute()->subMinutes(25);
$leave = now('UTC')->startOfMinute()->subMinutes(5);
$execution->recordVisit($actors['technician']['id'], $workOrder, $visit, 1, [
    'arrival_at' => $arrive->toIso8601String(), 'departure_at' => $leave->toIso8601String(),
    'work_performed' => 'فحص التوصيلة وإحكام الطرف.', 'diagnosis' => 'ارتخاء في طرف التوصيل.',
    'acknowledgment' => 'أقر العميل بإتمام الفحص.', 'outcome' => 'completed',
], ['safety_check' => ['result' => 'complete']]);
$rate = ['branch_id' => (int) $branch->id, 'technician_id' => (int) $actors['technician']['id'],
    'hourly_cost' => '45.00', 'effective_from' => now()->subDay()->toDateString(), 'effective_to' => now()->addDay()->toDateString(),
    'reference' => 'Synthetic local E2E labor rate'];
app(\App\Modules\FieldMaintenance\Application\MaintenanceCostProposals::class)->propose($actors['technician']['id'], 'labor_rate', null, $rate);
$execution->approveRate($actors['dispatcher']['id'], $actors['technician']['id'], $rate);
$execution->labor($actors['technician']['id'], $workOrder, (string) Str::uuid(), [
    'visit_id' => $visit, 'starts_at' => $arrive->toIso8601String(), 'ends_at' => $leave->toIso8601String(), 'work_type' => 'فحص توصيلات',
]);
$work = DB::table('maintenance_work_orders')->find($workOrder);
app(MaintenanceActions::class)->state($actors['technician']['id'], $workOrder, (int) $work->version, 'complete', 'اكتمل التنفيذ وتوثقت الزيارة والعمالة.');
}
if (in_array('workshop', $fixtureGroups, true)) {
$stage = 'M09 WorkshopRepairs owner actions';
$technician = User::findOrFail($actors['technician']['id']);
$workshopActions = app(WorkshopActions::class);
$workshopId = $workshopActions->saveWorkshop($dispatcher->id, null, 0, ['code' => 'DEMO-EG-M09-'.$tag,
    'name' => 'ورشة اختبار محلية '.$tag, 'specialty' => 'فحص وإصلاح قواطع كهربائية لأغراض الاختبار.',
    'branch_id' => (int) $branch->id, 'supervisor_id' => $dispatcher->id, 'warehouse_id' => null,
    'capacity' => 2, 'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '16:00']], 'active' => true]);
$workshopActions->member($dispatcher->id, $workshopId, 1, $technician->id, true);
$intake = $workshopActions->saveIntake($dispatcher->id, null, 0, (string) Str::uuid(), [
    'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id,
    'equipment_description' => 'قاطع كهربائي تجريبي وارد للإصلاح.', 'model' => 'DEMO-EG-BREAKER',
    'serial_number' => 'DEMO-EG-SERIAL-'.$tag, 'accessories' => 'مفتاح التشغيل فقط',
    'reported_fault' => 'فصل متكرر وفق بلاغ الاختبار.', 'arrival_condition' => 'دون تلف ظاهر.',
    'customer_owned' => true, 'location' => 'استلام الورشة',
]);
$workshopActions->receive($dispatcher->id, $intake, 1, (string) Str::uuid(), now('UTC')->subMinutes(10)->toIso8601String(), 'تم استلام الجهاز وملحقاته للاختبار.');
$job = $workshopActions->createJob($dispatcher->id, $intake, 2, (string) Str::uuid(), $workshopId, 'normal', 'nonbillable');
$workshopActions->assign($dispatcher->id, $job, 1, $workshopId, $technician->id, 'إسناد تجريبي للفني المختص.');
$workshopActions->diagnose($technician->id, $job, 2, ['diagnosis' => 'ارتخاء في طرف التوصيل يحتاج الفحص.',
    'planned_work' => 'إحكام التوصيلة ثم اختبار التشغيل الفعلي.', 'required_tests' => [['name' => 'اختبار التشغيل', 'required' => true]],
    'estimated_minutes' => 30]);
}
if (in_array('financial', $fixtureGroups, true)) {
$stage = 'M11 financial and inventory setup owner actions';
$maker = $dispatcher;
$poster = User::findOrFail($actors['reviewer']['id']);
$supplier = Supplier::factory()->create(['code' => 'DEMO-EG-E2E-SUP-'.$tag, 'status' => 'active']);
$stockProduct = Product::factory()->create(['sku' => 'DEMO-EG-E2E-STK-'.$tag, 'kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
$inventory = app(InventoryActions::class);
$warehouse = $inventory->saveWarehouse($maker->id, ['code' => 'DEMO-EG-E2E-WH-'.$tag,
    'name' => 'مستودع اختبار محلي '.$tag, 'branch_id' => (int) $branch->id, 'active' => true]);
foreach ([$maker, $poster] as $user) {
    DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'],
        'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}
$date = now('Africa/Cairo')->toDateString();
$activationRow = DB::table('accounting_activation')->find(1);
if (! $activationRow || $activationRow->activated_at !== null) throw new RuntimeException('E2E financial setup requires an inactive fresh M11 activation record.');
$accountActions = app(AccountingActions::class);
$mappings = [];
foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
    $mappings[$purpose] = $accountActions->account($maker->id, null, 0, ['code' => 'E2E-'.strtoupper($purpose).'-'.$tag,
        'name' => 'حساب اختبار '.$label, 'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true]);
}
$periodStart = now('Africa/Cairo')->startOfYear()->toDateString();
$periodEnd = now('Africa/Cairo')->endOfYear()->toDateString();
$accountActions->period($maker->id, ['name' => 'فترة اختبار E2E '.now('Africa/Cairo')->year, 'starts_on' => $periodStart, 'ends_on' => $periodEnd]);
$setup = app(FinancialActivation::class);
$version = (int) $activationRow->version;
$setup->save($maker->id, $version, ['go_live_date' => $date, 'mappings' => $mappings]);
$version++;
foreach ([['sale', null], ['purchase', (int) $stockProduct->id]] as [$type, $taxProduct]) {
    $tax = ['code' => 'E2E-'.$type.'-'.$tag, 'name' => 'Synthetic E2E '.$type.' tax (not statutory)',
        'transaction_type' => $type, 'product_id' => $taxProduct, 'rate' => '14', 'starts_on' => $date,
        'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2,
        'legal_reference' => 'Synthetic disposable E2E fixture only; not a statutory tax treatment.'];
    if ($type === 'purchase') $tax['purchase_recoverable'] = true;
    $setup->addTaxRule($maker->id, $version++, $tax);
}
$setup->validateTaxTreatment($maker->id, $version++, 'Synthetic isolated E2E tax validation reference.');
$opening = app(OpeningBalances::class);
$batch = $opening->save($maker->id, $version, (string) Str::uuid(), [], true,
    'Synthetic test declaration: no prior opening balances in this disposable acceptance database.');
$opening->advance($maker->id, $batch, 1, 'submit');
$opening->advance($poster->id, $batch, 2, 'approve');
app(ActivateFinancialPosting::class)->execute($poster->id, $batch, 3);
$stage = 'M05 purchasing order create/approve/issue owner actions';
$purchaseOrder = app(PurchaseOrders::class)->save($maker->id, ['creation_key' => (string) Str::uuid(),
    'branch_id' => (int) $branch->id, 'supplier_id' => (int) $supplier->id, 'order_date' => $date,
    'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => 'Synthetic E2E acceptance terms',
    'notes' => 'Real posting fixture to provide actual FIFO sourced stock.', 'tax_mode' => 'exclusive', 'reported_tax' => null,
    'lines' => [['product_id' => $stockProduct->id, 'quantity' => '4.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00']]], null, 0);
$purchaseOrders = app(PurchaseOrders::class);
$purchaseOrders->transition($maker->id, (int) $purchaseOrder, 1, 'submit');
$purchaseOrders->transition($poster->id, (int) $purchaseOrder, 2, 'approve');
$purchaseOrders->issue($poster->id, (int) $purchaseOrder);
$orderLine = (int) DB::table('purchasing_order_lines')->where('order_id', $purchaseOrder)->value('id');
$stage = 'M05 receiving draft and submit owner actions';
$receipt = app(PurchaseReceiving::class)->save($maker->id, ['creation_key' => (string) Str::uuid(),
    'order_id' => (int) $purchaseOrder, 'warehouse_id' => (int) $warehouse['id'], 'receipt_date' => $date,
    'delivery_reference' => 'DEMO-EG-E2E-RECEIPT-'.$tag, 'notes' => 'Synthetic local E2E purchase receipt.',
    'lines' => [['order_line_id' => $orderLine, 'quantity' => '4.00000000']]], null, 0);
app(PurchaseReceiving::class)->submit($maker->id, (int) $receipt, 1);
$stage = 'M06 posted purchase receipt owner action';
app(PostPurchaseReceipt::class)->execute($poster->id, (int) $receipt, 2);
$stage = 'M12 source-backed supplier invoice/AP draft owner action';
$receiptMovementId = (int) DB::table('purchasing_receipt_postings')->where('receipt_id', $receipt)->value('movement_id');
$receiptLineId = (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $receipt)->value('id');
$receivables = app(ReceivablesPayablesApi::class);
$supplierInvoice = $receivables->createSupplierInvoice($maker->id, ['creation_key' => (string) Str::uuid(),
    'supplier_id' => (int) $supplier->id, 'supplier_invoice_number' => 'DEMO-EG-E2E-SUPINV-'.$tag,
    'invoice_date' => $date, 'due_date' => now('Africa/Cairo')->addDays(30)->toDateString(), 'tax_mode' => 'exclusive',
    'lines' => [['order_line_id' => $orderLine, 'quantity' => '4.00000000', 'unit_price' => '10.00000000',
        'discount_amount' => '0.00', 'matches' => [['receipt_line_id' => $receiptLineId, 'quantity' => '4.00000000']]]]]);
$supplierInvoiceRow = DB::table('rp_supplier_invoices')->find($supplierInvoice);
if (! $supplierInvoiceRow || $supplierInvoiceRow->status !== 'draft' || $supplierInvoiceRow->posted_at !== null
    || DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $supplierInvoice)->exists()) {
    throw new RuntimeException('M12 source-backed supplier invoice fixture must remain a draft until browser actions.');
}
$stage = 'M04 commercial terms, approved price, and Sales order draft owner actions';
$unit = (int) $stockProduct->base_unit_id;
$paymentTerms = 'DEMO-E2E-PAY-'.$tag;
$deliveryTerms = 'DEMO-E2E-DEL-'.$tag;
$commercial = app(QuotationCommercialPolicy::class);
$commercial->term($maker->id, ['code' => $paymentTerms, 'kind' => 'payment', 'name' => 'Synthetic E2E payment', 'body' => $paymentTerms]);
$commercial->term($maker->id, ['code' => $deliveryTerms, 'kind' => 'delivery', 'name' => 'Synthetic E2E delivery', 'body' => $deliveryTerms]);
$price = app(SalePrices::class)->save($maker->id, (int) $stockProduct->id, ['amount' => '12.00000000', 'unit_id' => $unit,
    'starts_on' => $date, 'ends_on' => null, 'reference' => 'Synthetic E2E approved customer price.']);
app(SalePrices::class)->decide($poster->id, $price, 'approve');
$saleOrder = app(SalesOrderActions::class)->save($maker->id, ['creation_key' => (string) Str::uuid(),
    'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'order_date' => $date,
    'requested_delivery_date' => null, 'discount_mode' => 'percent', 'discount_value' => '0',
    'payment_terms' => $paymentTerms, 'delivery_terms' => $deliveryTerms, 'notes' => 'Synthetic E2E delivered source.',
    'lines' => [['product_id' => $stockProduct->id, 'unit_id' => $unit, 'quantity' => '4.00000000',
        'requested_price' => null, 'description' => 'Synthetic delivered test product', 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null]]]);
app(SalesOrderActions::class)->submit($maker->id, (int) $saleOrder->id, 1);
$saleLineId = (int) $saleOrder->lines->first()->id;
$stage = 'M04 Sales order approval and Inventory FIFO reservation owner actions';
app(ApproveSalesOrder::class)->execute($poster->id, (int) $saleOrder->id, 2, [$saleLineId => (int) $warehouse['id']]);
$stage = 'M04 Sales delivery instruction owner action';
$delivery = app(SalesDeliveries::class)->create($maker->id, (int) $saleOrder->id, ['creation_key' => (string) Str::uuid(),
    'reference' => 'DEMO-EG-E2E-SALE-DELIVERY-'.$tag, 'lines' => [['line_id' => $saleLineId, 'quantity' => '4.00000000']]]);
$stage = 'M04 Sales delivery confirmation and Inventory FIFO issue owner actions';
app(SalesDeliveries::class)->confirm($poster->id, (int) $delivery);
$stage = 'M12 source-backed customer invoice/AR draft owner action';
$deliveryLineId = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $delivery)->value('id');
$customerInvoice = $receivables->createCustomerInvoice($maker->id, ['creation_key' => (string) Str::uuid(),
    'customer_id' => (int) $customer->id, 'invoice_date' => $date, 'due_date' => now('Africa/Cairo')->addDays(30)->toDateString(),
    'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '4.00000000']]]);
$customerInvoiceRow = DB::table('rp_customer_invoices')->find($customerInvoice);
$arDraft = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $customerInvoice)->first();
if (! $customerInvoiceRow || $customerInvoiceRow->status !== 'draft' || $customerInvoiceRow->posted_at !== null || $arDraft) {
    throw new RuntimeException('M12 source-backed customer invoice fixture must remain a draft until browser actions.');
}
$supplierJournal = DB::table('accounting_journals')->where('source_key', 'purchase-receipt:'.$receipt)->first();
$saleMovementId = (int) DB::table('inventory_movements')->where('operation', 'reservation_issue')->where('source_module', 'Sales')
    ->where('source_id', (string) $saleOrder->id)->where('source_line', (string) $saleLineId)->value('id');
$saleJournalId = (int) DB::table('inventory_movement_journals')->where('movement_id', $saleMovementId)->value('journal_id');
$saleJournal = DB::table('accounting_journals')->find($saleJournalId);
if (! $supplierJournal || $supplierJournal->status !== 'posted' || ! $saleJournal || $saleJournal->status !== 'posted'
    || ! $supplierInvoiceRow || ! $customerInvoiceRow) {
    throw new RuntimeException('E2E financial source graph failed source posting and draft invoice prerequisites.');
}
}
$fixtureOutput = ['database' => $identity->db, 'port' => 55460, 'tag' => $tag, 'fixture_groups' => $fixtureGroups,
    'actors' => array_map(static fn (string $key, array $actor): array => ['key' => $key, ...$actor], array_keys($actors), array_values($actors)),
    'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'customer_code' => $customer->code,
    'smoke' => ['branch_id' => (int) $smokeBranch->id, 'customer_id' => (int) $smokeCustomer->id,
        'customer_code' => $smokeCustomer->code, 'warehouse_id' => (int) $smokeWarehouse['id']]];
if (isset($workOrder)) $fixtureOutput += ['request_id' => $request, 'work_order_id' => $workOrder, 'visit_id' => $visit, 'expected' => ['status' => 'work_completed']];
if (isset($job)) $fixtureOutput['workshop'] = ['id' => $workshopId, 'intake_id' => $intake, 'job_id' => $job,
        'expected_initial_status' => 'inspection'];
if (isset($customerInvoice)) $fixtureOutput['financial'] = ['purchase_order_id' => (int) $purchaseOrder, 'receipt_id' => (int) $receipt,
        'supplier_id' => (int) $supplier->id,
        'purchase_journal_id' => (int) $supplierJournal->id, 'sales_order_id' => (int) $saleOrder->id,
        'delivery_id' => (int) $delivery, 'delivery_line_id' => $deliveryLineId,
        'supplier_invoice_id' => (int) $supplierInvoice, 'supplier_invoice_number' => $supplierInvoiceRow->supplier_invoice_number,
        'supplier_invoice_total' => $supplierInvoiceRow->total_amount,
        'sale_journal_id' => (int) $saleJournal->id, 'customer_invoice_id' => (int) $customerInvoice,
        'customer_name' => $customerInvoiceRow->customer_name, 'customer_invoice_total' => $customerInvoiceRow->total_amount,
        'on_hand_after_sales' => (string) DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $stockProduct->id)->value('on_hand'),
        'fifo_receipt_movement_id' => $receiptMovementId, 'fifo_sale_movement_id' => $saleMovementId,
        'fifo_sale_movement_count' => DB::table('inventory_movements')->where('operation', 'reservation_issue')->where('source_module', 'Sales')->where('source_id', (string) $saleOrder->id)->where('source_line', (string) $saleLineId)->count(),
        'stock_product_sku' => $stockProduct->sku, 'stock_product_id' => (int) $stockProduct->id,
        'warehouse_id' => (int) $warehouse['id'], 'branch_id' => (int) $branch->id,
        'stock_product_id' => (int) $stockProduct->id, 'warehouse_id' => (int) $warehouse['id'],
        'sale_line_id' => $saleLineId];
echo json_encode($fixtureOutput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

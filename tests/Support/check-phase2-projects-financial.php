<?php

// Full source-driven M07 to M12 project projection acceptance in an isolated PostgreSQL transaction.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\FinancialReports;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Products\Domain\Product;
use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\ProjectExecution;
use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Application\ProjectMaterials;
use App\Modules\Projects\Application\ProjectPlanning;
use App\Modules\Projects\Application\ProjectSources;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectMilestone;
use App\Modules\Projects\Domain\ProjectPhase;
use App\Modules\Projects\Presentation\ProjectFinancialExportController;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ReceivablesPayables\Domain\CustomerInvoice;
use App\Modules\ReceivablesPayables\Domain\SupplierInvoice;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Domain\TreasuryAccount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storageOverride = getenv('PHASE2_STORAGE_ROOT');
$storage = is_string($storageOverride) && $storageOverride !== '' ? $storageOverride : $root.'/.tmp/phase2-project-financial-storage';
$normalizedRoot = str_replace('\\', '/', $root).'/.tmp/';
if (! str_starts_with(str_replace('\\', '/', $storage), $normalizedRoot)) {
    throw new RuntimeException('ABORT: PHASE2_STORAGE_ROOT must stay inside this workspace .tmp directory.');
}
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated Phase 1 storage path.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

$db = DB::connection();
$connection = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
$database = (string) config('database.connections.pgsql.database');
$host = (string) config('database.connections.pgsql.host');
$port = (string) config('database.connections.pgsql.port');
if ($db->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || ! str_ends_with($database, '_test') || $database !== $connection->db
    || $host !== '127.0.0.1' || ! in_array($connection->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || $port !== (string) $connection->port || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
    throw new RuntimeException('ABORT before writes: an isolated localhost PostgreSQL *_test database is required.');
}
$commitProjectFixture = getenv('PHASE2_COMMIT_FIXTURES') === '1';
if ($commitProjectFixture && ($database !== 'erp_elec_new_test' || ! in_array($port, ['55435', '55437', '55440'], true))) {
    throw new RuntimeException('ABORT before writes: committed M07 browser fixtures require erp_elec_new_test on loopback port 55435, 55437, or 55440.');
}
$browserPassword = getenv('PHASE2_BROWSER_PASSWORD');
if ($commitProjectFixture && (! is_string($browserPassword) || strlen($browserPassword) < 20)) {
    throw new RuntimeException('ABORT before writes: commit mode requires a private PHASE2_BROWSER_PASSWORD of at least 20 characters.');
}
$date = now('Africa/Cairo')->toDateString();

$need = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (callable $work, string $label, string $kind = 'validation'): Throwable {
    try {
        $work();
    } catch (Throwable $error) {
        $accepted = match ($kind) {
            'forbidden' => $error instanceof HttpExceptionInterface && $error->getStatusCode() === 403,
            'conflict' => $error instanceof HttpExceptionInterface && $error->getStatusCode() === 409,
            'duplicate' => $error instanceof QueryException && ($error->errorInfo[0] ?? null) === '23505'
                && str_contains($error->getMessage(), 'rp_supplier_invoices'),
            default => $error instanceof ValidationException,
        };
        if (! $accepted) {
            throw new RuntimeException('Unexpected error for '.$label.': '.$error::class.' '.$error->getMessage(), 0, $error);
        }

        return $error;
    }
    throw new RuntimeException('Expected '.$kind.' denial was not raised: '.$label);
};
$grant = static function (User $user, array $codes, ?int $branch = null): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $code,
            'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn (): string => (string) Str::uuid();

DB::beginTransaction();
$commitRaceFixture = false;
$evidence = [];
$factoryFailures = [];
try {
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]
        + ($commitProjectFixture ? ['password' => Hash::make($browserPassword)] : []));
    $poster = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($maker, ['M01.company_update', 'M02.product_view', 'M02.price_view', 'M02.price_manage', 'M03.supplier_view', 'M03.customer_view', 'M04.quotation_policy_manage',
        'M07.project_create', 'M07.project_view', 'M07.project_update', 'M07.project_manage', 'M07.budget_manage', 'M07.project_activate', 'M07.project_close', 'M07.document_view', 'M07.document_manage', 'M07.document_approve', 'M07.financial_view', 'M07.export', 'M07.material_manage', 'M07.material_issue',
        'M06.warehouse_create', 'M06.warehouse_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.account_manage', 'M11.period_open', 'M11.period_close',
        'M11.opening_manage', 'M11.opening_entry', 'M11.tax_calculate', 'M11.journal_view', 'M11.journal_create', 'M13.account_manage']);
    $grant($poster, ['M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M02.price_approve', 'M11.tax_calculate',
        'M11.opening_approve', 'M11.financial_activate']);
    $company = DB::table('companies')->where('id', 1)->first();
    if (! $company) {
        app(SaveCompany::class)->execute($maker, ['code' => 'PH1-SYNTHETIC', 'name' => 'شركة اختبار العمليات المالية',
            'legal_name' => 'Synthetic Financial Operations Test Company', 'tax_registration' => 'SYNTHETIC-TEST-ONLY',
            'address' => 'عنوان اصطناعي للاختبار فقط'], null);
    } elseif (! $company->setup_complete || ! $company->legal_name || ! $company->address) {
        throw new RuntimeException('Existing company identity is incomplete; preserving it rather than overwriting it for PDF testing.');
    }
    $branch = Branch::factory()->create(['code' => 'PH1-SI-'.strtoupper(Str::random(7))]);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $otherSupplier = Supplier::factory()->create(['status' => 'active']);
    $customer = Customer::factory()->create(['status' => 'active']);
    $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $activationRow = DB::table('accounting_activation')->where('id', 1)->first();
    if (! $activationRow || $activationRow->activated_at !== null) {
        throw new RuntimeException('Refusing to replace an existing activation state in the isolated test database.');
    }
    $mappings = [];
    $accountActions = app(AccountingActions::class);
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = $accountActions->account((int) $maker->id, null, 0, [
            'code' => 'PH1-'.strtoupper($purpose).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic '.$label,
            'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true,
        ]);
    }
    $periodStart = now('Africa/Cairo')->startOfYear()->toDateString();
    $periodEnd = now('Africa/Cairo')->endOfYear()->toDateString();
    if (DB::table('accounting_periods')->where('starts_on', '<=', $periodEnd)->where('ends_on', '>=', $periodStart)->exists()) {
        throw new RuntimeException('Current-year accounting period overlaps existing test data; refusing to rewrite period history.');
    }
    $accountActions->period((int) $maker->id, ['name' => 'Synthetic Phase 1 '.now('Africa/Cairo')->year,
        'starts_on' => $periodStart, 'ends_on' => $periodEnd]);
    $setup = app(FinancialActivation::class);
    $version = (int) $activationRow->version;
    $setup->save((int) $maker->id, $version, ['go_live_date' => $date, 'mappings' => $mappings]);
    $version++;
    foreach ([['sale', null], ['purchase', (int) $product->id]] as [$type, $taxProduct]) {
        $taxExists = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
            ->where('r.transaction_type', $type)->where('r.product_id', $taxProduct)->where('r.starts_on', '<=', $date)
            ->whereRaw('(coalesce(e.ends_on,r.ends_on) is null or coalesce(e.ends_on,r.ends_on)>=?::date)', [$date])
            ->whereRaw('coalesce(e.withdrawn,false)=false')->exists();
        if (! $taxExists) {
            $taxData = ['code' => 'PH1-'.strtoupper($type).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic Phase 1 '.$type.' tax',
                'transaction_type' => $type, 'product_id' => $taxProduct, 'rate' => '14', 'starts_on' => $date, 'ends_on' => null,
                'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic isolated PostgreSQL Phase 1 acceptance fixture'];
            if ($type === 'purchase') {
                $taxData['purchase_recoverable'] = true;
            }
            $setup->addTaxRule((int) $maker->id, $version, $taxData);
            $version++;
        }
    }
    $setup->validateTaxTreatment((int) $maker->id, $version, 'Synthetic isolated Phase 1 test tax treatment verification');
    $version++;
    $opening = app(OpeningBalances::class);
    $batch = $opening->save((int) $maker->id, $version, $uuid(), [], true,
        'Synthetic test declaration: no prior balances in this disposable PostgreSQL acceptance database.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit');
    $opening->advance((int) $poster->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $poster->id, $batch, 3);
    $purchaseTax = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
        ->where('r.transaction_type', 'purchase')->where('r.starts_on', '<=', $date)
        ->whereRaw('(coalesce(e.ends_on,r.ends_on) is null or coalesce(e.ends_on,r.ends_on)>=?::date)', [$date])
        ->whereRaw('coalesce(e.withdrawn,false)=false')->where(fn ($query) => $query->whereNull('r.product_id')->orWhere('r.product_id', $product->id))
        ->orderByRaw('case when r.product_id = ? then 0 else 1 end', [$product->id])->first(['r.id', 'r.rate', 'r.purchase_recoverable']);
    $need($purchaseTax && $purchaseTax->purchase_recoverable && bccomp((string) $purchaseTax->rate, '0', 8) > 0,
        'No effective recoverable purchase tax treatment applies to the synthetic product.');

    // Factories persist only draft source records and Treasury master accounts.
    $demoBranch = Branch::factory()->create(['code' => 'DEMO-EG-PH1-'.strtoupper(Str::random(7))]);
    $demoSupplier = Supplier::factory()->create(['code' => 'DEMO-EG-PH1-S-'.strtoupper(Str::random(6)), 'status' => 'active']);
    $demoCustomer = Customer::factory()->create(['code' => 'DEMO-EG-PH1-C-'.strtoupper(Str::random(6)), 'status' => 'active']);
    $demoUser = User::factory()->disabledDemo()->create();
    $factorySupplierInvoice = null;
    try {
        $factorySupplierInvoice = DB::transaction(fn () => SupplierInvoice::factory()->create(['branch_id' => $demoBranch->id, 'supplier_id' => $demoSupplier->id,
            'supplier_name' => $demoSupplier->name, 'created_by' => $demoUser->id]));
    } catch (Throwable $error) {
        $factoryFailures['supplier_invoice'] = $error::class.': '.mb_substr($error->getMessage(), 0, 350);
    }
    $factoryCustomerInvoice = null;
    try {
        $factoryCustomerInvoice = DB::transaction(fn () => CustomerInvoice::factory()->create(['branch_id' => $demoBranch->id, 'customer_id' => $demoCustomer->id,
            'customer_name' => $demoCustomer->name, 'created_by' => $demoUser->id]));
    } catch (Throwable $error) {
        $factoryFailures['customer_invoice'] = $error::class.': '.mb_substr($error->getMessage(), 0, 350);
    }
    $factoryBank = TreasuryAccount::factory()->create(['code' => 'DEMO-EG-PH1-BANK-'.strtoupper(Str::random(4))]);
    $factoryCash = TreasuryAccount::factory()->create(['code' => 'DEMO-EG-PH1-CASH-'.strtoupper(Str::random(4))]);
    $need($factorySupplierInvoice !== null && $factorySupplierInvoice->exists && $factorySupplierInvoice->status === 'draft' && $factorySupplierInvoice->posted_at === null
        && $factoryCustomerInvoice !== null && $factoryCustomerInvoice->exists && $factoryCustomerInvoice->status === 'draft' && $factoryCustomerInvoice->posted_at === null
        && $factoryBank->exists && $factoryBank->kind === 'bank' && $factoryCash->exists && $factoryCash->kind === 'cash',
        'Draft invoice or treasury account factory failed its PostgreSQL persistence check.');
    $factoryProject = Project::factory()->create(['code' => 'DEMO-EG-PH2-FACTORY-'.strtoupper(Str::random(5)),
        'branch_id' => $demoBranch->id, 'customer_id' => $demoCustomer->id, 'customer_name' => $demoCustomer->name,
        'manager_id' => $demoUser->id, 'created_by' => $demoUser->id]);
    $factoryMilestone = ProjectMilestone::factory()->create(['project_id' => $factoryProject->id, 'target_date' => $factoryProject->end_date]);
    $need($factoryProject->exists && $factoryMilestone->exists && $factoryMilestone->status === 'pending'
        && (int) $factoryMilestone->responsible_id === (int) $factoryProject->manager_id && $factoryMilestone->completed_by === null,
        'Project and milestone factories persist a coherent, pending synthetic project graph without fabricated approval evidence.');
    $treasury = app(TreasuryApi::class);
    $bankAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'PH1-OP-BANK-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic Phase 1 operational bank', 'kind' => 'bank', 'gl_account_id' => $mappings['bank']]);
    $cashAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'PH1-OP-CASH-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic Phase 1 operational cash', 'kind' => 'cash', 'gl_account_id' => $mappings['cash']]);

    foreach ([[$maker, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_create', 'M05.order_submit', 'M05.receipt_view', 'M05.receipt_create', 'M06.receipt_create', 'M06.reservation_view', 'M06.reservation_manage', 'M06.movement_view', 'M11.tax_calculate', 'M11.source_post', 'M11.report_view', 'M11.journal_view', 'M11.account_manage', 'M11.journal_create', 'M11.journal_submit', 'M12.supplier_invoice_manage', 'M12.supplier_invoice_post', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.ap_view', 'M12.ar_view', 'M12.allocation_manage', 'M12.credit_note_manage', 'M13.payment_prepare', 'M13.payment_execute', 'M13.collection_execute', 'M13.transfer_execute', 'M04.sales_order_view', 'M04.sales_order_create', 'M04.sales_order_edit', 'M04.sales_order_submit', 'M04.sales_order_delivery_create', 'M07.project_create', 'M07.project_view', 'M07.project_update', 'M07.project_manage', 'M07.budget_manage', 'M07.project_activate', 'M07.project_close', 'M07.document_view', 'M07.document_manage', 'M07.document_approve', 'M07.financial_view', 'M07.material_manage']],
        [$poster, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.reservation_manage', 'M06.reservation_view', 'M11.tax_calculate', 'M11.receipt_post', 'M11.source_post', 'M11.journal_post', 'M11.journal_approve', 'M11.report_view', 'M11.journal_view', 'M12.supplier_invoice_post', 'M12.customer_invoice_post', 'M12.ap_view', 'M12.ar_view', 'M12.allocation_manage', 'M12.credit_note_approve', 'M13.payment_approve', 'M13.payment_execute', 'M13.collection_execute', 'M13.transfer_execute', 'M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_delivery_confirm', 'M07.project_view', 'M07.project_close', 'M07.budget_approve', 'M07.document_view', 'M07.document_approve', 'M07.material_issue']]] as [$user, $codes]) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $grant($user, $codes, (int) $branch->id);
    }
    $grant($maker, ['M07.export'], (int) $branch->id);
    // Company period closure requires actual report authority for every branch.
    foreach (Branch::query()->get() as $companyBranch) {
        DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $maker->id, 'branch_id' => $companyBranch->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['M01.branch_view', 'M11.report_view'] as $permission) {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $maker->id, 'permission_code' => $permission, 'branch_id' => $companyBranch->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
    DB::table('user_branch_scopes')->insert(['user_id' => $denied->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($denied, ['M05.receipt_view'], (int) $branch->id);
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $maker->id, [
        'code' => 'PH1-SI-WH-'.strtoupper(Str::random(5)), 'name' => 'Synthetic invoice acceptance warehouse', 'branch_id' => $branch->id, 'active' => true,
    ]);
    foreach ([$maker, $poster] as $user) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $projectEnd = date('Y-m-d', strtotime($date.' + 30 days'));
    $project = app(ProjectActions::class)->save((int) $maker->id, null, 0, $uuid(), [
        'code' => 'PH2-'.strtoupper(Str::random(8)), 'name' => 'Synthetic project financial integration', 'branch_id' => (int) $branch->id,
        'customer_id' => (int) $customer->id, 'manager_id' => (int) $maker->id, 'members' => [], 'start_date' => $date, 'end_date' => $projectEnd,
        'description' => 'Isolated owner API integration acceptance fixture', 'notes' => '',
    ]);
    app(ProjectActions::class)->phase((int) $maker->id, $project->id, $project->version, ['title' => 'Executed delivery scope', 'start_date' => $date, 'end_date' => $projectEnd]);
    $project->refresh();
    $projectPhase = ProjectPhase::query()->where('project_id', $project->id)->firstOrFail();
    app(ProjectActions::class)->task((int) $maker->id, $project->id, $project->version, [
        'phase_id' => $projectPhase->id, 'title' => 'Complete the installed works handover', 'assigned_to' => (int) $maker->id,
        'start_date' => $date, 'end_date' => $projectEnd, 'notes' => 'Required synthetic project execution task.', 'priority' => 'high', 'required' => true,
    ]);
    $project->refresh();
    $taskId = (int) DB::table('project_tasks')->where('project_id', $project->id)->value('id');
    $milestoneId = app(ProjectExecution::class)->milestone((int) $maker->id, $project->id, $project->version, [
        'title' => 'Owner site acceptance', 'target_date' => $projectEnd, 'responsible_id' => (int) $maker->id, 'required' => true,
    ]);
    $project->refresh();
    $planning = app(ProjectPlanning::class);
    $planning->propose((int) $maker->id, $project->id, $project->version, $uuid(), 'Initial controlled synthetic project baseline', [
        'scope' => 'Synthetic scope acceptance', 'currency' => 'EGP', 'total' => '100.00',
        'categories' => [['name' => 'Electrical materials', 'amount' => '100.00']], 'phases' => [],
        'structure' => [['id' => $projectPhase->id, 'title' => $projectPhase->title, 'start_date' => $date, 'end_date' => $projectEnd, 'cancelled' => false]],
    ]);
    $project->refresh();
    $changeId = (int) DB::table('project_changes')->where('project_id', $project->id)->value('id');
    $expect(fn () => $planning->decide((int) $maker->id, $project->id, $project->version, $changeId, true, 'Self approval must be rejected.'), 'project manager self-approval', 'forbidden');
    $planning->decide((int) $poster->id, $project->id, $project->version, $changeId, true, 'Independent budget approval for isolated acceptance.');
    $project->refresh();
    $planning->transition((int) $maker->id, $project->id, $project->version, 'planned', 'Approved independent baseline.');
    $project->refresh();
    $planning->transition((int) $maker->id, $project->id, $project->version, 'active', 'Synthetic project work starts.');
    $project->refresh();
    $expect(fn () => $planning->transition((int) $maker->id, $project->id, $project->version, 'completed', 'Premature completion is blocked by required project evidence.'),
        'project cannot complete with required task and milestone pending');
    $need(Project::query()->findOrFail($project->id)->status === 'active', 'Rejected completion preserves active project state.');
    $need(DB::table('project_baselines')->where('project_id', $project->id)->where('revision', 1)->where('approved_by', $poster->id)->exists()
        && Project::query()->findOrFail($project->id)->status === 'active', 'Independent approved project baseline gates planned and active lifecycle.');

    $makeOrderReceipts = static function (string $quantity, string $label) use ($maker, $poster, $supplier, $product, $branch, $warehouse, $date): array {
        $orders = app(PurchaseOrders::class);
        $receiving = app(PurchaseReceiving::class);
        $order = $orders->save((int) $maker->id, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch->id,
            'supplier_id' => $supplier->id, 'order_date' => $date, 'expected_date' => null, 'currency' => 'EGP',
            'payment_terms' => 'Synthetic Phase 1 acceptance', 'notes' => $label, 'tax_mode' => 'exclusive', 'reported_tax' => null,
            'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '10.00000000', 'discount_amount' => '0.00']]], null, 0);
        $orders->transition((int) $maker->id, $order, 1, 'submit');
        $orders->transition((int) $poster->id, $order, 2, 'approve');
        $orders->issue((int) $poster->id, $order);
        $orderLine = (int) DB::table('purchasing_order_lines')->where('order_id', $order)->value('id');
        $receiptIds = [];
        $remaining = $quantity;
        $split = [];
        if ($quantity === '4.00000000') {
            $split = ['1.00000000', '1.00000000', '2.00000000'];
        } else {
            $split = [$quantity];
        }
        foreach ($split as $index => $part) {
            $receipt = $receiving->save((int) $maker->id, ['creation_key' => (string) Str::uuid(), 'order_id' => $order,
                'warehouse_id' => $warehouse['id'], 'receipt_date' => $date, 'delivery_reference' => $label.'-R'.($index + 1),
                'notes' => 'Synthetic posted receipt for supplier invoice acceptance',
                'lines' => [['order_line_id' => $orderLine, 'quantity' => $part]]], null, 0);
            $receiving->submit((int) $maker->id, $receipt, 1);
            app(PostPurchaseReceipt::class)->execute((int) $poster->id, $receipt, 2);
            $receiptIds[] = ['id' => $receipt, 'line_id' => (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $receipt)->value('id'), 'quantity' => $part];
            $remaining = bcsub($remaining, $part, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new RuntimeException('Internal receipt fixture split did not exhaust quantity.');
        }

        return ['order_id' => $order, 'order_line_id' => $orderLine, 'receipts' => $receiptIds];
    };
    $makeInvoice = static function (int $actor, string $number, string $invoiceDate, int $supplierId, int $orderLine, array $matches, string $quantity = '1.00000000'): array {
        $data = ['creation_key' => (string) Str::uuid(), 'supplier_id' => $supplierId, 'supplier_invoice_number' => $number,
            'invoice_date' => $invoiceDate, 'due_date' => date('Y-m-d', strtotime($invoiceDate.' + 30 days')), 'tax_mode' => 'exclusive',
            'lines' => [['order_line_id' => $orderLine, 'quantity' => $quantity, 'unit_price' => '11.00000000', 'discount_amount' => '0.00', 'matches' => $matches]]];
        $api = app(ReceivablesPayablesApi::class);
        $id = $api->createSupplierInvoice($actor, $data);

        return [$id, $data, $api];
    };
    $postLifecycle = static function (int $actor, int $id, ReceivablesPayablesApi $api): array {
        $api->validateSupplierInvoice($actor, $id, 1);
        $api->matchSupplierInvoice($actor, $id, 2);
        $api->approveSupplierInvoice($actor, $id, 3);

        return $api->postSupplierInvoice($actor, $id, 4);
    };

    // Four received units arrive in three actual posted receipt documents.
    $main = $makeOrderReceipts('4.00000000', 'MAIN');
    app(ProjectSources::class)->link((int) $maker->id, $project->id, 'purchase_order', (int) $main['order_id']);
    app(ProjectSources::class)->link((int) $maker->id, $project->id, 'purchase_order', (int) $main['order_id']);
    $need(DB::table('project_source_links')->where('project_id', $project->id)->where('source_type', 'purchase_order')->where('source_id', $main['order_id'])->count() === 1,
        'Same-owner PO link replay remains idempotent.');
    $projectStock = $makeOrderReceipts('1.00000000', 'PROJECT-MATERIAL');
    app(ProjectSources::class)->link((int) $maker->id, $project->id, 'purchase_order', (int) $projectStock['order_id']);
    app(ProjectSources::class)->requirement((int) $maker->id, $project->id, ['product_id' => (int) $product->id, 'quantity' => '1.00000000', 'notes' => 'Synthetic installed material demand.']);
    $requirementId = (int) DB::table('project_requirements')->where('project_id', $project->id)->value('id');
    $expect(fn () => app(ProjectMaterials::class)->reserve((int) $poster->id, $project->id, $requirementId, (int) $warehouse['id'], '1.00000000', $uuid()),
        'material issue permission cannot create a reservation', 'forbidden');
    $reservation = app(ProjectMaterials::class)->reserve((int) $maker->id, $project->id, $requirementId, (int) $warehouse['id'], '1.00000000', $uuid());
    $stockBeforeInvoice = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $fifoBeforeInvoice = DB::table('inventory_fifo_layers')->whereIn('movement_id', DB::table('purchasing_receipt_postings')->whereIn('receipt_id', array_column($main['receipts'], 'id'))->select('movement_id'))->count();
    $receipts = $main['receipts'];
    $invoiceAData = ['creation_key' => $uuid(), 'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'PH1-MULTI-A-'.strtoupper(Str::random(5)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $main['order_line_id'], 'quantity' => '1.50000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipts[0]['line_id'], 'quantity' => '1.00000000'], ['receipt_line_id' => $receipts[1]['line_id'], 'quantity' => '0.50000000']]]]];
    $api = app(ReceivablesPayablesApi::class);
    $invoiceA = $api->createSupplierInvoice((int) $maker->id, $invoiceAData);
    $invoiceAReplay = $api->createSupplierInvoice((int) $maker->id, $invoiceAData);
    $need($invoiceAReplay === $invoiceA, 'Same-key supplier invoice create replay did not return the original draft.');
    $api->validateSupplierInvoice((int) $maker->id, $invoiceA, 1);
    $api->matchSupplierInvoice((int) $maker->id, $invoiceA, 2);
    // ACC-11 explicitly permits this same authorized accountant to approve and post an invoice they created.
    $api->approveSupplierInvoice((int) $maker->id, $invoiceA, 3);
    $postA = $api->postSupplierInvoice((int) $maker->id, $invoiceA, 4);
    $invoiceBData = ['creation_key' => $uuid(), 'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'PH1-MULTI-B-'.strtoupper(Str::random(5)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $main['order_line_id'], 'quantity' => '1.50000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipts[1]['line_id'], 'quantity' => '0.50000000'], ['receipt_line_id' => $receipts[2]['line_id'], 'quantity' => '1.00000000']]]]];
    $invoiceB = $api->createSupplierInvoice((int) $maker->id, $invoiceBData);
    $postB = $postLifecycle((int) $maker->id, $invoiceB, $api);
    $invoiceC = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-MULTI-C-'.strtoupper(Str::random(5)), 'invoice_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $main['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipts[2]['line_id'], 'quantity' => '1.00000000']]]]]);
    $postC = $postLifecycle((int) $maker->id, $invoiceC, $api);

    $invoices = DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->orderBy('id')->get();
    $need($invoices->count() === 3 && $invoices->every(fn ($row) => $row->status === 'posted'), 'Three supplier invoice lifecycle records did not reach posted.');
    $pdfEvidence = [];
    foreach ($invoices as $row) {
        $pdf = app(OfficialDocuments::class)->retrieve((string) $row->official_document_id);
        $need(str_starts_with($pdf['bytes'], '%PDF-') && hash_equals($pdf['sha256'], hash('sha256', $pdf['bytes'])), 'Supplier invoice official PDF bytes/hash do not reconcile.');
        $pdfEvidence[] = ['invoice_id' => $row->id, 'official_number' => $row->official_number, 'sha256' => $pdf['sha256'], 'bytes' => strlen($pdf['bytes'])];
    }
    $tax15 = FinancialDecimal::round(bcdiv(bcmul('16.50000000000000000000000000000000', (string) $purchaseTax->rate, 32), '100', 32));
    $tax10 = FinancialDecimal::round(bcdiv(bcmul('11.00000000000000000000000000000000', (string) $purchaseTax->rate, 32), '100', 32));
    $expectedTax = bcadd(bcadd($tax15, $tax15, 2), $tax10, 2);
    $postedNet = (string) DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->selectRaw('coalesce(sum(net_amount),0)::text as total')->value('total');
    $postedTax = (string) DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->selectRaw('coalesce(sum(tax_amount),0)::text as total')->value('total');
    $postedGross = (string) DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->selectRaw('coalesce(sum(total_amount),0)::text as total')->value('total');
    $need($postedNet === '44.00' && $postedTax === $expectedTax && $postedGross === bcadd('44.00', $expectedTax, 2), 'Invoice net/tax/gross totals do not reconcile to 4 units at EGP 11 and the configured tax rate.');
    $matches = DB::table('rp_supplier_invoice_matches as m')->join('rp_supplier_invoice_lines as l', 'l.id', '=', 'm.invoice_line_id')->whereIn('l.invoice_id', [$invoiceA, $invoiceB, $invoiceC]);
    $matchTotals = (clone $matches)->selectRaw('coalesce(sum(m.quantity),0)::text as quantity, coalesce(sum(m.grni_amount),0)::text as grni')->first();
    $matchQuantity = (string) $matchTotals->quantity;
    $matchGrni = (string) $matchTotals->grni;
    $need(bccomp($matchQuantity, '4.00000000', 8) === 0 && $matchGrni === '40.00', 'Supplier invoice matching did not allocate exactly four received units and EGP 40.00 GRNI.');
    $invoiceValues = DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])
        ->selectRaw('coalesce(sum(grni_amount),0)::text as grni, coalesce(sum(ppv_amount),0)::text as ppv')->first();
    $need($invoiceValues->grni === '40.00' && $invoiceValues->ppv === '4.00', 'GRNI/PPV reconciliation failed for higher supplier invoice price.');
    $apOriginal = (string) DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->whereIn('source_id', [$invoiceA, $invoiceB, $invoiceC])
        ->selectRaw('coalesce(sum(original_amount),0)::text as total')->value('total');
    $need($apOriginal === $postedGross, 'Posted AP original balance does not equal supplier invoice total.');

    $journalTotals = [];
    foreach ([$invoiceA, $invoiceB, $invoiceC] as $id) {
        $journal = DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$id)->first();
        $need($journal && $journal->status === 'posted', 'Supplier invoice source journal missing/not posted.');
        $rows = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->get();
        $dr = '0.00';
        $cr = '0.00';
        $byAccount = [];
        foreach ($rows as $row) {
            $dr = bcadd($dr, $row->debit, 2);
            $cr = bcadd($cr, $row->credit, 2);
            $byAccount[$row->account_id] = bcadd($byAccount[$row->account_id] ?? '0.00', bcsub($row->debit, $row->credit, 2), 2);
        }
        $need($dr === $cr, 'Supplier invoice source journal does not balance exactly.');
        $journalTotals[] = ['invoice_id' => $id, 'journal_id' => $journal->id, 'debit' => $dr, 'credit' => $cr,
            'grni_net' => $byAccount[$mappings['grni']] ?? '0.00', 'ppv_net' => $byAccount[$mappings['ppv']] ?? '0.00',
            'tax_input_net' => $byAccount[$mappings['tax_input']] ?? '0.00', 'ap_net' => $byAccount[$mappings['ap']] ?? '0.00'];
    }
    $allGrni = $allPpv = $allTax = $allAp = '0.00';
    foreach ($journalTotals as $row) {
        $allGrni = bcadd($allGrni, $row['grni_net'], 2);
        $allPpv = bcadd($allPpv, $row['ppv_net'], 2);
        $allTax = bcadd($allTax, $row['tax_input_net'], 2);
        $allAp = bcadd($allAp, $row['ap_net'], 2);
    }
    $need($allGrni === '40.00' && $allPpv === '4.00' && $allTax === $expectedTax && $allAp === bcsub('0.00', $postedGross, 2), 'GL purpose totals do not reconcile to GRNI, PPV, recoverable input tax, and AP.');
    $stockAfterInvoice = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $fifoAfterInvoice = DB::table('inventory_fifo_layers')->whereIn('movement_id', DB::table('purchasing_receipt_postings')->whereIn('receipt_id', array_column($receipts, 'id'))->select('movement_id'))->count();
    $need($stockAfterInvoice === $stockBeforeInvoice && $fifoAfterInvoice === $fifoBeforeInvoice, 'Supplier invoice posting changed stock quantity or receipt FIFO layers.');
    $need($postA['journal_id'] && $postB['journal_id'] && $postC['journal_id'], 'Posted invoice API responses omitted journal identities.');

    // Actual delivered Sales source -> issued invoice -> partial/final customer collection.
    $unit = (int) $product->base_unit_id;
    $salePaymentTerms = 'PHASE1-PAY-'.strtoupper(Str::random(8));
    $saleDeliveryTerms = 'PHASE1-DEL-'.strtoupper(Str::random(8));
    app(QuotationCommercialPolicy::class)->term((int) $maker->id, ['code' => $salePaymentTerms, 'kind' => 'payment', 'name' => 'Synthetic Phase 1 payment terms', 'body' => $salePaymentTerms]);
    app(QuotationCommercialPolicy::class)->term((int) $maker->id, ['code' => $saleDeliveryTerms, 'kind' => 'delivery', 'name' => 'Synthetic Phase 1 delivery terms', 'body' => $saleDeliveryTerms]);
    $priceId = app(SalePrices::class)->save((int) $maker->id, (int) $product->id, ['amount' => '12.00000000', 'unit_id' => $unit,
        'starts_on' => $date, 'ends_on' => null, 'reference' => 'Synthetic Phase 1 customer invoice approved price']);
    app(SalePrices::class)->decide((int) $poster->id, $priceId, 'approve');
    $saleOrder = app(SalesOrderActions::class)->save((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'order_date' => $date, 'requested_delivery_date' => null, 'discount_mode' => 'percent',
        'discount_value' => '0', 'payment_terms' => $salePaymentTerms, 'delivery_terms' => $saleDeliveryTerms,
        'notes' => 'Synthetic Phase 1 AR source', 'lines' => [['product_id' => $product->id, 'unit_id' => $unit,
            'quantity' => '4.00000000', 'requested_price' => null, 'description' => 'Synthetic delivered test product',
            'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null]]]);
    app(ProjectSources::class)->link((int) $maker->id, $project->id, 'sales_order', (int) $saleOrder->id);
    $browserSalesOrder = app(SalesOrderActions::class)->save((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'order_date' => $date, 'requested_delivery_date' => null, 'discount_mode' => 'percent',
        'discount_value' => '0', 'payment_terms' => $salePaymentTerms, 'delivery_terms' => $saleDeliveryTerms,
        'notes' => 'Synthetic unlinked draft source for isolated browser acceptance', 'lines' => [['product_id' => $product->id, 'unit_id' => $unit,
            'quantity' => '1.00000000', 'requested_price' => null, 'description' => 'Synthetic unlinked browser source draft',
            'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null]]]);
    app(SalesOrderActions::class)->submit((int) $maker->id, (int) $saleOrder->id, 1);
    $saleLineId = (int) $saleOrder->lines->first()->id;
    app(ApproveSalesOrder::class)->execute((int) $poster->id, (int) $saleOrder->id, 2, [$saleLineId => (int) $warehouse['id']]);
    $delivery = app(SalesDeliveries::class)->create((int) $maker->id, (int) $saleOrder->id,
        ['creation_key' => $uuid(), 'reference' => 'PH1 fully delivered sales source', 'lines' => [['line_id' => $saleLineId, 'quantity' => '4.00000000']]]);
    app(SalesDeliveries::class)->confirm((int) $poster->id, $delivery);
    $projectIssueKey = $uuid();
    $projectIssue = app(ProjectMaterials::class)->issue((int) $poster->id, $project->id, (int) $reservation['id'], '1.00000000', $projectIssueKey);
    $need((string) DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$projectIssueKey)->value('source_module') === 'Projects',
        'Project material issue posts an owner-authored inventory movement.');
    $deliveryLineId = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $delivery)->value('id');
    $customerInvoice = $api->createCustomerInvoice((int) $maker->id, ['creation_key' => $uuid(), 'customer_id' => $customer->id,
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')),
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '4.00000000']]]);
    $api->validateCustomerInvoice((int) $maker->id, $customerInvoice, 1);
    $customerPost = $api->postCustomerInvoice((int) $maker->id, $customerInvoice, 2);
    $customerInvoiceRow = DB::table('rp_customer_invoices')->find($customerInvoice);
    $arOpen = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $customerInvoice)->first();
    $need($customerInvoiceRow->status === 'posted' && $arOpen && $arOpen->original_amount === $customerInvoiceRow->total_amount,
        'Delivered source customer invoice did not create its exact AR open item.');
    $collectionPart = bcdiv($customerInvoiceRow->total_amount, '2', 2);
    $collectionRemaining = bcsub($customerInvoiceRow->total_amount, $collectionPart, 2);
    $collectionOneKey = $uuid();
    $collectionData = ['idempotency_key' => $collectionOneKey, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'account_id' => $cashAccount, 'date' => $date, 'amount' => $collectionPart, 'reference' => 'PH1 AR partial'];
    $collectionOne = $treasury->executeCustomerCollection((int) $maker->id, $collectionData,
        [['open_item_id' => $arOpen->id, 'amount' => $collectionPart]]);
    $collectionReplay = $treasury->executeCustomerCollection((int) $maker->id, $collectionData,
        [['open_item_id' => $arOpen->id, 'amount' => $collectionPart]]);
    $need($collectionOne['movement_id'] === $collectionReplay['movement_id'], 'Same collection idempotency key created a duplicate receipt movement.');
    $collectionTwo = $treasury->executeCustomerCollection((int) $maker->id, ['idempotency_key' => $uuid(), 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'account_id' => $cashAccount, 'date' => $date, 'amount' => $collectionRemaining, 'reference' => 'PH1 AR final'],
        [['open_item_id' => $arOpen->id, 'amount' => $collectionRemaining]]);
    $arRemaining = (string) DB::table('rp_open_items')->where('id', $arOpen->id)->value('remaining_amount');
    $need($arRemaining === '0.00', 'Partial/final customer collections did not settle AR to zero.');

    // AP payouts use real cash accumulated from the posted customer collections above.
    $apItems = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->whereIn('source_id', [$invoiceA, $invoiceB, $invoiceC])->orderBy('id')->get();
    $apByInvoice = $apItems->keyBy('source_id');
    $paymentOne = $treasury->prepareSupplierPayment((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id,
        'supplier_id' => $supplier->id, 'account_id' => $cashAccount, 'request_date' => $date, 'amount' => '10.00', 'reference' => 'PH1 AP partial']);
    $treasury->approveSupplierPayment((int) $poster->id, $paymentOne, 1);
    $payOneKey = $uuid();
    $payOne = $treasury->executeSupplierPayment((int) $maker->id, $paymentOne, 2, $payOneKey,
        [['open_item_id' => $apByInvoice[$invoiceA]->id, 'amount' => '10.00']]);
    $payOneReplay = $treasury->executeSupplierPayment((int) $maker->id, $paymentOne, 2, $payOneKey,
        [['open_item_id' => $apByInvoice[$invoiceA]->id, 'amount' => '10.00']]);
    $need($payOne['movement_id'] === $payOneReplay['movement_id'], 'Same supplier payment retry did not return its one actual movement.');
    $remainingA = bcsub((string) $apByInvoice[$invoiceA]->original_amount, '10.00', 2);
    $remainingB = (string) $apByInvoice[$invoiceB]->original_amount;
    $remainingC = (string) $apByInvoice[$invoiceC]->original_amount;
    $finalPayAmount = bcadd(bcadd($remainingA, $remainingB, 2), $remainingC, 2);
    $paymentTwo = $treasury->prepareSupplierPayment((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id,
        'supplier_id' => $supplier->id, 'account_id' => $cashAccount, 'request_date' => $date, 'amount' => $finalPayAmount, 'reference' => 'PH1 AP final']);
    $treasury->approveSupplierPayment((int) $poster->id, $paymentTwo, 1);
    $payTwo = $treasury->executeSupplierPayment((int) $maker->id, $paymentTwo, 2, $uuid(), [
        ['open_item_id' => $apByInvoice[$invoiceA]->id, 'amount' => $remainingA],
        ['open_item_id' => $apByInvoice[$invoiceB]->id, 'amount' => $remainingB],
        ['open_item_id' => $apByInvoice[$invoiceC]->id, 'amount' => $remainingC],
    ]);
    $apRemaining = (string) DB::table('rp_open_items')->whereIn('id', $apItems->pluck('id'))->selectRaw('coalesce(sum(remaining_amount),0)::text total')->value('total');
    $need($apRemaining === '0.00' && DB::table('treasury_movements')->whereIn('id', [$payOne['movement_id'], $payTwo['movement_id']])->count() === 2,
        'Partial/final supplier payments did not settle the complete AP balance exactly once.');

    // Independent credit approval after settlement preserves the party credit instead of rewriting invoices.
    $supplierCredit = $api->createCreditNote((int) $maker->id, ['creation_key' => $uuid(), 'kind' => 'supplier', 'invoice_id' => $invoiceA,
        'note_date' => $date, 'reason' => 'Synthetic post-settlement supplier rebate', 'amount' => '2.00']);
    $customerCredit = $api->createCreditNote((int) $maker->id, ['creation_key' => $uuid(), 'kind' => 'customer', 'invoice_id' => $customerInvoice,
        'note_date' => $date, 'reason' => 'Synthetic post-settlement customer rebate', 'amount' => '2.00']);
    $api->approveCreditNote((int) $poster->id, $supplierCredit);
    $supplierCreditPost = $api->postCreditNote((int) $poster->id, $supplierCredit);
    $api->approveCreditNote((int) $poster->id, $customerCredit);
    $customerCreditPost = $api->postCreditNote((int) $poster->id, $customerCredit);
    $partyCredits = DB::table('rp_party_credits')->whereIn('credit_note_id', [$supplierCredit, $customerCredit])->get();
    $need($partyCredits->count() === 2 && $partyCredits->every(fn ($credit) => $credit->original_amount === '2.00'),
        'Post-settlement supplier/customer credits were not retained as party credit balances.');

    // Direct bank/cash transfer is a paired Treasury movement, with no income or expense accounts.
    $transfer = $treasury->transfer((int) $maker->id, ['idempotency_key' => $uuid(), 'branch_id' => $branch->id,
        'from_account_id' => $cashAccount, 'to_account_id' => $bankAccount, 'date' => $date, 'amount' => '1.00', 'reference' => 'PH1 direct cash transfer']);
    $transferLines = DB::table('accounting_journal_lines')->where('journal_id', $transfer['journal_id'])->get();
    $transferAccountIds = $transferLines->pluck('account_id')->all();
    $transferAccountIds = array_map('intval', $transferAccountIds);
    $need(count($transferLines) === 2 && ! in_array((int) $mappings['sales'], $transferAccountIds, true)
        && ! in_array((int) $mappings['ppv'], $transferAccountIds, true), 'Direct Treasury transfer was posted as revenue/expense instead of balance-sheet movement.');

    $manualDebitAccount = $accountActions->account((int) $maker->id, null, 0, ['code' => 'PH2-MANUAL-D-'.strtoupper(Str::random(5)), 'name' => 'Synthetic project manual debit', 'kind' => 'asset', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $manualCreditAccount = $accountActions->account((int) $maker->id, null, 0, ['code' => 'PH2-MANUAL-C-'.strtoupper(Str::random(5)), 'name' => 'Synthetic project manual credit', 'kind' => 'asset', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $projectJournal = app(AccountingActions::class)->journal((int) $maker->id, null, 0, $uuid(), [
        'project_id' => $project->id, 'branch_id' => $branch->id, 'journal_date' => $date,
        'description' => 'Synthetic balanced project dimension persistence check',
        'lines' => [['account_id' => $manualDebitAccount, 'debit' => '1.00', 'credit' => '0.00'],
            ['account_id' => $manualCreditAccount, 'debit' => '0.00', 'credit' => '1.00']],
    ]);
    $need((int) DB::table('accounting_project_dimensions')->where('journal_id', $projectJournal)->value('project_id') === (int) $project->id,
        'Accounting owner stores the selected active-project dimension at draft creation.');
    try {
        DB::transaction(fn () => DB::table('accounting_project_dimensions')->where('journal_id', $projectJournal)->update(['project_id' => $project->id]));
        throw new RuntimeException('Accounting project dimension was mutable after journal creation.');
    } catch (QueryException) {
        $need((int) DB::table('accounting_project_dimensions')->where('journal_id', $projectJournal)->value('project_id') === (int) $project->id,
            'Accounting project dimension is immutable at the PostgreSQL boundary.');
    }
    app(AccountingActions::class)->advance((int) $maker->id, $projectJournal, 1, 'submit');
    app(AccountingActions::class)->advance((int) $poster->id, $projectJournal, 2, 'approve');
    app(AccountingActions::class)->advance((int) $poster->id, $projectJournal, 3, 'post');
    $need(DB::table('accounting_journals')->where('id', $projectJournal)->where('status', 'posted')->exists(),
        'Independently approved project-dimensioned manual journal posts without changing project P&L totals.');

    $reports = app(FinancialReports::class);
    $trial = $reports->trialBalance((int) $maker->id, (int) $branch->id, $date, $date);
    $income = $reports->statement((int) $maker->id, 'income', (int) $branch->id, $date, $date);
    $balance = $reports->statement((int) $maker->id, 'balance', (int) $branch->id, $date, $date);
    $reconciliation = $reports->reconciliations((int) $maker->id, (int) $branch->id, $date);
    $need($trial['debit'] === $trial['credit'], 'Trial Balance debit and credit totals are unequal.');
    $need(count($income['rows']) > 0 && count($balance['rows']) > 0 && count($reconciliation) >= 7
        && collect($reconciliation)->contains('key', 'inventory_transit'),
        'Financial report APIs omitted posted-source income/balance or inventory-transit reconciliation rows.');
    $need(collect($reconciliation)->every(fn ($row) => $row['ok']), 'Control reconciliation failed: '.json_encode($reconciliation));

    app(ProjectActions::class)->taskStatus((int) $maker->id, $project->id, $taskId, 1, 'completed');
    app(ProjectExecution::class)->milestoneStatus((int) $maker->id, $project->id, $milestoneId, 1, 'completed', 'Owner site inspection and acceptance evidence recorded.');
    $project->refresh();
    $need(app(ProjectAccess::class)->progress($project)['percent'] === '100.00'
        && app(ProjectExecution::class)->completionChecks($project)['milestones'], 'Required task and milestone evidence completes project execution.');
    $expect(fn () => $planning->transition((int) $maker->id, $project->id, $project->version, 'completed', 'Handover record is still required.'),
        'project cannot complete before handover is recorded');
    $need(Project::query()->findOrFail($project->id)->status === 'active', 'Rejected completion before handover preserves active status.');
    $project->refresh();
    app(ProjectExecution::class)->handover((int) $maker->id, $project->id, $project->version, 'Synthetic customer handover evidence and signed acceptance reference PH2-HANDOVER.');
    $handoverId = (int) DB::table('project_handovers')->where('project_id', $project->id)->value('id');
    try {
        DB::transaction(fn () => DB::table('project_handovers')->where('id', $handoverId)->update(['evidence' => 'tampered']));
        throw new RuntimeException('Project handover evidence was mutable.');
    } catch (QueryException) {
        $need(DB::table('project_handovers')->where('id', $handoverId)->value('evidence') === 'Synthetic customer handover evidence and signed acceptance reference PH2-HANDOVER.',
            'Handover evidence is immutable at the PostgreSQL boundary.');
    }
    $project->refresh();
    $planning->transition((int) $maker->id, $project->id, $project->version, 'completed', 'Required tasks, milestone and handover are evidenced.');
    $project->refresh();
    $expect(fn () => $planning->transition((int) $maker->id, $project->id, $project->version, 'closed', 'Outstanding received value is still unmatched to invoices.'),
        'project closure is blocked while GRNI remains uncleared');
    $need(Project::query()->findOrFail($project->id)->status === 'completed', 'Rejected closure preserves completed project status.');

    $projectStockInvoice = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH2-MATERIAL-'.strtoupper(Str::random(5)), 'invoice_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $projectStock['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '10.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $projectStock['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]);
    $projectStockPost = $postLifecycle((int) $maker->id, $projectStockInvoice, $api);
    $need($projectStockPost['journal_id'] && DB::table('rp_supplier_invoices')->where('id', $projectStockInvoice)->whereNotNull('project_id')->exists(),
        'Project-linked supplementary receipt invoice posts with project attribution and GRNI settlement.');

    $projectSummary = app(ProjectFinancials::class)->summary((int) $maker->id, $project->id);
    $need($projectSummary['subledger']['grni_cleared'] === $projectSummary['purchasing']['received_value']
        && ! in_array(true, $projectSummary['closure_blockers'], true), 'All owner-sourced closure obligations clear after GRNI invoice matching and stock issue.');
    $project->refresh();
    $planning->transition((int) $maker->id, $project->id, $project->version, 'closed', 'Financial obligations reconciled; customer handover retained.');
    $need(Project::query()->findOrFail($project->id)->status === 'closed'
        && DB::table('project_handovers')->where('id', $handoverId)->exists(), 'Project closes only after real handover and reconciled financial/stock evidence.');
    $expect(fn () => app(AccountingActions::class)->journal((int) $maker->id, null, 0, $uuid(), [
        'project_id' => $project->id, 'branch_id' => $branch->id, 'journal_date' => $date, 'description' => 'Closed project dimension must be rejected.',
        'lines' => [['account_id' => $manualDebitAccount, 'debit' => '1.00', 'credit' => '0.00'],
            ['account_id' => $manualCreditAccount, 'debit' => '0.00', 'credit' => '1.00']],
    ]), 'new accounting project dimension is denied after closure', 'conflict');

    $period = DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
    $need($period !== null, 'Activated synthetic current period missing.');
    // Isolated one-unit PO/receipt sources provide valid material for negative cases.
    $negativeFixture = $makeOrderReceipts('1.00000000', 'NEG-A');
    $negativeLine = $negativeFixture['receipts'][0]['line_id'];
    $expect(fn () => $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $otherSupplier->id,
        'supplier_invoice_number' => 'PH1-MISMATCH', 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '1.00000000']]]]]), 'supplier identity mismatch');
    $expect(fn () => $api->createSupplierInvoice((int) $denied->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-UNAUTHORIZED', 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '1.00000000']]]]]), 'unauthorized actor', 'forbidden');
    $expect(fn () => $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-OVERMATCH', 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '2.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '2.00000000']]]]]), 'receipt overmatch');
    $draftData = ['creation_key' => $uuid(), 'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'PH1-DUP-'.strtoupper(Str::random(5)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '1.00000000']]]]];
    $draftId = $api->createSupplierInvoice((int) $maker->id, $draftData);
    $need($api->createSupplierInvoice((int) $maker->id, $draftData) === $draftId, 'Same-key draft replay changed invoice identity.');
    $duplicateFixture = $makeOrderReceipts('1.00000000', 'NEG-B');
    $historicalMarginEvidence = null;
    if (! $commitProjectFixture) {
        // Post a customer invoice while its Sales order has no project link, then link the order later.
        // Historical source evidence stays unallocated, so no recognized project margin may be shown.
        $historicalOrderId = (int) $browserSalesOrder->id;
        $historicalOrderLineId = (int) $browserSalesOrder->lines->first()->id;
        app(SalesOrderActions::class)->submit((int) $maker->id, $historicalOrderId, 1);
        app(ApproveSalesOrder::class)->execute((int) $poster->id, $historicalOrderId, 2, [$historicalOrderLineId => (int) $warehouse['id']]);
        $historicalDeliveryId = app(SalesDeliveries::class)->create((int) $maker->id, $historicalOrderId, [
            'creation_key' => $uuid(), 'reference' => 'Synthetic historical unallocated source delivery',
            'lines' => [['line_id' => $historicalOrderLineId, 'quantity' => '1.00000000']],
        ]);
        app(SalesDeliveries::class)->confirm((int) $poster->id, $historicalDeliveryId);
        $historicalDeliveryLineId = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $historicalDeliveryId)->value('id');
        $historicalInvoiceId = $api->createCustomerInvoice((int) $maker->id, ['creation_key' => $uuid(), 'customer_id' => $customer->id,
            'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')),
            'lines' => [['delivery_line_id' => $historicalDeliveryLineId, 'quantity' => '1.00000000']]]);
        $historicalInvoiceRow = DB::table('rp_customer_invoices')->find($historicalInvoiceId);
        $need($historicalInvoiceRow->project_id === null, 'Customer invoice posted before source linking remains historically unallocated.');
        $api->validateCustomerInvoice((int) $maker->id, $historicalInvoiceId, 1);
        $api->postCustomerInvoice((int) $maker->id, $historicalInvoiceId, 2);
        $historicalJournalId = (int) DB::table('accounting_journals')->where('source_key', 'customer-invoice:'.$historicalInvoiceId)->value('id');
        $historicalJournalLines = DB::table('accounting_journal_lines')->where('journal_id', $historicalJournalId)->orderBy('id')
            ->get(['account_id', 'debit', 'credit'])->map(fn ($line) => [(int) $line->account_id, (string) $line->debit, (string) $line->credit])->all();
        $historicalProjectDimensionCount = DB::table('accounting_project_dimensions')->where('journal_id', $historicalJournalId)->count();
        $need($historicalJournalId > 0 && $historicalJournalLines !== [], 'Historical invoice has a real posted source journal before project linkage.');
        $historicalProject = app(ProjectActions::class)->save((int) $maker->id, null, 0, $uuid(), [
            'code' => 'PH2-HIST-'.strtoupper(Str::random(8)), 'name' => 'Synthetic historical attribution review', 'branch_id' => (int) $branch->id,
            'customer_id' => (int) $customer->id, 'manager_id' => (int) $maker->id, 'members' => [], 'start_date' => $date, 'end_date' => $projectEnd,
            'description' => 'Synthetic acceptance of a posted invoice predating source linkage.',
        ]);
        app(ProjectSources::class)->link((int) $maker->id, $historicalProject->id, 'sales_order', $historicalOrderId);
        $historicalSummary = app(ProjectFinancials::class)->summary((int) $maker->id, $historicalProject->id);
        $historicalInvoiceRow = DB::table('rp_customer_invoices')->find($historicalInvoiceId);
        $historicalJournalAfter = DB::table('accounting_journals')->where('source_key', 'customer-invoice:'.$historicalInvoiceId)->first();
        $historicalJournalLinesAfter = DB::table('accounting_journal_lines')->where('journal_id', $historicalJournalId)->orderBy('id')
            ->get(['account_id', 'debit', 'credit'])->map(fn ($line) => [(int) $line->account_id, (string) $line->debit, (string) $line->credit])->all();
        $historicalProjectDimensionCountAfter = DB::table('accounting_project_dimensions')->where('journal_id', $historicalJournalId)->count();
        $need($historicalInvoiceRow->project_id === null && $historicalSummary['subledger']['unallocated_invoices'] === 1
            && $historicalSummary['recognized_margin'] === null && $historicalJournalAfter && $historicalJournalAfter->status === 'posted'
            && $historicalJournalLinesAfter === $historicalJournalLines && $historicalProjectDimensionCountAfter === $historicalProjectDimensionCount,
            'Linking a Sales source later preserves the historical posted invoice and GL lines without a project dimension or recognized margin.');
        Auth::login($maker);
        $historicalExport = app(ProjectFinancialExportController::class)($historicalProject->id, 'xlsx');
        $exportPath = $historicalExport->getFile()->getPathname();
        $exportBook = IOFactory::load($exportPath);
        try {
            $marginExportValue = (string) $exportBook->getSheetByName('Data')->getCell('B7')->getValue();
            $need(str_contains($marginExportValue, 'غير متاح') && ! is_numeric($marginExportValue),
                'Project financial XLSX exports unavailable margin for a historically unallocated invoice.');
        } finally {
            $exportBook->disconnectWorksheets();
            @unlink($exportPath);
            Auth::logout();
        }
        $historicalMarginEvidence = ['project_id' => (int) $historicalProject->id, 'sales_order_id' => $historicalOrderId,
            'invoice_id' => $historicalInvoiceId, 'invoice_project_id' => $historicalInvoiceRow->project_id,
            'unallocated_invoice_count' => $historicalSummary['subledger']['unallocated_invoices'],
            'recognized_margin' => $historicalSummary['recognized_margin'], 'fifo_gl_difference' => $historicalSummary['fifo_gl_difference'],
            'historical_journal_id' => $historicalJournalId, 'historical_journal_line_count' => count($historicalJournalLines),
            'journal_unchanged_after_link' => $historicalJournalLinesAfter === $historicalJournalLines
                && $historicalProjectDimensionCountAfter === $historicalProjectDimensionCount,
            'xlsx_margin_export' => $marginExportValue];
    }
    $expect(fn () => $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => $draftData['supplier_invoice_number'], 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $duplicateFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $duplicateFixture['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]), 'duplicate supplier invoice number', 'duplicate');
    $raceFixture = null;
    if ($commitRaceFixture) {
        // This explicit opt-in mode is only for the disposable isolated PostgreSQL worker race.
        // Leave one real, action-created approved invoice and its posted receipt visible to workers.
        $raceFixture = [];
        foreach (['DUP-A', 'NUMBER-B', 'NUMBER-C'] as $label) {
            $raceOrder = $makeOrderReceipts('1.00000000', 'RACE-'.$label);
            $raceInvoice = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
                'supplier_invoice_number' => 'PH1-RACE-'.strtoupper(Str::random(8)), 'invoice_date' => $date,
                'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
                'lines' => [['order_line_id' => $raceOrder['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
                    'matches' => [['receipt_line_id' => $raceOrder['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]);
            $api->validateSupplierInvoice((int) $maker->id, $raceInvoice, 1);
            $api->matchSupplierInvoice((int) $maker->id, $raceInvoice, 2);
            $api->approveSupplierInvoice((int) $maker->id, $raceInvoice, 3);
            $raceFixture[] = ['invoice_id' => $raceInvoice, 'actor_id' => (int) $maker->id, 'version' => 4,
                'receipt_id' => $raceOrder['receipts'][0]['id'], 'branch_id' => (int) $branch->id];
        }
    } elseif (! $commitProjectFixture) {
        $closedFixture = $makeOrderReceipts('1.00000000', 'NEG-CLOSED');
        $closedInvoice = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'PH1-CLOSED-'.strtoupper(Str::random(5)), 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
            'lines' => [['order_line_id' => $closedFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
                'matches' => [['receipt_line_id' => $closedFixture['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]);
        $api->validateSupplierInvoice((int) $maker->id, $closedInvoice, 1);
        $api->matchSupplierInvoice((int) $maker->id, $closedInvoice, 2);
        $api->approveSupplierInvoice((int) $maker->id, $closedInvoice, 3);
        $periodCloseError = null;
        try {
            $reports->closePeriod((int) $maker->id, (int) $period->id);
        } catch (ValidationException $error) {
            $periodCloseError = $error->getMessage();
        }
        $need($periodCloseError === null && DB::table('accounting_periods')->where('id', $period->id)->where('status', 'closed')->exists(),
            'Financial period did not close after successful reconciliations: '.(string) $periodCloseError);
        $expect(fn () => $api->postSupplierInvoice((int) $maker->id, $closedInvoice, 4), 'closed accounting period');
        $need(DB::table('rp_supplier_invoices')->where('id', $closedInvoice)->where('status', 'approved')->exists()
            && ! DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $closedInvoice)->exists()
            && ! DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$closedInvoice)->exists(), 'Closed-period rejection left partial AP/GL posting effects.');
    }

    $need($projectSummary['approved_budget'] === '100.00' && $projectSummary['remaining_budget'] === bcsub('100.00', $projectSummary['accounting']['recognized_cost'], 2),
        'Project financial projection reconciles remaining approved budget against owner-reported recognized cost.');
    $need($projectSummary['purchasing']['order_ids'] === [(int) $main['order_id'], (int) $projectStock['order_id']] && $projectSummary['purchasing']['received_value'] === '50.00'
        && $projectSummary['purchasing']['open_commitments'] === '0.00', 'Project projection reads actual linked PO and posted receipt commitments.');
    $need($projectSummary['subledger']['supplier_invoiced'] === bcadd($postedGross, '11.40', 2) && $projectSummary['subledger']['customer_invoiced'] === $customerInvoiceRow->total_amount
        && $projectSummary['subledger']['unallocated_invoices'] === 0, 'Project projection attributes posted supplier and customer invoices through linked source APIs.');
    $need($projectSummary['sales'][0]['id'] === (int) $saleOrder->id && $projectSummary['sales'][0]['delivery'] === 'fulfilled'
        && $projectSummary['accounting']['recognized_revenue'] === '46.25' && $projectSummary['accounting']['recognized_cost'] === '52.25'
        && $projectSummary['accounting']['material_cost'] === '50.00' && $projectSummary['fifo_gl_difference'] === '0.00',
        'Project projection reads linked delivery, customer/supplier credits and FIFO/GL cost from owner sources: '.json_encode(['sales' => $projectSummary['sales'], 'accounting' => $projectSummary['accounting'], 'fifo_gl_difference' => $projectSummary['fifo_gl_difference']], JSON_UNESCAPED_SLASHES));
    $need($projectSummary['estimated_profitability'] === null && $projectSummary['estimate_unavailable_reason'] !== '',
        'Estimate remains unavailable without an approved completion estimate.');

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $evidence = ['database' => $connection->db, 'postgres_port' => (int) $connection->port, 'financial_date' => $date,
        'synthetic' => true, 'source_order_id' => $main['order_id'], 'posted_receipts' => array_column($receipts, 'id'),
        'invoice_ids' => [$invoiceA, $invoiceB, $invoiceC], 'source_journals' => $journalTotals,
        'official_pdf_integrity' => $pdfEvidence,
        'matching' => ['invoice_count' => 3, 'receipt_count' => 3, 'quantity' => $matchQuantity, 'grni' => $matchGrni,
            'net' => '44.00', 'recoverable_input_tax' => $expectedTax, 'ppv' => '4.00', 'ap' => $postedGross],
        'stock_and_fifo_unchanged_by_invoice_posting' => true, 'same_key_replay' => true,
        'factory_persistence' => ['supplier_invoice_id' => $factorySupplierInvoice->id, 'supplier_draft' => true,
            'customer_invoice_id' => $factoryCustomerInvoice->id, 'customer_draft' => true,
            'treasury_accounts' => [$factoryBank->id, $factoryCash->id]],
        'ap' => ['payment_movement_ids' => [$payOne['movement_id'], $payTwo['movement_id']], 'retry_idempotent' => true, 'remaining' => $apRemaining],
        'customer' => ['sales_order_id' => $saleOrder->id, 'delivery_id' => $delivery, 'invoice_id' => $customerInvoice,
            'collection_movement_ids' => [$collectionOne['movement_id'], $collectionTwo['movement_id']], 'remaining_ar' => $arRemaining],
        'credit_notes' => ['supplier' => $supplierCreditPost, 'customer' => $customerCreditPost, 'party_credit_rows' => $partyCredits->count()],
        'direct_transfer' => $transfer, 'reports' => ['trial_balance_debit' => $trial['debit'], 'trial_balance_credit' => $trial['credit'],
            'income_row_count' => count($income['rows']), 'balance_row_count' => count($balance['rows']), 'reconciliations' => $reconciliation],
        'negative_cases' => ['duplicate_supplier_number' => 'rejected', 'supplier_mismatch' => 'rejected', 'overmatch' => 'rejected',
            'unauthorized' => '403', 'closed_period' => ($commitRaceFixture || $commitProjectFixture) ? 'deferred_for_committed_fixture' : 'rejected_without_partial_post'],
        'project_financial_projection' => ['budget' => $projectSummary['approved_budget'], 'remaining' => $projectSummary['remaining_budget'],
            'purchasing' => $projectSummary['purchasing'], 'subledger' => $projectSummary['subledger'], 'accounting' => $projectSummary['accounting'],
            'fifo_gl_difference' => $projectSummary['fifo_gl_difference']],
        'historical_margin_negative' => $historicalMarginEvidence,
        'project_fixture' => ['project_id' => (int) $project->id, 'manager_id' => (int) $maker->id, 'manager_email' => $maker->email,
            'reviewer_id' => (int) $poster->id, 'reviewer_email' => $poster->email, 'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id,
            'denied_actor_id' => (int) $denied->id, 'denied_actor_email' => $denied->email,
            'product_id' => (int) $product->id, 'warehouse_id' => (int) $warehouse['id'], 'purchase_order_ids' => [(int) $main['order_id'], (int) $projectStock['order_id']],
            'sales_order_id' => (int) $saleOrder->id, 'customer_invoice_id' => (int) $customerInvoice,
            'source_link_ids' => DB::table('project_source_links')->where('project_id', $project->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'reservation_id' => (int) $reservation['id'], 'project_issue_id' => (int) DB::table('project_material_issues')->where('issue_key', $projectIssueKey)->value('id'),
            'required_task_id' => $taskId, 'required_milestone_id' => $milestoneId, 'handover_id' => $handoverId, 'accounting_journal_id' => (int) $projectJournal,
            'unlinked_sales_order_id' => (int) $browserSalesOrder->id, 'unlinked_purchase_order_id' => (int) ($closedFixture['order_id'] ?? $duplicateFixture['order_id']),
            'linkable_purchase_order' => ['id' => (int) $duplicateFixture['order_id'], 'order_line_id' => (int) $duplicateFixture['order_line_id'],
                'receipt_line_id' => (int) $duplicateFixture['receipts'][0]['line_id']],
            'committed' => $commitProjectFixture],
        'committed_worker_fixtures' => null, 'all_pass' => true];
} catch (Throwable $error) {
    $evidence = ['database' => $connection->db, 'postgres_port' => (int) $connection->port, 'all_pass' => false,
        'error_class' => $error::class, 'error' => mb_substr($error->getMessage(), 0, 1500), 'trace' => array_slice($error->getTrace(), 0, 8)];
    throw $error;
} finally {
    if ($db->transactionLevel() > 0) {
        if ($commitProjectFixture && ($evidence['all_pass'] ?? false)) {
            DB::commit();
        } else {
            DB::rollBack();
        }
    }
    $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    $evidenceOverride = getenv('PHASE2_EVIDENCE_PATH');
    $allowedEvidenceRoot = str_replace('\\', '/', $root.'/docs/qa/evidence/phase2-projects-browser/');
    $evidencePath = is_string($evidenceOverride) && $evidenceOverride !== '' ? $evidenceOverride : $root.'/docs/qa/evidence/phase2-projects-financial.json';
    if (is_string($evidenceOverride) && $evidenceOverride !== '' && ! str_starts_with(str_replace('\\', '/', $evidencePath), $allowedEvidenceRoot)) {
        throw new RuntimeException('PHASE2_EVIDENCE_PATH must stay under docs/qa/evidence/phase2-projects-browser/.');
    }
    if (! is_dir(dirname($evidencePath))) {
        mkdir(dirname($evidencePath), 0770, true);
    }
    file_put_contents($evidencePath, $json, LOCK_EX);
    echo $json;
}

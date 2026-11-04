<?php

// Trade returns acceptance on real posted sources, against isolated PostgreSQL only. Always rolls back.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\FinancialReports;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\PurchaseReturns;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ReceivablesPayables\Domain\CustomerInvoice;
use App\Modules\ReceivablesPayables\Domain\SupplierInvoice;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesReturns;
use App\Modules\System\Application\DocumentNumbering;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Domain\TreasuryAccount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
$gate1History = getenv('GATE1_HISTORY_CHECK') === '1';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $gate1History ? $root.'/.tmp/master-remediation-20261101/phase1-history-storage'
    : $root.'/.tmp/phase1-supplier-invoice-storage';
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
$gate1Identity = null;
$gate1Chronology = [];
$gate1Passed = [];
$date = now('Africa/Cairo')->toDateString();
$historyBase = Carbon::parse($date, 'Africa/Cairo')->subDays(10)->toDateString();
if ($gate1History) {
    require_once __DIR__.'/Gate1FinancialHistory.php';
    $gate1Identity = Gate1FinancialHistory::assertOwnedDatabase($root);
    $date = $historyBase;
}
$setHistoryDay = static function (int $offset) use (&$date, $historyBase, $gate1History): string {
    if ($gate1History) {
        $date = Carbon::parse($historyBase, 'Africa/Cairo')->addDays($offset)->toDateString();
        Carbon::setTestNow(Carbon::parse($date.' 12:00:00', 'Africa/Cairo'));
    }

    return $date;
};

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
$commitRaceFixture = getenv('PHASE1_COMMIT_RACE_FIXTURES') === '1';
$gate1FinancialRisk = null;
$evidence = [];
$factoryFailures = [];
try {
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $poster = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($maker, ['M01.company_update', 'M02.product_view', 'M02.price_view', 'M02.price_manage', 'M03.supplier_view', 'M03.customer_view', 'M04.quotation_policy_manage',
        'M06.warehouse_create', 'M06.warehouse_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.account_manage', 'M11.period_open', 'M11.period_close',
        'M11.opening_manage', 'M11.opening_entry', 'M11.tax_calculate', 'M13.account_manage']);
    $grant($poster, ['M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M02.price_approve', 'M11.tax_calculate',
        'M11.opening_approve', 'M11.financial_activate']);
    $grant($maker, ['M03.supplier_view', 'M03.customer_view']);
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
    $serialProduct = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'serial', 'active' => true]);
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
    $advanceAccount = $accountActions->account((int) $maker->id, null, 0, ['code' => 'PH1-CUSTOMER-ADVANCE-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic customer advance liability', 'kind' => 'liability', 'parent_id' => null, 'postable' => true, 'active' => true]);
    $setup->saveOptionalMapping((int) $maker->id, 'customer_advance', $advanceAccount);
    foreach ([['sale', null], ['purchase', (int) $product->id], ['purchase', (int) $serialProduct->id]] as [$type, $taxProduct]) {
        $taxExists = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
            ->where('r.transaction_type', $type)->where('r.product_id', $taxProduct)->where('r.starts_on', '<=', $date)
            ->whereRaw('(coalesce(e.ends_on,r.ends_on) is null or coalesce(e.ends_on,r.ends_on)>=?::date)', [$date])
            ->whereRaw('coalesce(e.withdrawn,false)=false')->exists();
        if (! $taxExists) {
            $taxData = ['code' => 'PH1-'.strtoupper($type).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic Phase 1 '.$type.' tax',
                'transaction_type' => $type, 'product_id' => $taxProduct, 'rate' => '14', 'starts_on' => $type === 'purchase' ? $periodStart : $date, 'ends_on' => null,
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
    foreach ([$maker, $poster] as $user) {
        DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($maker, ['M11.opening_entry', 'M11.opening_view'], (int) $branch->id);
    $grant($poster, ['M11.opening_review', 'M11.opening_view'], (int) $branch->id);
    $equity = $accountActions->account((int) $maker->id, null, 0, ['code' => 'PH1-EQUITY-'.strtoupper(Str::random(5)), 'name' => 'Synthetic opening equity', 'kind' => 'equity', 'parent_id' => null, 'postable' => true, 'active' => true]);
    // The account that owns an opening balance must exist before go-live; later accounts cannot claim it.
    app(TreasuryApi::class)->saveOperationalAccount((int) $maker->id, ['code' => 'BANK-SECOND', 'name' => 'البنك الثاني', 'kind' => 'bank', 'gl_account_id' => $mappings['bank']]);
    $batch = $opening->save((int) $maker->id, $version, $uuid(), [
        ['kind' => 'gl', 'reference' => 'GL-AR', 'branch_id' => $branch->id, 'account_id' => $mappings['receivable'], 'debit' => '500.00', 'credit' => '0'],
        ['kind' => 'gl', 'reference' => 'GL-BANK', 'branch_id' => $branch->id, 'account_id' => $mappings['bank'], 'debit' => '300.00', 'credit' => '0'],
        ['kind' => 'gl', 'reference' => 'GL-EQ', 'branch_id' => $branch->id, 'account_id' => $equity, 'debit' => '0', 'credit' => '800.00'],
        ['kind' => 'ar', 'reference' => 'OPEN-CUST-1', 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'amount' => '500.00'],
        ['kind' => 'bank', 'reference' => 'BANK-SECOND', 'branch_id' => $branch->id, 'amount' => '300.00'],
    ], false, 'Synthetic opening evidence for the isolated client setup check.');
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
    $factoryBank = TreasuryAccount::factory()->create(['kind' => 'bank', 'gl_account_id' => $mappings['bank'], 'code' => 'DEMO-EG-PH1-BANK-'.strtoupper(Str::random(4))]);
    $factoryCash = TreasuryAccount::factory()->create(['kind' => 'cash', 'gl_account_id' => $mappings['cash'], 'code' => 'DEMO-EG-PH1-CASH-'.strtoupper(Str::random(4))]);
    $need($factorySupplierInvoice !== null && $factorySupplierInvoice->exists && $factorySupplierInvoice->status === 'draft' && $factorySupplierInvoice->posted_at === null
        && $factoryCustomerInvoice !== null && $factoryCustomerInvoice->exists && $factoryCustomerInvoice->status === 'draft' && $factoryCustomerInvoice->posted_at === null
        && $factoryBank->exists && $factoryBank->kind === 'bank' && $factoryCash->exists && $factoryCash->kind === 'cash',
        'Draft invoice or treasury account factory failed its PostgreSQL persistence check.');
    $treasury = app(TreasuryApi::class);
    $bankAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'PH1-OP-BANK-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic Phase 1 operational bank', 'kind' => 'bank', 'gl_account_id' => $mappings['bank']]);
    $cashAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'PH1-OP-CASH-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic Phase 1 operational cash', 'kind' => 'cash', 'gl_account_id' => $mappings['cash']]);

    foreach ([[$maker, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_create', 'M05.order_submit', 'M05.receipt_view', 'M05.receipt_create', 'M06.receipt_create', 'M06.reservation_view', 'M11.tax_calculate', 'M11.source_post', 'M11.report_view', 'M12.supplier_invoice_manage', 'M12.supplier_invoice_post', 'M12.supplier_invoice_view', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.customer_invoice_view', 'M12.ap_view', 'M12.ar_view', 'M12.allocation_manage', 'M12.credit_note_manage', 'M13.payment_prepare', 'M13.payment_execute', 'M13.collection_execute', 'M13.transfer_execute', 'M04.sales_order_view', 'M04.sales_order_create', 'M04.sales_order_edit', 'M04.sales_order_submit', 'M04.sales_order_delivery_create']],
        [$poster, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.reservation_manage', 'M06.reservation_view', 'M11.tax_calculate', 'M11.receipt_post', 'M11.source_post', 'M11.journal_post', 'M11.report_view', 'M12.supplier_invoice_post', 'M12.customer_invoice_post', 'M12.ap_view', 'M12.ar_view', 'M12.allocation_manage', 'M12.credit_note_approve', 'M13.payment_approve', 'M13.payment_execute', 'M13.collection_execute', 'M13.transfer_execute', 'M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_delivery_confirm']]] as [$user, $codes]) {
        DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $grant($user, $codes, (int) $branch->id);
    }
    DB::table('user_branch_scopes')->insert(['user_id' => $denied->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($denied, ['M05.receipt_view'], (int) $branch->id);
    $grant($denied, ['M03.supplier_view', 'M03.customer_view']);
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $maker->id, [
        'code' => 'PH1-SI-WH-'.strtoupper(Str::random(5)), 'name' => 'Synthetic invoice acceptance warehouse', 'branch_id' => $branch->id, 'active' => true,
    ]);
    foreach ([$maker, $poster] as $user) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $makeOrderReceipts = static function (string $quantity, string $label) use ($maker, $poster, $supplier, $product, $branch, $warehouse, &$date): array {
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

    // Snapshot the empty pre-activity cutoff, then post four units through real receipt documents.
    if ($gate1History) {
        $setHistoryDay(-1);
        $gate1Chronology['before_first_activity'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        $before = $gate1Chronology['before_first_activity'];
        if ($before['inventory_fifo'] !== '0.00' || $before['inventory_transit'] !== '0.00' || $before['grni'] !== '0.00'
            || ($before['receivables_payables']['ap'] ?? null) !== '0.00' || ($before['receivables_payables']['ar'] ?? null) !== '0.00') {
            throw new RuntimeException('Pre-activity cutoff should contain no synthetic financial source balances.');
        }
        $gate1Passed['before_first_activity'] = true;
        $setHistoryDay(0);
    }
    $main = $makeOrderReceipts('4.00000000', 'MAIN');
    if ($gate1History) {
        $gate1Chronology['after_receipt'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        if ($gate1Chronology['after_receipt']['inventory_fifo'] !== '40.00'
            || $gate1Chronology['after_receipt']['grni'] !== '40.00') {
            throw new RuntimeException('Inclusive receipt-date cutoff did not include the real FIFO and GRNI receipt postings.');
        }
        if (($gate1Chronology['after_receipt']['receivables_payables']['ap'] ?? null) !== '0.00'
            || ($gate1Chronology['after_receipt']['receivables_payables']['ar'] ?? null) !== '0.00') {
            throw new RuntimeException('Posted receipt cutoff unexpectedly contains AP or AR before any invoices.');
        }
        $gate1Passed['after_receipt'] = true;
    }
    $stockBeforeInvoice = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $fifoBeforeInvoice = DB::table('inventory_fifo_layers')->whereIn('movement_id', DB::table('purchasing_receipt_postings')->whereIn('receipt_id', array_column($main['receipts'], 'id'))->select('movement_id'))->count();
    $receipts = $main['receipts'];
    $setHistoryDay(1);
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
    if ($gate1History) {
        $gate1Chronology['after_grni_clearing'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        if ($gate1Chronology['after_grni_clearing']['grni'] !== '40.00'
            || ($gate1Chronology['after_grni_clearing']['receivables_payables']['grni_cleared'] ?? null) !== '40.00') {
            throw new RuntimeException('Inclusive supplier-invoice cutoff did not clear exactly the matched GRNI receipt value.');
        }
        $gate1Passed['after_grni_clearing'] = true;
    }

    $invoices = DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->orderBy('id')->get();
    $need($invoices->count() === 3 && $invoices->every(fn ($row) => $row->status === 'posted'), 'Three supplier invoice lifecycle records did not reach posted.');
    $pdfEvidence = [];
    foreach ($invoices as $row) {
        $pdf = app(OfficialDocuments::class)->retrieve((string) $row->official_document_id);
        $need(str_starts_with($pdf['bytes'], '%PDF-') && hash_equals($pdf['sha256'], hash('sha256', $pdf['bytes'])), 'Supplier invoice official PDF bytes/hash do not reconcile.');
        $pdfEvidence[] = ['invoice_id' => $row->id, 'official_number' => $row->official_number, 'sha256' => $pdf['sha256'], 'bytes' => strlen($pdf['bytes'])];
    }
    $tax15 = Decimal::round(bcdiv(bcmul('16.50000000000000000000000000000000', (string) $purchaseTax->rate, 32), '100', 32));
    $tax10 = Decimal::round(bcdiv(bcmul('11.00000000000000000000000000000000', (string) $purchaseTax->rate, 32), '100', 32));
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
    $setHistoryDay(2);
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
    app(SalesOrderActions::class)->submit((int) $maker->id, (int) $saleOrder->id, 1);
    $saleLineId = (int) $saleOrder->lines->first()->id;
    app(ApproveSalesOrder::class)->execute((int) $poster->id, (int) $saleOrder->id, 2, [$saleLineId => (int) $warehouse['id']]);
    $delivery = app(SalesDeliveries::class)->create((int) $maker->id, (int) $saleOrder->id,
        ['creation_key' => $uuid(), 'reference' => 'PH1 fully delivered sales source', 'lines' => [['line_id' => $saleLineId, 'quantity' => '4.00000000']]]);
    app(SalesDeliveries::class)->confirm((int) $poster->id, $delivery);
    $deliveryLineId = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $delivery)->value('id');
    $customerInvoice = $api->createCustomerInvoice((int) $maker->id, ['creation_key' => $uuid(), 'customer_id' => $customer->id,
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')),
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '4.00000000']]]);
    $api->validateCustomerInvoice((int) $maker->id, $customerInvoice, 1);
    $customerPost = $api->postCustomerInvoice((int) $maker->id, $customerInvoice, 2);

    // ---- Trade returns acceptance: real sources, rollback only ----
    $sales = app(SalesReturns::class);
    $purchases = app(PurchaseReturns::class);
    $grant($maker, ['M04.sales_order_return_view', 'M04.sales_order_return_create', 'M05.return_view', 'M05.return_create', 'M06.movement_view'], (int) $branch->id);
    $grant($poster, ['M04.sales_order_return_view', 'M04.sales_order_return_approve', 'M05.return_view', 'M05.return_approve', 'M06.movement_view', 'M06.correction_execute', 'M06.isolation_manage'], (int) $branch->id);
    $onHand = static fn (): object => DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->first(['on_hand', 'unavailable']);
    $accountBalance = static function (string $purpose) use ($mappings): string {
        $row = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.status', 'posted')->where('l.account_id', $mappings[$purpose])->selectRaw('coalesce(sum(l.debit),0) d,coalesce(sum(l.credit),0) c')->first();

        return bcsub($row->d, $row->c, 2);
    };
    $checks = [];
    $before = $onHand();
    $cogsBefore = $accountBalance('cogs');
    $inventoryBefore = $accountBalance('inventory');
    $salesBefore = $accountBalance('sales');
    $arBefore = $accountBalance('receivable');
    $invoice = DB::table('rp_customer_invoices')->find($customerInvoice);

    $eligible = collect($sales->eligibleDeliveries((int) $maker->id))->firstWhere('id', $delivery);
    $need($eligible && $eligible['lines'][0]['returnable'] === '4.00000000', 'Invoiced delivery is not offered for return.');
    $checks['eligible_delivery'] = true;
    $expect(fn () => $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $delivery, 'reason_code' => 'defective', 'reason' => 'كمية أكبر من المفوتر',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '4.50000000', 'condition' => 'sellable', 'serials' => []]]]), 'return above invoiced');
    $checks['blocks_above_invoiced'] = true;
    $expect(fn () => $sales->create((int) $denied->id, ['creation_key' => $uuid(), 'delivery_id' => $delivery, 'reason_code' => 'defective', 'reason' => 'مستخدم بلا صلاحية',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '1.00000000', 'condition' => 'sellable', 'serials' => []]]]), 'unauthorized return', 'forbidden');
    $checks['blocks_unauthorized'] = true;

    $key = $uuid();
    $data = ['creation_key' => $key, 'delivery_id' => $delivery, 'reason_code' => 'defective', 'reason' => 'مرتجع جزئي عبر طبقتين',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '1.50000000', 'condition' => 'sellable', 'serials' => []]]];
    $return = $sales->create((int) $maker->id, $data);
    $need($sales->create((int) $maker->id, $data) === $return, 'Return replay changed identity.');
    $need(bccomp($onHand()->on_hand, $before->on_hand, 8) === 0, 'Draft return changed stock.');
    $checks['draft_has_no_effect'] = true;
    $expect(fn () => $sales->approve((int) $maker->id, $return), 'creator approving own return', 'forbidden');
    $checks['maker_cannot_approve'] = true;

    $result = $sales->approve((int) $poster->id, $return);
    $need(bccomp($onHand()->on_hand, bcadd($before->on_hand, '1.5', 8), 8) === 0, 'Customer return did not restore exact stock.');
    $movements = DB::table('sales_return_postings as p')->join('sales_return_lines as l', 'l.id', '=', 'p.line_id')->where('l.return_id', $return)->get(['p.movement_id', 'p.amount']);
    $need($movements->count() === 2, 'Return across two FIFO layers must create two movements, got '.$movements->count());
    $cost = $movements->reduce(fn ($sum, $m) => bcadd($sum, $m->amount, 2), '0.00');
    $need($cost === '15.00', 'Returned FIFO cost must be 15.00, got '.$cost);
    $need(bcsub($accountBalance('inventory'), $inventoryBefore, 2) === '15.00' && bcsub($accountBalance('cogs'), $cogsBefore, 2) === '-15.00', 'Stock and COGS reversal mismatch.');
    $note = DB::table('rp_credit_notes')->where('return_source', 'sales-return:'.$return.':'.$customerInvoice)->first();
    $expectedNet = bcdiv(bcmul($invoice->net_amount, '1.5', 8), '4', 2);
    $expectedTax = bcdiv(bcmul($invoice->tax_amount, '1.5', 8), '4', 2);
    $need($note && $note->status === 'posted' && $note->net_amount === $expectedNet && $note->tax_amount === $expectedTax, 'Credit note value mismatch.');
    $need(bcsub($accountBalance('receivable'), $arBefore, 2) === bcsub('0', $note->amount, 2) && bcsub($accountBalance('sales'), $salesBefore, 2) === $note->net_amount, 'AR and revenue reversal mismatch.');
    $open = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $customerInvoice)->first();
    $need($open->credited_amount === $note->amount, 'Open item was not credited.');
    $need($sales->approve((int) $poster->id, $return)['number'] === $result['number'], 'Approve replay changed the document.');
    $checks['customer_return_posted'] = ['cost' => $cost, 'credit' => $note->amount, 'number' => $result['number']];

    $damaged = $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $delivery, 'reason_code' => 'customer_refused', 'reason' => 'وصل تالف من العميل',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '0.50000000', 'condition' => 'damaged', 'serials' => []]]]);
    $sales->approve((int) $poster->id, $damaged);
    $need(bccomp($onHand()->unavailable, bcadd($before->unavailable, '0.5', 8), 8) === 0, 'Damaged return was not isolated.');
    $checks['damaged_isolated'] = true;

    $need(collect($sales->eligibleDeliveries((int) $maker->id))->firstWhere('id', $delivery)['lines'][0]['returnable'] === '2.00000000', 'Remaining returnable is not 2.');
    $rejected = $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $delivery, 'reason_code' => 'other', 'reason' => 'مسودة ستُرفض',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '2.00000000', 'condition' => 'sellable', 'serials' => []]]]);
    $expect(fn () => $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $delivery, 'reason_code' => 'other', 'reason' => 'تتجاوز المسودة المفتوحة',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '0.10000000', 'condition' => 'sellable', 'serials' => []]]]), 'open draft reserves quantity');
    // Same user holding both permissions still cannot approve what they prepared.
    $grant($poster, ['M04.sales_order_return_create', 'M05.return_create'], (int) $branch->id);
    $grant($maker, ['M04.sales_order_return_approve', 'M05.return_approve'], (int) $branch->id);
    $expect(fn () => $sales->approve((int) $maker->id, $rejected), 'self approval with permission', 'conflict');
    $expect(fn () => $sales->reject((int) $maker->id, $rejected, 'رفض ذاتي غير مسموح'), 'self rejection with permission', 'conflict');
    $checks['self_approval_blocked_with_permission'] = true;
    $sales->reject((int) $poster->id, $rejected, 'مرفوض بعد الفحص');
    $final = $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $delivery, 'reason_code' => 'other', 'reason' => 'إغلاق الكمية بالكامل',
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '2.00000000', 'condition' => 'sellable', 'serials' => []]]]);
    $sales->approve((int) $poster->id, $final);
    $credited = (string) DB::table('rp_credit_notes')->where('kind', 'customer')->where('original_invoice_id', $customerInvoice)->where('status', 'posted')->sum('amount');
    $need(bccomp($credited, $invoice->total_amount, 2) === 0, 'Full return must exhaust the invoice exactly: '.$credited.' vs '.$invoice->total_amount);
    $need(DB::table('rp_customer_invoices')->where('id', $customerInvoice)->value('status') === 'credited', 'Fully returned invoice must be credited.');
    $checks['full_return_exhausts_invoice'] = $credited;

    // Supplier: the restored customer goods are a new layer, so the old receipt layer stays exhausted.
    $old = $purchases->create((int) $maker->id, ['creation_key' => $uuid(), 'receipt_id' => $receipts[0]['id'], 'reason_code' => 'defective', 'reason' => 'طبقة الاستلام مستهلكة',
        'lines' => [['receipt_line_id' => $receipts[0]['line_id'], 'quantity' => '0.50000000', 'serials' => []]]]);
    $expect(fn () => $purchases->approve((int) $poster->id, $old), 'exhausted receipt layer');
    $need(DB::table('purchasing_returns')->where('id', $old)->value('status') === 'draft', 'Failed approval left a partial state.');
    $purchases->reject((int) $poster->id, $old, 'الطبقة الأصلية مستهلكة');
    $checks['exhausted_layer_rolls_back'] = true;

    app(DocumentNumbering::class)->setStart((int) $maker->id, 'purchase_return', (int) substr($date, 0, 4), 500);
    $extra = $makeOrderReceipts('2.00000000', 'RET');
    $line = $extra['receipts'][0]['line_id'];
    [$supplierInvoice, , $invoiceApi] = $makeInvoice((int) $maker->id, 'RET-'.strtoupper(Str::random(6)), $date, (int) $supplier->id, $extra['order_line_id'], [['receipt_line_id' => $line, 'quantity' => '2.00000000']], '2.00000000');
    $postLifecycle((int) $maker->id, $supplierInvoice, $invoiceApi);
    $ppvBefore = $accountBalance('ppv');
    $apBefore = $accountBalance('ap');
    $taxBefore = $accountBalance('tax_input');
    $stock = $onHand();
    $inventoryBefore = $accountBalance('inventory');
    $supplierReturn = $purchases->create((int) $maker->id, ['creation_key' => $uuid(), 'receipt_id' => $extra['receipts'][0]['id'], 'reason_code' => 'defective', 'reason' => 'عيب مصنعي',
        'lines' => [['receipt_line_id' => $line, 'quantity' => '1.00000000', 'serials' => []]]]);
    $expect(fn () => $purchases->approve((int) $maker->id, $supplierReturn), 'creator approving supplier return', 'conflict');
    $supplierResult = $purchases->approve((int) $poster->id, $supplierReturn);
    $need(bccomp($onHand()->on_hand, bcsub($stock->on_hand, '1', 8), 8) === 0, 'Supplier return did not reduce stock.');
    $need(bcsub($accountBalance('inventory'), $inventoryBefore, 2) === '-10.00', 'Inventory must fall by receipt cost 10.00.');
    $supplierNote = DB::table('rp_credit_notes')->where('return_source', 'purchase-return:'.$supplierReturn.':'.$supplierInvoice)->first();
    $need($supplierNote && $supplierNote->status === 'posted' && $supplierNote->net_amount === '11.00' && $supplierNote->tax_amount === '1.54', 'Supplier credit mismatch.');
    $need(bcsub($accountBalance('ap'), $apBefore, 2) === '12.54' && bcsub($accountBalance('tax_input'), $taxBefore, 2) === '-1.54' && bcsub($accountBalance('ppv'), $ppvBefore, 2) === '-1.00', 'AP, VAT or PPV effect mismatch.');
    $need(str_ends_with($supplierResult['number'], '-000500'), 'Start number was not honoured: '.$supplierResult['number']);
    $expect(fn () => app(DocumentNumbering::class)->setStart((int) $maker->id, 'purchase_return', (int) substr($date, 0, 4), 900), 'numbering locked after first document');
    $checks['numbering_start_and_lock'] = $supplierResult['number'];
    $checks['supplier_return_posted'] = ['number' => $supplierResult['number'], 'credit' => $supplierNote->amount];



    // Serialized goods: identity and cost follow the unit through both return directions.
    $orders = app(PurchaseOrders::class);
    $serialOrder = $orders->save((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'order_date' => $date, 'expected_date' => null,
        'currency' => 'EGP', 'payment_terms' => 'Synthetic', 'notes' => 'serial returns', 'tax_mode' => 'exclusive', 'reported_tax' => null,
        'lines' => [['product_id' => $serialProduct->id, 'quantity' => '3.00000000', 'unit_price' => '100.00000000', 'discount_amount' => '0.00']]], null, 0);
    $orders->transition((int) $maker->id, $serialOrder, 1, 'submit');
    $orders->transition((int) $poster->id, $serialOrder, 2, 'approve');
    $orders->issue((int) $poster->id, $serialOrder);
    $serialOrderLine = (int) DB::table('purchasing_order_lines')->where('order_id', $serialOrder)->value('id');
    $receiving = app(PurchaseReceiving::class);
    $serialReceipt = $receiving->save((int) $maker->id, ['creation_key' => $uuid(), 'order_id' => $serialOrder, 'warehouse_id' => $warehouse['id'], 'receipt_date' => $date,
        'delivery_reference' => 'SERIAL-RET', 'notes' => 'serial receipt', 'lines' => [['order_line_id' => $serialOrderLine, 'quantity' => '3.00000000', 'serials' => ['RET-SN-1', 'RET-SN-2', 'RET-SN-3']]]], null, 0);
    $receiving->submit((int) $maker->id, $serialReceipt, 1);
    app(PostPurchaseReceipt::class)->execute((int) $poster->id, $serialReceipt, 2);
    $serialReceiptLine = (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $serialReceipt)->value('id');
    [$serialInvoice, , $serialApi] = $makeInvoice((int) $maker->id, 'RETS-'.strtoupper(Str::random(6)), $date, (int) $supplier->id, $serialOrderLine, [['receipt_line_id' => $serialReceiptLine, 'quantity' => '3.00000000']], '3.00000000');
    $postLifecycle((int) $maker->id, $serialInvoice, $serialApi);
    $serialUnit = (int) $serialProduct->base_unit_id;
    $serialPrice = app(SalePrices::class)->save((int) $maker->id, (int) $serialProduct->id, ['amount' => '150.00000000', 'unit_id' => $serialUnit, 'starts_on' => $date, 'ends_on' => null, 'reference' => 'Synthetic serial price']);
    app(SalePrices::class)->decide((int) $poster->id, $serialPrice, 'approve');
    $serialSale = app(SalesOrderActions::class)->save((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'order_date' => $date,
        'requested_delivery_date' => null, 'discount_mode' => 'percent', 'discount_value' => '0', 'payment_terms' => $salePaymentTerms, 'delivery_terms' => $saleDeliveryTerms, 'notes' => 'serial sale',
        'lines' => [['product_id' => $serialProduct->id, 'unit_id' => $serialUnit, 'quantity' => '2.00000000', 'requested_price' => null, 'description' => 'Synthetic serial unit', 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null]]]);
    app(SalesOrderActions::class)->submit((int) $maker->id, (int) $serialSale->id, 1);
    $serialSaleLine = (int) $serialSale->lines->first()->id;
    Customer::whereKey($customer->id)->update(['credit_limit' => '1.00']);
    $expect(fn () => app(ApproveSalesOrder::class)->execute((int) $poster->id, (int) $serialSale->id, 2, [$serialSaleLine => (int) $warehouse['id']]), 'credit limit blocks approval');
    Customer::whereKey($customer->id)->update(['credit_limit' => '100000.00']);
    $checks['credit_limit_blocks_approval'] = true;
    app(ApproveSalesOrder::class)->execute((int) $poster->id, (int) $serialSale->id, 2, [$serialSaleLine => (int) $warehouse['id']]);
    $serialDelivery = app(SalesDeliveries::class)->create((int) $maker->id, (int) $serialSale->id, ['creation_key' => $uuid(), 'reference' => 'Serial delivery', 'lines' => [['line_id' => $serialSaleLine, 'quantity' => '2.00000000']]]);
    app(SalesDeliveries::class)->confirm((int) $poster->id, $serialDelivery);
    $serialDeliveryLine = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $serialDelivery)->value('id');
    $serialCustomerInvoice = $api->createCustomerInvoice((int) $maker->id, ['creation_key' => $uuid(), 'customer_id' => $customer->id, 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')),
        'lines' => [['delivery_line_id' => $serialDeliveryLine, 'quantity' => '2.00000000']]]);
    $api->validateCustomerInvoice((int) $maker->id, $serialCustomerInvoice, 1);
    $api->postCustomerInvoice((int) $maker->id, $serialCustomerInvoice, 2);
    $issued = collect($sales->eligibleDeliveries((int) $maker->id))->firstWhere('id', $serialDelivery)['lines'][0]['serials'];
    $need(count($issued) === 2, 'Delivered serials are not offered for return.');
    $expect(fn () => $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $serialDelivery, 'reason_code' => 'defective', 'reason' => 'رقم لا يخص التسليم',
        'lines' => [['delivery_line_id' => $serialDeliveryLine, 'quantity' => '1.00000000', 'condition' => 'sellable', 'serials' => ['NOT-THIS-UNIT']]]]), 'foreign serial');
    $checks['blocks_foreign_serial'] = true;
    $expect(fn () => $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $serialDelivery, 'reason_code' => 'defective', 'reason' => 'بلا رقم مسلسل',
        'lines' => [['delivery_line_id' => $serialDeliveryLine, 'quantity' => '1.00000000', 'condition' => 'sellable', 'serials' => []]]]), 'missing serial');
    $serialReturn = $sales->create((int) $maker->id, ['creation_key' => $uuid(), 'delivery_id' => $serialDelivery, 'reason_code' => 'defective', 'reason' => 'وحدة مسلسلة معيبة',
        'lines' => [['delivery_line_id' => $serialDeliveryLine, 'quantity' => '1.00000000', 'condition' => 'damaged', 'serials' => [$issued[0]]]]]);
    $expect(fn () => $sales->approve((int) $maker->id, $serialReturn), 'self approval with permission', 'conflict');
    $checks['self_approval_blocked_with_permission'] = true;
    $sales->approve((int) $poster->id, $serialReturn);
    $serialRow = DB::table('inventory_serials')->where('product_id', $serialProduct->id)->where('normalized', $issued[0])->first();
    $need($serialRow->state === 'unavailable', 'Damaged serial must be isolated, got '.$serialRow->state);
    $need(count(collect($sales->eligibleDeliveries((int) $maker->id))->firstWhere('id', $serialDelivery)['lines'][0]['serials']) === 1, 'Returned serial is still offered.');
    $checks['customer_serial_return'] = $issued[0];
    $inStock = DB::table('inventory_serials')->where('product_id', $serialProduct->id)->where('state', 'available')->value('normalized');
    $sold = DB::table('inventory_serials')->where('product_id', $serialProduct->id)->where('state', 'issued')->value('normalized');
    $need($inStock !== null && $sold !== null, 'Serial fixture states are not as expected.');
    $supplierSold = $purchases->create((int) $maker->id, ['creation_key' => $uuid(), 'receipt_id' => $serialReceipt, 'reason_code' => 'defective', 'reason' => 'وحدة عند العميل',
        'lines' => [['receipt_line_id' => $serialReceiptLine, 'quantity' => '1.00000000', 'serials' => [$sold]]]]);
    $expect(fn () => $purchases->approve((int) $poster->id, $supplierSold), 'serial already with customer');
    $purchases->reject((int) $poster->id, $supplierSold, 'الوحدة عند العميل');
    $serialSupplier = $purchases->create((int) $maker->id, ['creation_key' => $uuid(), 'receipt_id' => $serialReceipt, 'reason_code' => 'defective', 'reason' => 'وحدة مسلسلة للمورد',
        'lines' => [['receipt_line_id' => $serialReceiptLine, 'quantity' => '1.00000000', 'serials' => [$inStock]]]]);
    $expect(fn () => $purchases->approve((int) $maker->id, $serialSupplier), 'supplier self approval', 'conflict');
    $purchases->approve((int) $poster->id, $serialSupplier);
    $need(DB::table('inventory_serials')->where('product_id', $serialProduct->id)->where('normalized', $inStock)->value('state') === 'issued', 'Supplier serial must leave stock.');
    $checks['supplier_serial_return'] = $inStock;

    $openingItem = DB::table('rp_open_items')->where('source_type', 'opening_receivable')->where('party_id', $customer->id)->first();
    $need($openingItem && $openingItem->remaining_amount === '500.00', 'Customer opening did not create its open item.');
    $treasuryApi = app(TreasuryApi::class);
    $secondBank = $treasuryApi->saveOperationalAccount((int) $maker->id, ['code' => 'BANK-SECOND', 'name' => 'البنك الثاني', 'kind' => 'bank', 'gl_account_id' => $mappings['bank']]);
    $need($secondBank !== $bankAccount && DB::table('treasury_accounts')->where('kind', 'bank')->where('active', true)->count() >= 2, 'Second bank account was not added.');
    $treasuryApi->executeCustomerCollection((int) $maker->id, ['idempotency_key' => $uuid(), 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'account_id' => $secondBank, 'date' => $date, 'amount' => '100.00', 'reference' => 'Opening AR collection'],
        [['open_item_id' => $openingItem->id, 'amount' => '100.00']]);
    $need(DB::table('rp_open_items')->where('id', $openingItem->id)->value('remaining_amount') === '400.00', 'Opening AR was not settled.');
    $checks['opening_receivable_collected'] = true;
    $move = static fn (int $from, string $amount) => $treasuryApi->transfer((int) $maker->id, ['idempotency_key' => (string) Str::uuid(), 'branch_id' => $branch->id, 'from_account_id' => $from, 'to_account_id' => $cashAccount, 'date' => $date, 'amount' => $amount, 'reference' => 'Account balance check']);
    $expect(fn () => $move($secondBank, '400.01'), 'second bank overdraft');
    $move($secondBank, '400.00');
    $expect(fn () => $move($bankAccount, '1.00'), 'first bank has no opening of its own');
    $checks['treasury_balance_per_account'] = true;
    $values = app(ReceivablesPayablesApi::class)->reconciliationValues((int) $poster->id, [(int) $branch->id], $date);
    $need(bccomp($values['ar'], $accountBalance('receivable'), 2) === 0, 'AR subledger differs from GL: '.$values['ar'].' vs '.$accountBalance('receivable'));
    $checks['ar_subledger_equals_gl'] = $values['ar'];

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $checks['database_guards_pass'] = true;
    $layers = (string) DB::table('inventory_fifo_layers')->sum('remaining_value');
    $need(bccomp(Decimal::round($layers), $accountBalance('inventory'), 2) === 0, 'Inventory GL does not equal FIFO: '.$layers.' vs '.$accountBalance('inventory'));
    $checks['inventory_equals_fifo'] = $accountBalance('inventory');
    $balance = DB::table('accounting_journal_lines as l')->join('accounting_journals as j', 'j.id', '=', 'l.journal_id')->where('j.status', 'posted')->selectRaw('sum(l.debit) d,sum(l.credit) c')->first();
    $need(bccomp($balance->d, $balance->c, 2) === 0, 'Trial balance is not balanced.');
    $checks['trial_balance_balanced'] = true;
    echo json_encode(['all_pass' => true, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {
    if ($db->transactionLevel() > 0) {
        DB::rollBack();
    }
    Carbon::setTestNow();
}

<?php

// Source-driven M12 supplier invoice acceptance against isolated PostgreSQL only.
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
    foreach ([['sale', null], ['purchase', (int) $product->id]] as [$type, $taxProduct]) {
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
    $treasury = app(TreasuryApi::class);
    $bankAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'PH1-OP-BANK-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic Phase 1 operational bank', 'kind' => 'bank', 'gl_account_id' => $mappings['bank']]);
    $cashAccount = $treasury->saveOperationalAccount((int) $maker->id, ['code' => 'PH1-OP-CASH-'.strtoupper(Str::random(5)),
        'name' => 'Synthetic Phase 1 operational cash', 'kind' => 'cash', 'gl_account_id' => $mappings['cash']]);

    foreach ([[$maker, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_create', 'M05.order_submit', 'M05.receipt_view', 'M05.receipt_create', 'M06.receipt_create', 'M06.reservation_view', 'M11.tax_calculate', 'M11.source_post', 'M11.report_view', 'M12.supplier_invoice_manage', 'M12.supplier_invoice_post', 'M12.supplier_invoice_view', 'M12.customer_invoice_manage', 'M12.customer_invoice_post', 'M12.customer_invoice_view', 'M12.ap_view', 'M12.ar_view', 'M12.allocation_manage', 'M12.credit_note_manage', 'M13.payment_prepare', 'M13.payment_execute', 'M13.collection_execute', 'M13.transfer_execute', 'M04.sales_order_view', 'M04.sales_order_create', 'M04.sales_order_edit', 'M04.sales_order_submit', 'M04.sales_order_delivery_create']],
        [$poster, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M03.customer_view', 'M02.product_view', 'M02.price_view', 'M05.order_view', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post', 'M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M06.reservation_manage', 'M06.reservation_view', 'M11.tax_calculate', 'M11.receipt_post', 'M11.source_post', 'M11.journal_post', 'M11.report_view', 'M12.supplier_invoice_post', 'M12.customer_invoice_post', 'M12.ap_view', 'M12.ar_view', 'M12.allocation_manage', 'M12.credit_note_approve', 'M13.payment_approve', 'M13.payment_execute', 'M13.collection_execute', 'M13.transfer_execute', 'M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_delivery_confirm']]] as [$user, $codes]) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
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
    $customerInvoiceRow = DB::table('rp_customer_invoices')->find($customerInvoice);
    $arOpen = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $customerInvoice)->first();
    $need($customerInvoiceRow->status === 'posted' && $arOpen && $arOpen->original_amount === $customerInvoiceRow->total_amount,
        'Delivered source customer invoice did not create its exact AR open item.');
    $collectionPart = bcdiv($customerInvoiceRow->total_amount, '2', 2);
    $collectionRemaining = bcsub($customerInvoiceRow->total_amount, $collectionPart, 2);
    $setHistoryDay(3);
    $collectionOneKey = $uuid();
    $collectionData = ['idempotency_key' => $collectionOneKey, 'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'account_id' => $cashAccount, 'date' => $date, 'amount' => $collectionPart, 'reference' => 'PH1 AR partial'];
    $collectionOne = $treasury->executeCustomerCollection((int) $maker->id, $collectionData,
        [['open_item_id' => $arOpen->id, 'amount' => $collectionPart]]);
    $collectionReplay = $treasury->executeCustomerCollection((int) $maker->id, $collectionData,
        [['open_item_id' => $arOpen->id, 'amount' => $collectionPart]]);
    $need($collectionOne['movement_id'] === $collectionReplay['movement_id'], 'Same collection idempotency key created a duplicate receipt movement.');
    if ($gate1History) {
        $gate1Chronology['after_partial_ar_settlement'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        $expectedPartialAr = bcsub((string) $customerInvoiceRow->total_amount, $collectionPart, 2);
        if (($gate1Chronology['after_partial_ar_settlement']['receivables_payables']['ar'] ?? null) !== $expectedPartialAr) {
            throw new RuntimeException('As-of partial collection did not reduce AR by exactly the posted settlement allocation.');
        }
        $gate1Passed['after_partial_ar_settlement'] = true;
    }
    $setHistoryDay(4);
    $collectionTwo = $treasury->executeCustomerCollection((int) $maker->id, ['idempotency_key' => $uuid(), 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'account_id' => $cashAccount, 'date' => $date, 'amount' => $collectionRemaining, 'reference' => 'PH1 AR final'],
        [['open_item_id' => $arOpen->id, 'amount' => $collectionRemaining]]);
    $customerAdvance = $treasury->executeCustomerCollection((int) $maker->id, ['idempotency_key' => $uuid(), 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'account_id' => $cashAccount, 'date' => $date, 'amount' => '1.00', 'allow_advance' => true,
        'reference' => 'PH1 explicit customer advance'], []);
    if ($gate1History) {
        $gate1Chronology['after_advance'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        if (($gate1Chronology['after_advance']['receivables_payables']['ar'] ?? null) !== '0.00'
            || ($gate1Chronology['after_advance']['receivables_payables']['customer_advances'] ?? null) !== '1.00') {
            throw new RuntimeException('As-of customer advance altered AR or omitted its separate source balance.');
        }
        $gate1Passed['after_advance'] = true;
    }
    $arRemaining = (string) DB::table('rp_open_items')->where('id', $arOpen->id)->value('remaining_amount');
    $need($arRemaining === '0.00', 'Partial/final customer collections did not settle AR to zero.');

    // AP payouts use real cash accumulated from the posted customer collections above.
    $apItems = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->whereIn('source_id', [$invoiceA, $invoiceB, $invoiceC])->orderBy('id')->get();
    $apByInvoice = $apItems->keyBy('source_id');
    $setHistoryDay(5);
    $paymentOne = $treasury->prepareSupplierPayment((int) $maker->id, ['creation_key' => $uuid(), 'branch_id' => $branch->id,
        'supplier_id' => $supplier->id, 'account_id' => $cashAccount, 'request_date' => $date, 'amount' => '10.00', 'reference' => 'PH1 AP partial']);
    $treasury->approveSupplierPayment((int) $poster->id, $paymentOne, 1);
    $payOneKey = $uuid();
    $payOne = $treasury->executeSupplierPayment((int) $maker->id, $paymentOne, 2, $payOneKey,
        [['open_item_id' => $apByInvoice[$invoiceA]->id, 'amount' => '10.00']]);
    $payOneReplay = $treasury->executeSupplierPayment((int) $maker->id, $paymentOne, 2, $payOneKey,
        [['open_item_id' => $apByInvoice[$invoiceA]->id, 'amount' => '10.00']]);
    $need($payOne['movement_id'] === $payOneReplay['movement_id'], 'Same supplier payment retry did not return its one actual movement.');
    if ($gate1History) {
        $gate1Chronology['after_partial_ap_settlement'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        if (bccomp((string) ($gate1Chronology['after_partial_ap_settlement']['receivables_payables']['ap'] ?? '0.00'), '0.00', 2) === 0) {
            throw new RuntimeException('Partial supplier payment unexpectedly settled all AP before the remaining payment action.');
        }
        $gate1Passed['after_partial_ap_settlement'] = true;
    }
    $remainingA = bcsub((string) $apByInvoice[$invoiceA]->original_amount, '10.00', 2);
    $remainingB = (string) $apByInvoice[$invoiceB]->original_amount;
    $remainingC = (string) $apByInvoice[$invoiceC]->original_amount;
    $finalPayAmount = bcadd(bcadd($remainingA, $remainingB, 2), $remainingC, 2);
    $setHistoryDay(6);
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
    if ($gate1History) {
        $gate1Chronology['after_supplier_payment'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        if (($gate1Chronology['after_supplier_payment']['receivables_payables']['ap'] ?? null) !== '0.00') {
            throw new RuntimeException('As-of final supplier payment did not settle AP at its inclusive business-date cutoff.');
        }
        $gate1Passed['after_supplier_payment'] = true;
    }

    // Independent credit approval after settlement preserves the party credit instead of rewriting invoices.
    $setHistoryDay(7);
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
    if ($gate1History) {
        $gate1Chronology['after_credit_notes'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        if (($gate1Chronology['after_credit_notes']['receivables_payables']['ap'] ?? null) !== '-2.00'
            || ($gate1Chronology['after_credit_notes']['receivables_payables']['ar'] ?? null) !== '-2.00') {
            throw new RuntimeException('As-of posted credit notes did not remain distinct source credits on the correct parties.');
        }
        $paymentCutoff = Carbon::parse($historyBase, 'Africa/Cairo')->addDays(6)->toDateString();
        $atPaymentCutoff = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $paymentCutoff);
        Gate1FinancialHistory::sameCutoff($gate1Chronology['after_supplier_payment'], $atPaymentCutoff, 'Later-dated credit notes');
        $gate1Passed['after_credit_notes'] = true;
        $gate1Chronology['earlier_payment_cutoff_stable_after_credit'] = true;
        $gate1Passed['earlier_payment_cutoff_stable_after_credit'] = true;
    }

    $setHistoryDay(8);
    $agedSource = $makeOrderReceipts('1.00000000', 'AGED-AP');
    [$agedInvoice, , $agedApi] = $makeInvoice((int) $maker->id, 'PH1-AGED-'.strtoupper(Str::random(5)), $date,
        (int) $supplier->id, $agedSource['order_line_id'], [['receipt_line_id' => $agedSource['receipts'][0]['line_id'], 'quantity' => '1.00000000']]);
    $agedPost = $postLifecycle((int) $maker->id, $agedInvoice, $agedApi);
    $agedOpen = DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $agedInvoice)->first();
    $need($agedOpen && $agedOpen->due_date === date('Y-m-d', strtotime($date.' + 30 days')), 'Aged AP fixture was not created through posted M12 actions.');
    if ($gate1History) {
        $afterDatedActivity = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $date);
        $gate1Chronology['later_dated_ap'] = ['date' => $date, 'as_of_ap' => $afterDatedActivity['receivables_payables']['ap'] ?? null,
            'new_invoice_id' => (int) $agedInvoice];
        $expectedLaterAp = bcadd((string) $gate1Chronology['after_credit_notes']['receivables_payables']['ap'], (string) $agedOpen->original_amount, 2);
        if (($afterDatedActivity['receivables_payables']['ap'] ?? null) !== $expectedLaterAp
            || ($afterDatedActivity['grni'] ?? null) !== '50.00'
            || ($afterDatedActivity['receivables_payables']['grni_cleared'] ?? null) !== '50.00') {
            throw new RuntimeException('Later dated AP invoice did not change only its own as-of payable and GRNI clearing totals.');
        }
        $gate1Chronology['later_dated_ap']['expected_ap'] = $expectedLaterAp;
        $creditCutoff = Carbon::parse($historyBase, 'Africa/Cairo')->addDays(7)->toDateString();
        $atCreditCutoff = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $creditCutoff);
        Gate1FinancialHistory::sameCutoff($gate1Chronology['after_credit_notes'], $atCreditCutoff, 'Later-dated receipt and AP invoice');
        $gate1Chronology['earlier_credit_cutoff_stable_after_later_ap'] = true;
        $gate1Passed['later_dated_ap'] = true;
        $gate1Passed['earlier_credit_cutoff_stable_after_later_ap'] = true;
    }

    // M03 read projection is checked against the real M12/M13 actions above.
    $profileApi = app(ReceivablesPayablesApi::class);
    // Shift only the read clock to verify overdue aging; posted transaction dates remain actual action dates.
    Carbon::setTestNow(now('Africa/Cairo')->addDays(100));
    $supplierProfile = $profileApi->partyFinancialProfile((int) $maker->id, 'supplier', (int) $supplier->id);
    $customerProfile = $profileApi->partyFinancialProfile((int) $maker->id, 'customer', (int) $customer->id);
    $need($supplierProfile['authorized'] && $customerProfile['authorized'], 'Scoped AP/AR profile access was not authorized.');
    $need($supplierProfile['summary']['posted_invoice_count'] === 4 && $supplierProfile['summary']['posted_invoices'] === bcadd($postedGross, $agedOpen->original_amount, 2)
        && $supplierProfile['summary']['allocated_settlements'] === $postedGross && $supplierProfile['summary']['outstanding'] === $agedOpen->remaining_amount
        && $supplierProfile['summary']['overdue'] === $agedOpen->remaining_amount
        && $supplierProfile['summary']['unapplied_credits'] === '2.00', 'Supplier profile totals do not reconcile to the four posted invoices, settlements, and excess credit.');
    $need($customerProfile['summary']['posted_invoice_count'] === 1 && $customerProfile['summary']['posted_invoices'] === $customerInvoiceRow->total_amount
        && $customerProfile['summary']['allocated_settlements'] === $customerInvoiceRow->total_amount && $customerProfile['summary']['outstanding'] === '0.00'
        && $customerProfile['summary']['unapplied_credits'] === '2.00', 'Customer profile totals do not reconcile to the posted invoice, collections, and excess credit.');
    $need($supplierProfile['summary']['customer_advances'] === null && $customerProfile['summary']['customer_advances'] === '1.00'
        && $supplierProfile['summary']['unmapped_opening_payables'] === '0.00' && $customerProfile['summary']['unmapped_opening_payables'] === '0.00'
        && $supplierProfile['invoices']['total'] === 4 && $customerProfile['invoices']['total'] === 1
        && $supplierProfile['statement']['closing'] === $supplierProfile['summary']['net_outstanding']
        && $customerProfile['statement']['closing'] === $customerProfile['summary']['net_outstanding']
        && $supplierProfile['statement']['closing'] === bcsub($agedOpen->remaining_amount, '2.00', 2) && $customerProfile['statement']['closing'] === '-2.00'
        && $supplierProfile['statement']['rows']['total'] > 0 && $customerProfile['statement']['rows']['total'] > 0,
        'Party statement/invoice pages are missing or duplicate totals do not reconcile.');
    $advanceMovement = collect($customerProfile['movements']['rows']['items'])->firstWhere('reference', 'PH1 explicit customer advance');
    $need($supplierProfile['movements']['authorized'] && $supplierProfile['movements']['rows']['total'] === 2
        && $customerProfile['movements']['authorized'] && $customerProfile['movements']['rows']['total'] === 3
        && $advanceMovement && $advanceMovement->allocated_amount === '0.00' && $advanceMovement->unallocated_amount === '1.00',
        'Treasury projection omitted a settlement/advance movement or double-counted the unallocated advance.');
    $need($profileApi->partyFinancialProfile((int) $denied->id, 'supplier', (int) $supplier->id) === ['authorized' => false]
        && $profileApi->partyFinancialProfile((int) $denied->id, 'customer', (int) $customer->id) === ['authorized' => false],
        'Party profiles exposed financial data without scoped AP/AR permission.');
    $expect(fn () => $profileApi->partyFinancialProfile((int) $maker->id, 'supplier', (int) $supplier->id,
        ['branch' => (int) Branch::factory()->create()->id]), 'unscoped financial profile branch', 'forbidden');
    Carbon::setTestNow();

    // Direct bank/cash transfer is a paired Treasury movement, with no income or expense accounts.
    Carbon::setTestNow();
    $setHistoryDay(9);
    $priorTransferCutoff = Carbon::parse($historyBase, 'Africa/Cairo')->addDays(8)->toDateString();
    $beforeLaterTransfer = $gate1History ? Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $priorTransferCutoff) : null;
    $transfer = $treasury->transfer((int) $maker->id, ['idempotency_key' => $uuid(), 'branch_id' => $branch->id,
        'from_account_id' => $cashAccount, 'to_account_id' => $bankAccount, 'date' => $date, 'amount' => '1.00', 'reference' => 'PH1 direct cash transfer']);
    if ($gate1History) {
        $afterLaterTransfer = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $priorTransferCutoff);
        Gate1FinancialHistory::sameCutoff($beforeLaterTransfer, $afterLaterTransfer, 'Later-dated Treasury transfer');
        $gate1Chronology['later_treasury_activity_stability'] = ['later_activity_date' => $date, 'prior_cutoff' => $priorTransferCutoff, 'unchanged' => true];
        $gate1Passed['later_treasury_activity_stability'] = true;
    }
    $transferLines = DB::table('accounting_journal_lines')->where('journal_id', $transfer['journal_id'])->get();
    $transferAccountIds = $transferLines->pluck('account_id')->all();
    $transferAccountIds = array_map('intval', $transferAccountIds);
    $need(count($transferLines) === 2 && ! in_array((int) $mappings['sales'], $transferAccountIds, true)
        && ! in_array((int) $mappings['ppv'], $transferAccountIds, true), 'Direct Treasury transfer was posted as revenue/expense instead of balance-sheet movement.');

    $reports = app(FinancialReports::class);
    $reportFrom = $gate1History ? $historyBase : $date;
    $trial = $reports->trialBalance((int) $maker->id, (int) $branch->id, $reportFrom, $date);
    $income = $reports->statement((int) $maker->id, 'income', (int) $branch->id, $reportFrom, $date);
    $balance = $reports->statement((int) $maker->id, 'balance', (int) $branch->id, $reportFrom, $date);
    $reconciliation = $reports->reconciliations((int) $maker->id, (int) $branch->id, $date);
    $need($trial['debit'] === $trial['credit'], 'Trial Balance debit and credit totals are unequal.');
    $need(count($income['rows']) > 0 && count($balance['rows']) > 0 && count($reconciliation) >= 7
        && collect($reconciliation)->contains('key', 'inventory_transit'),
        'Financial report APIs omitted posted-source income/balance or inventory-transit reconciliation rows: '.json_encode(['income' => count($income['rows']), 'balance' => count($balance['rows']), 'reconciliations' => count($reconciliation), 'date' => $date]));
    $need(collect($reconciliation)->every(fn ($row) => $row['ok']), 'Control reconciliation failed: '.json_encode($reconciliation));

    $period = DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
    $need($period !== null, 'Activated synthetic current period missing.');
    if (getenv('GATE1_FINANCIAL_RISK_CHECK') === '1') {
        require_once __DIR__.'/Gate1FinancialRiskCases.php';
        $gate1FinancialRisk = Gate1FinancialRiskCases::run((int) $maker->id, (int) $branch->id, (int) $period->id, (int) $advanceAccount, $date);
        $evidence['gate1_financial_risk_cases'] = $gate1FinancialRisk;
        $need($gate1FinancialRisk['result'] === 'PASS', 'FIN-P0 risk regression cases did not all pass: '.json_encode($gate1FinancialRisk));
    }
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
    } else {
        $closedFixture = $makeOrderReceipts('1.00000000', 'NEG-CLOSED');
        $closedInvoice = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'PH1-CLOSED-'.strtoupper(Str::random(5)), 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
            'lines' => [['order_line_id' => $closedFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
                'matches' => [['receipt_line_id' => $closedFixture['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]);
        $api->validateSupplierInvoice((int) $maker->id, $closedInvoice, 1);
        $api->matchSupplierInvoice((int) $maker->id, $closedInvoice, 2);
        $api->approveSupplierInvoice((int) $maker->id, $closedInvoice, 3);
        // A valid positive close fixture must carry explicit authority for the complete real company branch set.
        $companyBranchIds = DB::table('branches')->where('company_id', 1)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($companyBranchIds as $companyBranchId) {
            DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $maker->id, 'branch_id' => $companyBranchId,
                'created_at' => now(), 'updated_at' => now()]);
            $grant($maker, ['M11.report_view'], $companyBranchId);
        }
        if (! in_array((int) $branch->id, $companyBranchIds, true) || $companyBranchIds === []) {
            throw new RuntimeException('Synthetic close actor lacks explicit authority for the complete company branch set.');
        }
        $beforeCloseSnapshot = $gate1History ? Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, (string) $period->ends_on) : null;
        $beforeCloseEarlierSnapshot = $gate1History ? Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $priorTransferCutoff) : null;
        $periodCloseError = null;
        try {
            $reports->closePeriod((int) $maker->id, (int) $period->id);
        } catch (ValidationException $error) {
            $periodCloseError = $error->getMessage();
        }
        $need($periodCloseError === null && DB::table('accounting_periods')->where('id', $period->id)->where('status', 'closed')->exists(),
            'Financial period did not close after successful reconciliations: '.(string) $periodCloseError);
        $closureBeforeDeniedPosting = null;
        if ($gate1History) {
            $gate1Chronology['before_closed_snapshot'] = $beforeCloseSnapshot;
            $gate1Chronology['after_closed_snapshot'] = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, (string) $period->ends_on);
            $closureRows = DB::table('accounting_period_closures')->where('period_id', $period->id)->orderBy('id')->get();
            $closureBeforeDeniedPosting = $closureRows->count() === 1 ? $closureRows->first() : null;
            if (! $closureBeforeDeniedPosting || ! hash_equals((string) $closureBeforeDeniedPosting->sha256, hash('sha256', (string) $closureBeforeDeniedPosting->evidence))) {
                throw new RuntimeException('Close did not append exactly one immutable, hash-verifiable period-history snapshot.');
            }
        }
        $expect(fn () => $api->postSupplierInvoice((int) $maker->id, $closedInvoice, 4), 'closed accounting period');
        $need(DB::table('rp_supplier_invoices')->where('id', $closedInvoice)->where('status', 'approved')->exists()
            && ! DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $closedInvoice)->exists()
            && ! DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$closedInvoice)->exists(), 'Closed-period rejection left partial AP/GL posting effects.');
        if ($gate1History) {
            $afterClosedDenial = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, (string) $period->ends_on);
            Gate1FinancialHistory::sameCutoff($gate1Chronology['before_closed_snapshot'], $afterClosedDenial, 'Closed-period backdated posting denial');
            $afterClosedEarlierSnapshot = Gate1FinancialHistory::snapshot((int) $maker->id, (int) $branch->id, $priorTransferCutoff);
            Gate1FinancialHistory::sameCutoff($beforeCloseEarlierSnapshot, $afterClosedEarlierSnapshot, 'Earlier cutoff after later-dated activity and period close');
            $closureAfterDeniedPosting = DB::table('accounting_period_closures')->where('period_id', $period->id)->orderBy('id')->get();
            if ($closureAfterDeniedPosting->count() !== 1
                || (int) $closureAfterDeniedPosting->first()->id !== (int) $closureBeforeDeniedPosting->id
                || ! hash_equals((string) $closureAfterDeniedPosting->first()->sha256, (string) $closureBeforeDeniedPosting->sha256)) {
                throw new RuntimeException('Rejected backdated posting changed or duplicated immutable period-close history.');
            }
            $gate1Chronology['after_closed_snapshot'] = $afterClosedDenial;
            $gate1Chronology['closed_snapshot_stable'] = ['closed_period_end_unchanged' => true,
                'earlier_cutoff_unchanged' => true, 'later_activity_date' => $date];
            $gate1Chronology['closed_period_backdated_post_denied'] = ['status' => 'rejected', 'draft_invoice_id' => (int) $closedInvoice, 'no_partial_post' => true];
            $gate1Chronology['close_history_after_denial'] = ['rows_before' => 1, 'rows_after' => $closureAfterDeniedPosting->count(),
                'same_closure_id' => true, 'hash_verified' => true];
            $gate1Chronology['company_close_authority'] = ['company_branch_count' => count($companyBranchIds), 'all_explicitly_scoped' => true];
            $gate1Passed['closed_snapshot_stable'] = true;
            $gate1Passed['closed_period_backdated_post_denied'] = true;
            $gate1Passed['close_history_after_denial'] = true;
            $gate1Passed['company_close_authority'] = true;
        }
    }

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
        'customer' => ['sales_order_id' => $saleOrder->id, 'delivery_id' => $delivery, 'invoice_id' => $customerInvoice, 'advance_movement_id' => $customerAdvance['movement_id'],
            'collection_movement_ids' => [$collectionOne['movement_id'], $collectionTwo['movement_id']], 'remaining_ar' => $arRemaining],
        'party_financial_profiles' => ['actor_id' => (int) $maker->id, 'branch_id' => (int) $branch->id, 'supplier_id' => (int) $supplier->id, 'customer_id' => (int) $customer->id,
            'aging_read_clock' => '100 days after actual action dates',
            'supplier' => ['invoice_count' => $supplierProfile['invoices']['total'], 'settled' => $supplierProfile['summary']['allocated_settlements'],
                'outstanding' => $supplierProfile['summary']['outstanding'], 'overdue' => $supplierProfile['summary']['overdue'],
                'unapplied_credit' => $supplierProfile['summary']['unapplied_credits'], 'statement_closing' => $supplierProfile['statement']['closing']],
            'customer' => ['invoice_count' => $customerProfile['invoices']['total'], 'settled' => $customerProfile['summary']['allocated_settlements'],
                'outstanding' => $customerProfile['summary']['outstanding'], 'advance' => $customerProfile['summary']['customer_advances'],
                'unapplied_credit' => $customerProfile['summary']['unapplied_credits'], 'statement_closing' => $customerProfile['statement']['closing']],
            'unmapped_opening_payables' => ['supplier' => $supplierProfile['summary']['unmapped_opening_payables'], 'customer' => $customerProfile['summary']['unmapped_opening_payables']],
            'no_ar_ap_scope' => 'authorized_false', 'foreign_branch_filter' => '403'],
        'credit_notes' => ['supplier' => $supplierCreditPost, 'customer' => $customerCreditPost, 'party_credit_rows' => $partyCredits->count()],
        'direct_transfer' => $transfer, 'reports' => ['trial_balance_debit' => $trial['debit'], 'trial_balance_credit' => $trial['credit'],
            'income_row_count' => count($income['rows']), 'balance_row_count' => count($balance['rows']), 'reconciliations' => $reconciliation],
        'negative_cases' => ['duplicate_supplier_number' => 'rejected', 'supplier_mismatch' => 'rejected', 'overmatch' => 'rejected',
            'unauthorized' => '403', 'closed_period' => $commitRaceFixture ? 'deferred_to_serial_run' : 'rejected_without_partial_post'],
        'committed_worker_fixtures' => $raceFixture, 'gate1_financial_risk_cases' => $gate1FinancialRisk, 'all_pass' => true];
} catch (Throwable $error) {
    $evidence = ['database' => $connection->db, 'postgres_port' => (int) $connection->port, 'all_pass' => false,
        'error_class' => $error::class, 'error' => mb_substr($error->getMessage(), 0, 1500), 'trace' => array_slice($error->getTrace(), 0, 8)];
    throw $error;
} finally {
    if ($db->transactionLevel() > 0) {
        if ($commitRaceFixture && ($evidence['all_pass'] ?? false)) {
            DB::commit();
        } else {
            DB::rollBack();
        }
    }
    Carbon::setTestNow();
    if ($gate1History) {
        File::deleteDirectory($storage);
    }
    if (getenv('GATE1_CI_RUN_ID')) {
        $evidence['ci_run_id'] = getenv('GATE1_CI_RUN_ID');
    }
    $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    $evidencePath = $gate1History
        ? (($gate1CiRunId = (getenv('GATE1_CI_RUN_ID') ?: '')) !== ''
            ? $root.'/.tmp/ci-postgres/ar-ap-treasury-run-'.$gate1CiRunId.'.json'
            : $root.'/docs/release/remediation/evidence/gate1/historical/ar-ap-treasury-run.json')
        : ((($phase1CiRunId = (getenv('GATE1_CI_RUN_ID') ?: '')) !== '')
            ? $root.'/.tmp/ci-postgres/phase1-financial-journeys-'.$phase1CiRunId.'.json'
            : $root.'/docs/qa/evidence/phase1-financial-journeys.json');
    if (! is_dir(dirname($evidencePath))) {
        mkdir(dirname($evidencePath), 0770, true);
    }
    file_put_contents($evidencePath, $json, LOCK_EX);
    if (getenv('GATE1_FINANCIAL_RISK_CHECK') === '1') {
        $riskReceipt = $gate1FinancialRisk ?? ['result' => 'INCOMPLETE', 'assertions' => 0, 'findings' => []];
        $riskJson = json_encode(['ci_run_id' => getenv('GATE1_CI_RUN_ID') ?: null,
            'database' => $connection->db, 'postgres_port' => (int) $connection->port,
            'rollback_only' => ! $commitRaceFixture, ...$riskReceipt], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        $riskPath = (($riskCiRunId = (getenv('GATE1_CI_RUN_ID') ?: '')) !== '')
            ? $root.'/.tmp/ci-postgres/financial-risk-cases-'.$riskCiRunId.'.json'
            : $root.'/docs/release/remediation/evidence/gate1/financial-risk-cases.json';
        if (! is_dir(dirname($riskPath))) {
            mkdir(dirname($riskPath), 0770, true);
        }
        file_put_contents($riskPath, $riskJson, LOCK_EX);
        echo $riskJson;
    }
    if ($gate1History) {
        $required = ['before_first_activity', 'after_receipt', 'after_grni_clearing', 'after_partial_ar_settlement', 'after_advance',
            'after_partial_ap_settlement', 'after_supplier_payment', 'after_credit_notes', 'later_dated_ap',
            'earlier_payment_cutoff_stable_after_credit', 'earlier_credit_cutoff_stable_after_later_ap', 'later_treasury_activity_stability', 'closed_snapshot_stable', 'closed_period_backdated_post_denied', 'close_history_after_denial', 'company_close_authority'];
        $complete = ! array_diff($required, array_keys($gate1Passed));
        Gate1FinancialHistory::writeEvidence($root, 'ar-ap-treasury-chronology.json', [
            'database_identity' => $gate1Identity,
            'scenarios' => $gate1Chronology,
            'scenario_status' => array_combine($required, array_map(static fn ($key): string => array_key_exists($key, $gate1Passed) ? 'PASS' : 'NOT TESTED', $required)),
            'all_required_scenarios_pass' => ($evidence['all_pass'] ?? false) && $complete,
            'limits' => ['later activity is source-driven and in the same disposable open accounting year before close; no successful back-date is allowed after closure.',
                'No distinct workshop/maintenance unused-part return lifecycle is exercised by this AP/AR/Treasury fixture.'],
        ]);
    }
    echo $json;
    if (getenv('GATE1_FINANCIAL_RISK_CHECK') === '1') {
        echo 'FIN-P0 RESULT '.$riskReceipt['result'].' assertions='.$riskReceipt['assertions'].PHP_EOL;
    }
}

<?php

// Temporary rollback-only V1 financial acceptance probe. Never point at any DB except erp_elec_new_test.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingService;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryTransfers;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrderProjection;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\PurchasingAccess;
use App\Modules\Purchasing\Domain\ProcurementChanged;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Sales\Application\ConvertAcceptedQuotation;
use App\Modules\Sales\Application\QuotationActions;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Application\QuotationIssuance;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesOrderProgress;
use App\Modules\Sales\Domain\QuotationTerm;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
putenv('DB_HOST=127.0.0.1');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
putenv('DB_PORT=55432');
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = '55432';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$auditRoot = sys_get_temp_dir().'/erp-financial-audit-'.getmypid();
$GLOBALS['phase0_audit_root'] = $auditRoot;
foreach (['app', 'framework/cache', 'framework/sessions', 'framework/views', 'logs', 'app/private/mpdf'] as $dir) {
    if (! is_dir($auditRoot.'/'.$dir) && ! mkdir($auditRoot.'/'.$dir, 0700, true) && ! is_dir($auditRoot.'/'.$dir)) {
        throw new RuntimeException('Cannot create private audit temp path.');
    }
}
$app->useStoragePath($auditRoot);
putenv('TMPDIR='.$auditRoot);
putenv('TEMP='.$auditRoot);
putenv('TMP='.$auditRoot);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test'
    || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '55432'
    || config('database.connections.pgsql.url')) {
    throw new RuntimeException('ABORT before writes: exact PostgreSQL testing DB not configured.');
}
$actual = $db->selectOne("select current_database() as db, current_setting('server_version_num') as version, inet_server_addr()::text as address, inet_server_port() as port");
if (($actual->db ?? null) !== 'erp_elec_new_test' || $actual->address !== '127.0.0.1' || (string) $actual->port !== '55432') {
    throw new RuntimeException('ABORT before writes: current_database() mismatch.');
}

$results = [];
$seq = 0;
$ctx = [];
$record = static function (string $name, callable $fn, array $requires = [], bool $rollbackOnSuccess = false) use (&$results, &$seq, &$ctx): mixed {
    foreach ($requires as $dependency) {
        $state = $results[$dependency]['status'] ?? 'MISSING';
        if ($state !== 'PASS') {
            $results[$name] = ['status' => 'BLOCKED', 'reason' => 'Prerequisite '.$dependency.' is '.$state];

            return null;
        }
    }
    $sp = 'financial_probe_'.(++$seq);
    DB::statement('SAVEPOINT '.$sp);
    try {
        $value = $fn();
        if ($rollbackOnSuccess) {
            DB::statement('ROLLBACK TO SAVEPOINT '.$sp);
        } DB::statement('RELEASE SAVEPOINT '.$sp);
        $results[$name] = ['status' => 'PASS', 'evidence' => is_array($value) ? $value : (is_scalar($value) ? $value : null)];

        return $value;
    } catch (Throwable $e) {
        try {
            DB::statement('ROLLBACK TO SAVEPOINT '.$sp);
            DB::statement('RELEASE SAVEPOINT '.$sp);
        } catch (Throwable) {
        }
        $results[$name] = ['status' => 'FAIL', 'stage' => $ctx['detail_stage'] ?? null, 'error' => $e::class.': '.mb_substr($e->getMessage(), 0, 650)];

        return null;
    }
};
$need = static function (bool $ok, string $why): void {
    if (! $ok) {
        throw new RuntimeException($why);
    }
};
$expect = static function (callable $fn, string $why, string $type = 'validation'): void {
    try {
        $fn();
    } catch (Throwable $e) {
        $valid = match ($type) {
            'validation' => $e instanceof ValidationException,
            'forbidden' => $e instanceof HttpExceptionInterface && $e->getStatusCode() === 403,
            'conflict' => $e instanceof HttpExceptionInterface && $e->getStatusCode() === 409,
            'sentinel' => $e instanceof RuntimeException && $e->getMessage() === 'FINANCIAL_AUDIT_INJECTED_AFTER_RECEIPT_POSTED',
            default => false,
        };
        if ($valid) {
            return;
        }
        throw new RuntimeException('Expected '.$type.' denial for '.$why.'; got '.$e::class.' '.$e->getMessage(), 0, $e);
    }
    throw new RuntimeException('Expected denial missing: '.$why);
};
$grant = static function (User $u, array $codes, ?int $branch = null): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $u->id, 'permission_code' => $code, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn () => (string) Str::uuid();
$db->beginTransaction();
try {
    $record('synthetic actors and masters', function () use (&$ctx, $grant) {
        $company = DB::table('companies')->find(1);
        if (! $company) {
            DB::table('companies')->insert(['id' => 1, 'code' => 'FIN-AUDIT', 'name' => 'Synthetic financial audit', 'legal_name' => 'Synthetic Financial Audit Company', 'tax_registration' => 'AUDIT-0001', 'address' => 'Synthetic Cairo address', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        } elseif (! $company->setup_complete || ! $company->legal_name || ! $company->address) {
            if ($company->code !== 'E2E-SO-COMPANY') {
                throw new RuntimeException('BLOCKER: existing company is not the approved synthetic E2E-SO fixture; preserving owner data.');
            }
            $fix = [];
            if (! $company->legal_name) {
                $fix['legal_name'] = 'Synthetic Financial Audit Company';
            }
            if (! $company->address) {
                $fix['address'] = 'Synthetic Cairo address';
            }
            if (! $company->setup_complete) {
                $fix['setup_complete'] = true;
            }
            if ($fix) {
                DB::table('companies')->where('id', 1)->update($fix);
            }
        }
        $branch = Branch::factory()->create(['code' => 'FIN-AUDIT-'.strtoupper(Str::random(6))]);
        $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $unauthorized = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        foreach ([$maker, $reviewer] as $u) {
            DB::table('user_branch_scopes')->insert(['user_id' => $u->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $common = ['M01.branch_view', 'M02.product_view', 'M03.supplier_view', 'M06.warehouse_view', 'M06.warehouse_create', 'M06.balance_view', 'M06.receipt_create', 'M06.receipt_post', 'M06.movement_view', 'M06.transfer_create', 'M06.transfer_approve', 'M06.transfer_dispatch', 'M06.transfer_receive', 'M06.adjustment_create', 'M06.adjustment_approve', 'M06.adjustment_post', 'M06.count_create', 'M06.count_submit', 'M06.count_approve'];
        $makerCodes = array_merge($common, ['M06.issue_create', 'M06.issue_post', 'M06.issue_approve', 'M11.setup_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.opening_manage', 'M11.opening_entry', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.tax_calculate', 'M11.receipt_post', 'M11.journal_post', 'M05.order_view', 'M05.order_create', 'M05.order_update', 'M05.order_submit', 'M05.order_approve', 'M05.receipt_view', 'M05.receipt_create', 'M05.receipt_post', 'M04.sales_order_submit']);
        $reviewCodes = array_merge($common, ['M06.issue_approve', 'M06.issue_create', 'M06.issue_post', 'M06.reservation_manage', 'M06.reservation_view', 'M11.setup_view', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.tax_calculate', 'M11.receipt_post', 'M11.journal_post', 'M05.order_view', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view', 'M05.receipt_post', 'M04.sales_order_view', 'M04.sales_order_approve', 'M04.sales_order_delivery_create', 'M04.sales_order_delivery_confirm']);
        $grant($maker, $makerCodes, $branch->id);
        $grant($reviewer, $reviewCodes, $branch->id);
        $grant($maker, ['M11.setup_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.opening_manage', 'M11.opening_approve', 'M11.financial_activate', 'M11.tax_calculate']);
        $grant($reviewer, ['M11.setup_view', 'M11.opening_approve', 'M11.financial_activate', 'M11.tax_calculate']);
        foreach ([$maker, $reviewer] as $u) {
            $grant($u, ['M01.branch_view', 'M01.company_view', 'M02.product_view', 'M02.price_view', 'M03.customer_view', 'M03.supplier_view', 'M06.warehouse_view', 'M06.warehouse_create']);
        }
        $grant($maker, ['M02.price_manage', 'M04.quotation_view', 'M04.quotation_create', 'M04.quotation_policy_manage', 'M04.quotation_issue', 'M04.quotation_accept', 'M04.quotation_document_view', 'M04.quotation_convert']);
        $grant($reviewer, ['M02.price_approve', 'M04.quotation_view', 'M04.quotation_document_view']);
        $grant($maker, ['M04.sales_order_view', 'M04.sales_order_create', 'M04.sales_order_edit', 'M04.sales_order_convert'], $branch->id);
        $warehouse = app(InventoryActions::class)->saveWarehouse($maker->id, ['code' => 'FIN-AUDIT-WH-'.strtoupper(Str::random(4)), 'name' => 'Synthetic audit warehouse', 'branch_id' => $branch->id, 'active' => true]);
        foreach ([$maker, $reviewer] as $u) {
            DB::table('user_warehouse_scopes')->insert(['user_id' => $u->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
        $supplier = Supplier::factory()->create(['status' => 'active']);
        $ctx = ['maker' => $maker->id, 'reviewer' => $reviewer->id, 'unauthorized' => $unauthorized->id, 'branch' => $branch->id, 'warehouse' => $warehouse['id'], 'product' => $product->id, 'supplier' => $supplier->id];

        return ['branch_id' => $branch->id, 'warehouse_id' => $warehouse['id'], 'product_id' => $product->id, 'supplier_id' => $supplier->id, 'actor_count' => 3, 'only_synthetic' => true];
    });

    $record('real financial setup and dated synthetic tax rules', function () use (&$ctx, $need) {
        $maps = [];
        foreach (FinancialActivation::MAPPINGS as $purpose => [$label,$kind]) {
            $maps[$purpose] = AccountingAccount::factory()->create(['code' => 'FA-'.strtoupper($purpose).'-'.Str::random(5), 'name' => 'Synthetic '.$purpose, 'kind' => $kind])->id;
        }
        $equity = AccountingAccount::factory()->create(['code' => 'FA-EQUITY-'.Str::random(5), 'name' => 'Synthetic opening equity', 'kind' => 'equity'])->id;
        DB::table('accounting_periods')->insert(['name' => 'Synthetic audit period 2024', 'starts_on' => '2024-01-01', 'ends_on' => '2024-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounting_periods')->insert(['name' => 'Synthetic audit period 2025-2035', 'starts_on' => '2025-01-01', 'ends_on' => '2035-12-31', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $setup = app(FinancialActivation::class);
        $v = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
        $setup->save($ctx['maker'], $v, ['go_live_date' => '2024-06-01', 'mappings' => $maps]);
        $v++;
        foreach (['sale', 'purchase'] as $type) {
            $data = ['code' => 'FA-'.strtoupper($type).'-'.Str::random(5), 'name' => 'Synthetic acceptance tax rule', 'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => '2024-06-01', 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic audit fixture reference 2024'];
            if ($type === 'purchase') {
                $data['purchase_recoverable'] = true;
            } $setup->addTaxRule($ctx['maker'], $v, $data);
            $v++;
        }
        $setup->validateTaxTreatment($ctx['maker'], $v, 'Synthetic audit fixture validated rule set');
        $v++;
        $ctx['config_version'] = $v;
        $ctx['maps'] = $maps;
        $ctx['equity_account'] = $equity;
        $need(count($maps) === count(FinancialActivation::MAPPINGS), 'Incomplete financial mappings.');

        return ['configuration_version' => $v, 'mapping_count' => count($maps), 'tax_rules_added' => 2, 'synthetic_go_live' => '2024-06-01'];
    }, ['synthetic actors and masters']);

    $record('detailed GL AP cash bank FIFO opening and internal FIFO issue', function () use (&$ctx, $uuid, $need, $expect) {
        $ctx['detail_stage'] = 'opening save/normalize';
        $maps = $ctx['maps'];
        $branch = $ctx['branch'];
        $supplier = $ctx['supplier'];
        $warehouse = $ctx['warehouse'];
        $product = $ctx['product'];
        $lines = [
            ['kind' => 'gl', 'reference' => 'GL-CASH', 'branch_id' => $branch, 'account_id' => $maps['cash'], 'debit' => '10.00', 'credit' => '0.00'],
            ['kind' => 'gl', 'reference' => 'GL-BANK', 'branch_id' => $branch, 'account_id' => $maps['bank'], 'debit' => '5.00', 'credit' => '0.00'],
            ['kind' => 'gl', 'reference' => 'GL-INVENTORY', 'branch_id' => $branch, 'account_id' => $maps['inventory'], 'debit' => '20.00', 'credit' => '0.00'],
            ['kind' => 'gl', 'reference' => 'GL-AP', 'branch_id' => $branch, 'account_id' => $maps['ap'], 'debit' => '0.00', 'credit' => '10.00'],
            ['kind' => 'gl', 'reference' => 'GL-EQUITY', 'branch_id' => $branch, 'account_id' => $ctx['equity_account'], 'debit' => '0.00', 'credit' => '25.00'],
            ['kind' => 'cash', 'reference' => 'CASH-OPEN', 'branch_id' => $branch, 'amount' => '10.00'],
            ['kind' => 'bank', 'reference' => 'BANK-OPEN', 'branch_id' => $branch, 'amount' => '5.00'],
            ['kind' => 'ap', 'reference' => 'AP-OPEN', 'branch_id' => $branch, 'supplier_id' => $supplier, 'amount' => '10.00'],
            ['kind' => 'inventory', 'reference' => 'FIFO-OPEN', 'branch_id' => $branch, 'warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => '2.00000000', 'unit_cost' => '10.0000000000000000', 'amount' => '20.00'],
        ];
        $opening = app(OpeningBalances::class);
        $batch = $opening->save($ctx['maker'], $ctx['config_version'], $uuid(), $lines, false, 'Synthetic reconciled detailed opening balances');
        $ctx['detail_stage'] = 'opening submit';
        $opening->advance($ctx['maker'], $batch, 1, 'submit');
        $ctx['detail_stage'] = 'self-approval negative check';
        $expect(fn () => $opening->advance($ctx['maker'], $batch, 2, 'approve'), 'opening preparer self-approval');
        $ctx['detail_stage'] = 'independent opening approval';
        $opening->advance($ctx['reviewer'], $batch, 2, 'approve');
        $ctx['detail_stage'] = 'root activation and opening contributions';
        app(ActivateFinancialPosting::class)->execute($ctx['reviewer'], $batch, 3);
        $need(DB::table('accounting_journals')->where('opening_batch_id', $batch)->where('status', 'posted')->exists(), 'Detailed opening did not create actual GL posting.');
        $payable = DB::table('payable_opening_items')->where('source_key', 'opening:'.$batch.':AP-OPEN')->first();
        $cash = DB::table('treasury_opening_balances')->where('source_key', 'opening:'.$batch.':cash:CASH-OPEN')->first();
        $bank = DB::table('treasury_opening_balances')->where('source_key', 'opening:'.$batch.':bank:BANK-OPEN')->first();
        $openingLayer = DB::table('inventory_fifo_layers')->where('source_key', 'opening:'.$batch.':FIFO-OPEN')->first();
        $need($payable && $payable->amount === '10.00' && $cash && $cash->amount === '10.00' && $bank && $bank->amount === '5.00', 'Opening AP/cash/bank contributions mismatch.');
        $need($openingLayer && $openingLayer->original_quantity === '2.00000000' && $openingLayer->original_value === '20.00', 'Opening FIFO layer mismatch.');
        $ctx['detail_stage'] = 'internal issue save';
        $docs = app(InventoryDocuments::class);
        $doc = $docs->save($ctx['maker'], 'issue', ['creation_key' => $uuid(), 'warehouse_id' => $warehouse, 'reason' => 'Synthetic FIFO costing acceptance issue', 'lines' => [['product_id' => $product, 'quantity' => '1.50000000']]]);
        $ctx['detail_stage'] = 'internal issue submit';
        $doc = $docs->transition($ctx['maker'], $doc['id'], 'submit', $doc['version']);
        $ctx['detail_stage'] = 'internal issue self-approval negative check';
        $expect(fn () => $docs->transition($ctx['maker'], $doc['id'], 'approve', $doc['version']), 'inventory document creator self-approval');
        $ctx['detail_stage'] = 'internal issue independent approval';
        $doc = $docs->transition($ctx['reviewer'], $doc['id'], 'approve', $doc['version']);
        $ctx['detail_stage'] = 'internal issue FIFO and GL posting';
        $doc = $docs->transition($ctx['maker'], $doc['id'], 'post', $doc['version']);
        $ctx['detail_stage'] = 'internal issue assertions';
        $movement = DB::table('inventory_movements')->where('document_id', $doc['id'])->where('operation', 'issue_post')->first();
        $allocation = DB::table('inventory_fifo_allocations')->where('movement_id', $movement?->id)->first();
        $costing = DB::table('inventory_movement_journals')->where('movement_id', $movement?->id)->first();
        $journal = $costing ? DB::table('accounting_journals')->find($costing->journal_id) : null;
        $need($doc['status'] === 'posted' && $movement && $movement->quantity === '-1.50000000', 'Internal issue did not create the real negative movement.');
        $need($allocation && $allocation->quantity === '1.50000000' && $allocation->amount === '15.00', 'Internal issue FIFO allocation should cost 15.00.');
        $need($journal && $journal->status === 'posted' && $journal->source_key === 'inventory-movement:'.$movement->id, 'Internal issue costing journal missing.');
        $jlines = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->get();
        $dr = '0.00';
        $cr = '0.00';
        foreach ($jlines as $jline) {
            $dr = bcadd($dr, (string) $jline->debit, 2);
            $cr = bcadd($cr, (string) $jline->credit, 2);
        }
        $need($jlines->count() === 2 && $dr === '15.00' && $cr === '15.00', 'Internal issue GL not balanced at FIFO cost 15.00.');
        $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse)->where('product_id', $product)->value('on_hand');
        $need($balance === '0.50000000', 'Internal issue remaining stock should be 0.5.');

        $ctx['detail_stage'] = 'valued positive adjustment';
        $adjustmentData = ['creation_key' => $uuid(), 'warehouse_id' => $warehouse, 'reason' => 'Synthetic priced inventory surplus for transfer valuation', 'lines' => [['product_id' => $product, 'quantity' => '3.00000000', 'unit_cost' => '4.0000000000000000', 'cost_reference' => 'Synthetic valuation evidence INV-AUDIT-01']]];
        $adjustment = $docs->save($ctx['maker'], 'adjustment', $adjustmentData);
        $adjustment = $docs->transition($ctx['maker'], $adjustment['id'], 'submit', $adjustment['version']);
        $adjustment = $docs->transition($ctx['reviewer'], $adjustment['id'], 'approve', $adjustment['version']);
        $adjustment = $docs->transition($ctx['maker'], $adjustment['id'], 'post', $adjustment['version']);
        $adjustmentLayer = DB::table('inventory_fifo_layers')->where('source_key', 'document:'.$adjustment['id'].':post:'.DB::table('inventory_document_lines')->where('document_id', $adjustment['id'])->value('id'))->first();
        $need($adjustment['status'] === 'posted' && $adjustmentLayer && $adjustmentLayer->original_quantity === '3.00000000' && $adjustmentLayer->original_value === '12.00', 'Priced surplus adjustment did not create its exact FIFO layer.');
        $expect(fn () => $docs->save($ctx['maker'], 'adjustment', array_merge($adjustmentData, ['lines' => [['product_id' => $product, 'quantity' => '3.00000000', 'unit_cost' => '5.0000000000000000', 'cost_reference' => 'Changed synthetic valuation']]])), 'adjustment idempotency key with changed valuation');
        $expect(fn () => $docs->save($ctx['maker'], 'adjustment', $adjustmentData, $adjustment['id'], $adjustment['version']), 'posted adjustment cost edit');

        $ctx['detail_stage'] = 'synthetic transfer destinations and explicit scopes';
        $sameDestination = app(InventoryActions::class)->saveWarehouse($ctx['maker'], ['code' => 'FIN-AUDIT-TRANSFER-SAME-'.strtoupper(Str::random(4)), 'name' => 'Synthetic same branch transfer destination', 'branch_id' => $branch, 'active' => true]);
        $otherBranch = Branch::factory()->create(['code' => 'FIN-AUDIT-XBR-'.strtoupper(Str::random(5))]);
        foreach ([$ctx['maker'], $ctx['reviewer'], $ctx['unauthorized']] as $actorId) {
            DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actorId, 'branch_id' => $otherBranch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('access_grants')->insertOrIgnore(['user_id' => $ctx['maker'], 'permission_code' => 'M01.branch_view', 'branch_id' => $otherBranch->id, 'created_at' => now(), 'updated_at' => now()]);
        $crossDestination = app(InventoryActions::class)->saveWarehouse($ctx['maker'], ['code' => 'FIN-AUDIT-TRANSFER-CROSS-'.strtoupper(Str::random(4)), 'name' => 'Synthetic cross branch transfer destination', 'branch_id' => $otherBranch->id, 'active' => true]);
        foreach ([$ctx['maker'], $ctx['reviewer']] as $actorId) {
            foreach ([$sameDestination['id'], $crossDestination['id']] as $warehouseId) {
                $branchId = DB::table('inventory_warehouses')->where('id', $warehouseId)->value('branch_id');
                DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $actorId, 'warehouse_id' => $warehouseId, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (['M06.transfer_create', 'M06.transfer_approve', 'M06.transfer_dispatch', 'M06.transfer_receive', 'M06.adjustment_create', 'M06.adjustment_approve', 'M06.adjustment_post', 'M06.count_create', 'M06.count_submit', 'M06.count_approve', 'M11.journal_post'] as $permission) {
                foreach ([$branch, $otherBranch->id] as $branchId) {
                    DB::table('access_grants')->insertOrIgnore(['user_id' => $actorId, 'permission_code' => $permission, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id' => $ctx['unauthorized'], 'warehouse_id' => $warehouse, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $ctx['unauthorized'], 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insertOrIgnore(['user_id' => $ctx['unauthorized'], 'permission_code' => 'M06.transfer_create', 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insertOrIgnore(['user_id' => $ctx['unauthorized'], 'permission_code' => 'M06.transfer_create', 'branch_id' => $otherBranch->id, 'created_at' => now(), 'updated_at' => now()]);
        $transferBase = ['creation_key' => $uuid(), 'warehouse_id' => $warehouse, 'destination_id' => $sameDestination['id'], 'reason' => 'Synthetic valued transfer audit', 'lines' => [['product_id' => $product, 'quantity' => '1.00000000']]];
        $expect(fn () => $docs->save($ctx['unauthorized'], 'transfer', $transferBase), 'actor without transfer create permission', 'forbidden');
        $limitedTransfer = $transferBase;
        $limitedTransfer['creation_key'] = $uuid();
        $limitedTransfer['destination_id'] = $crossDestination['id'];
        $expect(fn () => $docs->save($ctx['unauthorized'], 'transfer', $limitedTransfer), 'actor scoped only to transfer source warehouse', 'forbidden');

        $transferFlow = function (int $destination, string $quantity, string $first, string $last, string $label) use (&$ctx, $docs, $uuid, $need): array {
            $data = ['creation_key' => $uuid(), 'warehouse_id' => $ctx['warehouse'], 'destination_id' => $destination, 'reason' => 'Synthetic '.$label.' transfer valuation', 'lines' => [['product_id' => $ctx['product'], 'quantity' => $quantity]]];
            $doc = $docs->save($ctx['maker'], 'transfer', $data);
            $doc = $docs->transition($ctx['maker'], $doc['id'], 'submit', $doc['version']);
            $doc = $docs->transition($ctx['reviewer'], $doc['id'], 'approve', $doc['version']);
            $doc = $docs->transition($ctx['maker'], $doc['id'], 'dispatch', $doc['version']);
            $line = DB::table('inventory_document_lines')->where('document_id', $doc['id'])->first();
            $transit = DB::table('inventory_transfer_lots')->where('document_id', $doc['id'])->get();
            $transitValue = '0.00';
            foreach ($transit as $lot) {
                $transitValue = bcadd($transitValue, (string) $lot->original_value, 2);
            }
            $need($doc['status'] === 'dispatched' && $transit->isNotEmpty() && bccomp($transitValue, '0.00', 2) > 0, 'Dispatch did not create valued transit FIFO lots.');
            $receipts = app(InventoryTransfers::class);
            $firstPayload = ['idempotency_key' => $uuid(), 'reason' => 'Synthetic '.$label.' partial receipt', 'lines' => [['line_id' => $line->id, 'accepted' => $first, 'damaged' => '0', 'missing' => '0', 'recovered_missing' => '0']]];
            $afterFirst = $receipts->receive($ctx['reviewer'], $doc['id'], $firstPayload);
            $countAfterFirst = DB::table('inventory_transfer_allocations as a')->join('inventory_transfer_lots as l', 'l.id', '=', 'a.lot_id')->where('l.document_id', $doc['id'])->count();
            $receipts->receive($ctx['reviewer'], $doc['id'], $firstPayload);
            $need(DB::table('inventory_transfer_allocations as a')->join('inventory_transfer_lots as l', 'l.id', '=', 'a.lot_id')->where('l.document_id', $doc['id'])->count() === $countAfterFirst, 'Identical partial transfer retry duplicated FIFO allocations.');
            $remaining = bcsub($quantity, $first, 8);
            $finalPayload = ['idempotency_key' => $uuid(), 'reason' => 'Synthetic '.$label.' final receipt', 'lines' => [['line_id' => $line->id, 'accepted' => $remaining, 'damaged' => '0', 'missing' => '0', 'recovered_missing' => '0']]];
            $received = $receipts->receive($ctx['reviewer'], $doc['id'], $finalPayload);
            $afterValue = '0.00';
            foreach (DB::table('inventory_transfer_allocations as a')->join('inventory_transfer_lots as l', 'l.id', '=', 'a.lot_id')->where('l.document_id', $doc['id'])->get(['a.amount']) as $allocation) {
                $afterValue = bcadd($afterValue, (string) $allocation->amount, 2);
            }
            $movements = DB::table('inventory_movements')->where('document_id', $doc['id'])->whereIn('operation', ['transfer_dispatch', 'transfer_receive'])->count();
            $journals = DB::table('inventory_movement_journals as j')->join('inventory_movements as m', 'm.id', '=', 'j.movement_id')->where('m.document_id', $doc['id'])->count();
            $need($received['status'] === 'received' && $movements >= 2 && $journals === $movements && bccomp($afterValue, $transitValue, 2) === 0, 'Transfer did not fully reconcile dispatch/receipt movements, FIFO value and GL journals.');

            return ['document_id' => $doc['id'], 'destination_id' => $destination, 'quantity' => $quantity, 'transit_value' => $transitValue, 'received_fifo_value' => $afterValue, 'partial_retry_idempotent' => true, 'movement_count' => $movements, 'journal_count' => $journals];
        };
        $sameTransfer = $transferFlow($sameDestination['id'], '2.00000000', '0.75000000', '1.25000000', 'same branch');
        $crossTransfer = $transferFlow($crossDestination['id'], '1.00000000', '0.40000000', '0.60000000', 'cross branch');

        $ctx['detail_stage'] = 'positive count variance adjustment';
        $count = $docs->save($ctx['maker'], 'count', ['creation_key' => $uuid(), 'warehouse_id' => $sameDestination['id'], 'reason' => 'Synthetic count surplus with valuation', 'lines' => [['product_id' => $product, 'quantity' => '3.00000000']]]);
        $count = $docs->transition($ctx['maker'], $count['id'], 'submit', $count['version']);
        $count = $docs->transition($ctx['reviewer'], $count['id'], 'approve', $count['version']);
        $surplus = DB::table('inventory_documents')->find($count['linked_document_id']);
        $surplusData = ['creation_key' => $surplus->creation_key, 'warehouse_id' => $sameDestination['id'], 'reason' => $surplus->reason, 'lines' => [['product_id' => $product, 'quantity' => '1.00000000', 'unit_cost' => '4.0000000000000000', 'cost_reference' => 'Synthetic count surplus valuation']]];
        $surplus = $docs->save($ctx['reviewer'], 'adjustment', $surplusData, $surplus->id, $surplus->version);
        $surplus = $docs->transition($ctx['reviewer'], $surplus['id'], 'submit', $surplus['version']);
        $surplus = $docs->transition($ctx['maker'], $surplus['id'], 'approve', $surplus['version']);
        $surplus = $docs->transition($ctx['reviewer'], $surplus['id'], 'post', $surplus['version']);
        $positiveCost = DB::table('inventory_movement_journals as j')->join('inventory_movements as m', 'm.id', '=', 'j.movement_id')->where('m.document_id', $surplus['id'])->value('j.amount');
        $need($count['status'] === 'posted' && $surplus['status'] === 'posted' && $positiveCost === '4.00', 'Count surplus did not produce a valued positive adjustment of 4.00.');

        $ctx['detail_stage'] = 'negative count variance adjustment';
        $negativeCount = $docs->save($ctx['maker'], 'count', ['creation_key' => $uuid(), 'warehouse_id' => $crossDestination['id'], 'reason' => 'Synthetic count shortage', 'lines' => [['product_id' => $product, 'quantity' => '0.75000000']]]);
        $negativeCount = $docs->transition($ctx['maker'], $negativeCount['id'], 'submit', $negativeCount['version']);
        $negativeCount = $docs->transition($ctx['reviewer'], $negativeCount['id'], 'approve', $negativeCount['version']);
        $shortage = DB::table('inventory_documents')->find($negativeCount['linked_document_id']);
        $shortage = $docs->transition($ctx['reviewer'], $shortage->id, 'submit', $shortage->version);
        $shortage = $docs->transition($ctx['maker'], $shortage['id'], 'approve', $shortage['version']);
        $shortage = $docs->transition($ctx['reviewer'], $shortage['id'], 'post', $shortage['version']);
        $negativeMovement = DB::table('inventory_movements')->where('document_id', $shortage['id'])->where('operation', 'adjustment_post')->first();
        $negativeAllocation = DB::table('inventory_fifo_allocations')->where('movement_id', $negativeMovement?->id)->first();
        $need($negativeCount['status'] === 'posted' && $shortage['status'] === 'posted' && $negativeMovement && $negativeMovement->quantity === '-0.25000000' && $negativeAllocation && $negativeAllocation->quantity === '0.25000000', 'Count shortage did not consume exact FIFO quantity.');
        $sameOnHand = DB::table('inventory_balances')->where('warehouse_id', $sameDestination['id'])->where('product_id', $product)->value('on_hand');
        $crossOnHand = DB::table('inventory_balances')->where('warehouse_id', $crossDestination['id'])->where('product_id', $product)->value('on_hand');
        $need($sameOnHand === '3.00000000' && $crossOnHand === '0.75000000', 'Count and transfer stock balances do not reconcile.');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $evidence = ['opening_batch_id' => $batch, 'opening_gl_posted' => true, 'payable_amount' => $payable->amount, 'cash_amount' => $cash->amount, 'bank_amount' => $bank->amount, 'opening_fifo_quantity' => $openingLayer->original_quantity, 'opening_fifo_value' => $openingLayer->original_value, 'issue_document_id' => $doc['id'], 'issue_movement_id' => $movement->id, 'issue_fifo_quantity' => $allocation->quantity, 'issue_fifo_cost' => $allocation->amount, 'issue_journal_id' => $journal->id, 'issue_debit' => $dr, 'issue_credit' => $cr, 'remaining_quantity' => $balance, 'positive_adjustment_value' => $adjustmentLayer->original_value, 'same_branch_transfer' => $sameTransfer, 'cross_branch_transfer' => $crossTransfer, 'count_surplus_journal_amount' => $positiveCost, 'count_shortage_fifo_quantity' => $negativeAllocation->quantity, 'same_destination_on_hand' => $sameOnHand, 'cross_destination_on_hand' => $crossOnHand, 'constraints' => 'IMMEDIATE'];
        unset($ctx['detail_stage']);

        return $evidence;
    }, ['real financial setup and dated synthetic tax rules'], true);

    $record('opening declaration independent approval and real activation', function () use (&$ctx, $uuid, $expect, $need) {
        $opening = app(OpeningBalances::class);
        $batch = $opening->save($ctx['maker'], $ctx['config_version'], $uuid(), [], true, 'Synthetic audited no-opening declaration');
        $opening->advance($ctx['maker'], $batch, 1, 'submit');
        $expect(fn () => $opening->advance($ctx['maker'], $batch, 2, 'approve'), 'opening preparer self-approval');
        $opening->advance($ctx['reviewer'], $batch, 2, 'approve');
        app(ActivateFinancialPosting::class)->execute($ctx['reviewer'], $batch, 3);
        $need(DB::table('accounting_activation')->whereNotNull('activated_at')->exists(), 'Activation missing.');
        $need(! DB::table('accounting_journals')->where('source_key', 'like', 'opening:%')->exists(), 'Explicit no-balances declaration posted a fake journal.');
        $ctx['batch'] = $batch;

        return ['opening_batch_id' => $batch, 'status' => 'posted', 'activated' => true, 'fake_zero_journals' => 0];
    }, ['real financial setup and dated synthetic tax rules']);

    $record('exact tax arithmetic', function () use (&$ctx, $need) {
        $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
        $ctx['product'] = $product->id;
        $tax = app(AccountingService::class)->calculateTax($ctx['maker'], 'purchase', '2024-06-01', 'exclusive', [['product_id' => $product->id, 'quantity' => '3', 'unit_price' => '0.335', 'discount' => '0']]);
        $need($tax['net'] === '1.01' && $tax['tax'] === '0.14' && $tax['total'] === '1.15', 'Expected net 1.01, tax .14, total 1.15; actual '.json_encode($tax));
        $ctx['tax'] = $tax;

        return ['net' => $tax['net'], 'tax' => $tax['tax'], 'total' => $tax['total'], 'tax_rate' => '14'];
    }, ['opening declaration independent approval and real activation']);

    $record('inclusive price discount and future exemption effective date', function () use (&$ctx, $need) {
        $inclusive = app(AccountingService::class)->calculateTax($ctx['maker'], 'sale', '2024-06-01', 'inclusive', [
            ['product_id' => $ctx['product'], 'quantity' => '2', 'unit_price' => '1.15', 'discount' => '0.10'],
        ]);
        $need($inclusive['net'] === '1.93' && $inclusive['tax'] === '0.27' && $inclusive['total'] === '2.20', 'Inclusive discounted sale expected 1.93/.27/2.20; got '.json_encode($inclusive));
        $setup = app(FinancialActivation::class);
        $version = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
        $setup->addTaxRule($ctx['maker'], $version, ['code' => 'FA-EXEMPT-'.Str::random(5), 'name' => 'Synthetic future exempt product rule', 'transaction_type' => 'sale', 'product_id' => $ctx['product'], 'rate' => '0', 'starts_on' => '2099-01-01', 'ends_on' => null, 'treatment' => 'exempt', 'tax_scale' => 2, 'legal_reference' => 'Synthetic future exemption fixture reference']);
        $before = app(AccountingService::class)->calculateTax($ctx['maker'], 'sale', '2098-12-31', 'exclusive', [
            ['product_id' => $ctx['product'], 'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0'],
        ]);
        $effective = app(AccountingService::class)->calculateTax($ctx['maker'], 'sale', '2099-01-01', 'exclusive', [
            ['product_id' => $ctx['product'], 'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0'],
        ]);
        $need($before['tax'] === '1.40' && $before['total'] === '11.40', 'Before product exemption effective date, generic sale rule should calculate 1.40 tax.');
        $need($effective['tax'] === '0.00' && $effective['total'] === '10.00' && $effective['lines'][0]['treatment'] === 'exempt', 'Product-specific exemption did not take effect on 2099-01-01.');

        return ['inclusive_net' => $inclusive['net'], 'inclusive_tax' => $inclusive['tax'], 'inclusive_total' => $inclusive['total'], 'line_discount' => '0.10', 'pre_effective_tax' => $before['tax'], 'effective_exempt_tax' => $effective['tax'], 'effective_date' => '2099-01-01'];
    }, ['exact tax arithmetic'], true);

    $record('official quotation issuance written acceptance and controlled draft conversion', function () use (&$ctx, $uuid, $need, $expect) {
        $maker = $ctx['maker'];
        $reviewer = $ctx['reviewer'];
        $ctx['detail_stage'] = 'quotation policy';
        $commercial = app(QuotationCommercialPolicy::class);
        $commercial->save($maker, ['validity_default' => 30, 'validity_min' => 1, 'validity_max' => 365,
            'line_percent' => '0', 'line_fixed' => '0', 'quotation_percent' => '0', 'quotation_fixed' => '0'], null);
        $paymentCode = 'QA-PAY-'.strtoupper(Str::random(6));
        $deliveryCode = 'QA-DEL-'.strtoupper(Str::random(6));
        $ctx['detail_stage'] = 'payment term action';
        $commercial->term($maker, ['code' => $paymentCode, 'kind' => 'payment', 'name' => 'Synthetic QA payment terms', 'body' => 'Synthetic payment on completion.']);
        $ctx['detail_stage'] = 'delivery term action';
        $commercial->term($maker, ['code' => $deliveryCode, 'kind' => 'delivery', 'name' => 'Synthetic QA delivery terms', 'body' => 'Synthetic delivery within the approved schedule.']);
        $payment = QuotationTerm::where('code', $paymentCode)->where('active', true)->firstOrFail();
        $delivery = QuotationTerm::where('code', $deliveryCode)->where('active', true)->firstOrFail();
        $product = Product::query()->findOrFail($ctx['product']);
        $unit = (int) $product->base_unit_id;
        $ctx['detail_stage'] = 'approved sale price actions';
        $secondProduct = Product::factory()->create(['base_unit_id' => $unit, 'purchase_unit_id' => $unit, 'sales_unit_id' => $unit, 'kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
        $salePrices = app(SalePrices::class);
        foreach ([$product, $secondProduct] as $pricedProduct) {
            $priceId = $salePrices->save($maker, $pricedProduct->id, [
                'amount' => '10.00', 'unit_id' => $unit, 'starts_on' => '2024-06-01', 'ends_on' => null, 'reference' => 'Synthetic accepted quotation price evidence',
            ]);
            $salePrices->decide($reviewer, $priceId, 'approve');
        }
        $ctx['detail_stage'] = 'customer and quotation draft save';
        $customer = Customer::factory()->create(['status' => 'active']);
        $today = now('Africa/Cairo')->toDateString();
        $validUntil = now('Africa/Cairo')->addDays(30)->toDateString();
        $quote = app(QuotationActions::class)->save($maker, [
            'customer_id' => $customer->id, 'quotation_date' => $today, 'valid_until' => $validUntil, 'project_name' => 'Synthetic QA quotation conversion',
            'rfq_reference' => 'QA-'.strtoupper(Str::random(8)), 'discount_mode' => 'percent', 'discount_value' => '0',
            'payment_term_id' => $payment->id, 'delivery_term_id' => $delivery->id, 'payment_terms' => $payment->body, 'delivery_terms' => $delivery->body,
            'notes' => 'Synthetic test-only commercial source.', 'exception_reason' => null,
            'lines' => [
                ['product_id' => $product->id, 'unit_id' => $unit, 'quantity' => '2.00000000', 'requested_price' => null, 'description' => 'Synthetic electrical product line one', 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null],
                ['product_id' => $secondProduct->id, 'unit_id' => $unit, 'quantity' => '2.00000000', 'requested_price' => null, 'description' => 'Synthetic electrical product line two', 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => null],
            ],
        ]);
        $need($quote->status === 'draft' && $quote->lines->count() === 2 && $quote->lines->every(fn ($line) => $line->suggested_price === '10.00000000'), 'Quotation draft did not capture both approved sale prices.');
        $ctx['detail_stage'] = 'official quotation issue';
        $issuance = app(QuotationIssuance::class);
        $issuance->issue($maker, $quote->id, 1);
        $quote = $quote->fresh('lines');
        $issue = DB::table('sales_quotation_issues')->where('quotation_id', $quote->id)->first();
        $need($quote->status === 'issued' && $quote->version === 2 && $issue && $issue->source_version === 1, 'Real quotation issue did not freeze the source version.');
        $tax = json_decode($issue->tax_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $need($tax['net'] === '40.00' && $tax['tax'] === '5.60' && $tax['total'] === '45.60' && $tax['production_validated'], 'Issued quote tax snapshot did not use approved validated tax.');
        $official = app(OfficialDocuments::class);
        $pdf = $official->retrieve((string) $issue->document_id);
        $need(str_starts_with($pdf['bytes'], '%PDF-') && hash_equals($pdf['sha256'], hash('sha256', $pdf['bytes'])) && strlen($issue->document_id) === 36, 'Official quote PDF bytes/hash/id are invalid.');
        $number = DB::table('system_official_documents')->where('id', $issue->document_id)->value('official_number');
        $need(str_starts_with($number, 'QT-') && DB::table('system_official_documents')->where('id', $issue->document_id)->exists(), 'Official quotation number/document missing.');
        $issuance->issue($maker, $quote->id, 1);
        $need(DB::table('sales_quotation_issues')->where('quotation_id', $quote->id)->count() === 1 && DB::table('system_official_documents')->where('document_type', 'quotation')->where('source_id', (string) $quote->id)->count() === 1, 'Official issue retry created another document or issue row.');

        $sourceKey = json_encode(['official-document', 1, 'quotation', (string) $quote->id, 1], JSON_THROW_ON_ERROR);
        config(['database.connections.phase0_official_lock' => config('database.connections.pgsql')]);
        $lockConnection = DB::connection('phase0_official_lock');
        $lockProbe = $lockConnection->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', [$sourceKey]);
        $differentProbe = $lockConnection->selectOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired', [
            json_encode(['official-document', 1, 'quotation', (string) $quote->id.'-other', 1], JSON_THROW_ON_ERROR),
        ]);
        $need(! filter_var($lockProbe->acquired, FILTER_VALIDATE_BOOLEAN) && filter_var($differentProbe->acquired, FILTER_VALIDATE_BOOLEAN), 'System official-document source lock was not held per source/revision.');
        $lockConnection->disconnect();

        $ctx['detail_stage'] = 'written acceptance upload';
        $imagePath = $GLOBALS['phase0_audit_root'].'/quotation-acceptance.png';
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGQAAAAASUVORK5CYII=', true));
        $file = new UploadedFile($imagePath, 'synthetic-acceptance.png', 'image/png', null, true);
        $acceptance = ['accepted_on' => $today, 'evidence_type' => 'purchase_order', 'reference' => 'Synthetic customer acceptance QA-'.strtoupper(Str::random(6))];
        $issuance->accept($maker, $quote->id, 2, $acceptance, $file);
        $accepted = $quote->fresh();
        $acceptedRow = DB::table('sales_quotation_acceptances')->where('quotation_id', $quote->id)->first();
        $need($accepted->status === 'accepted' && $accepted->version === 3 && $acceptedRow && $acceptedRow->issue_id === $issue->id
            && hash_equals($acceptedRow->sha256, hash('sha256', base64_decode($acceptedRow->evidence_base64, true))), 'Written customer acceptance evidence did not bind immutably to the issued revision.');
        $issuance->accept($maker, $quote->id, 2, $acceptance, $file);
        $need(DB::table('sales_quotation_acceptances')->where('quotation_id', $quote->id)->count() === 1, 'Identical acceptance retry created duplicate evidence.');
        $different = $acceptance;
        $different['reference'] .= ' altered';
        $expect(fn () => $issuance->accept($maker, $quote->id, 2, $different, $file), 'altered acceptance retry', 'conflict');

        $ctx['detail_stage'] = 'accepted quotation draft conversion';
        $conversionKey = $uuid();
        $order = app(ConvertAcceptedQuotation::class)->execute($maker, $quote->id, 3, $ctx['branch'], $conversionKey);
        $need($order->source_type === 'quotation' && $order->status === 'draft' && $order->branch_id === $ctx['branch'] && $order->lines->count() === 2
            && $order->calculation['accepted_tax_snapshot']['total'] === '45.60', 'Accepted quotation did not convert two accepted products to a frozen unsent Sales Order draft.');
        $need(DB::table('sales_quotation_conversions')->where('quotation_id', $quote->id)->where('order_id', $order->id)->exists()
            && ! DB::table('sales_order_reviews')->where('sales_order_id', $order->id)->exists()
            && ! DB::table('inventory_reservations')->where('source_module', 'sales')->where('source_id', (string) $order->id)->exists(),
            'Conversion created no immutable link, review approval, or inventory reservation as required.');
        $sourceEdit = $order->only(['branch_id', 'customer_id', 'project_name', 'order_date', 'requested_delivery_date', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'notes']);
        $sourceEdit['order_date'] = $order->order_date->toDateString();
        $sourceEdit['requested_delivery_date'] = $order->requested_delivery_date?->toDateString();
        $sourceEdit['lines'] = $order->lines->map(fn ($line) => $line->only(['product_id', 'unit_id', 'quantity', 'requested_price', 'description', 'discount_mode', 'discount_value', 'override_reason']))->all();
        $expect(fn () => app(SalesOrderActions::class)->save($maker, $sourceEdit, $order->id, 1),
            'converted quotation order direct commercial edit', 'conflict');
        $replay = app(ConvertAcceptedQuotation::class)->execute($maker, $quote->id, 3, $ctx['branch'], $conversionKey);
        $need($replay->id === $order->id && DB::table('sales_orders')->where('draft_key', $conversionKey)->count() === 1, 'Identical conversion retry duplicated the draft.');
        $expect(fn () => app(ConvertAcceptedQuotation::class)->execute($maker, $quote->id, 3, $ctx['branch'], $uuid()), 'altered conversion key', 'conflict');
        $otherBranch = Branch::factory()->create(['code' => 'QA-CONVERT-'.strtoupper(Str::random(6))]);
        $grant = DB::table('access_grants');
        foreach (['M04.sales_order_view', 'M04.sales_order_convert'] as $permission) {
            $grant->insert(['user_id' => $maker, 'permission_code' => $permission, 'branch_id' => $otherBranch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $expect(fn () => app(ConvertAcceptedQuotation::class)->execute($maker, $quote->id, 3, $otherBranch->id, $conversionKey), 'altered conversion branch', 'conflict');
        $ctx['detail_stage'] = 'real independent Sales approval and Inventory backorder reservation';
        $backorderWarehouse = app(InventoryActions::class)->saveWarehouse($maker, ['code' => 'QA-BACKORDER-'.strtoupper(Str::random(6)),
            'name' => 'Synthetic quotation backorder warehouse', 'branch_id' => $ctx['branch'], 'active' => true]);
        foreach ([$maker, $reviewer] as $scopedUser) {
            DB::table('user_warehouse_scopes')->insert(['user_id' => $scopedUser, 'warehouse_id' => $backorderWarehouse['id'], 'branch_id' => $ctx['branch'], 'created_at' => now(), 'updated_at' => now()]);
        }
        $inventoryDocs = app(InventoryDocuments::class);
        $supply = $inventoryDocs->save($maker, 'adjustment', ['creation_key' => $uuid(), 'warehouse_id' => $backorderWarehouse['id'],
            'reason' => 'Synthetic reviewed FIFO supply for accepted quotation delivery', 'lines' => [
                ['product_id' => $product->id, 'quantity' => '1.50000000', 'unit_cost' => '10.0000000000000000', 'cost_reference' => 'Synthetic approved supply evidence QA-SUP-1'],
                ['product_id' => $secondProduct->id, 'quantity' => '1.50000000', 'unit_cost' => '10.0000000000000000', 'cost_reference' => 'Synthetic approved supply evidence QA-SUP-2'],
            ]]);
        $supply = $inventoryDocs->transition($maker, $supply['id'], 'submit', $supply['version']);
        $supply = $inventoryDocs->transition($reviewer, $supply['id'], 'approve', $supply['version']);
        $supply = $inventoryDocs->transition($maker, $supply['id'], 'post', $supply['version']);
        $need($supply['status'] === 'posted', 'Synthetic delivery supply must be posted through the real approved inventory action.');
        $review = app(SalesOrderActions::class)->submit($maker, $order->id, 1);
        $need($review->status === 'pending' && DB::table('sales_order_issues')->where('order_id', $order->id)->doesntExist(),
            'Submission must create a real review while remaining unissued and unreserved.');
        $warehouseByLine = $order->lines->mapWithKeys(fn ($line) => [$line->id => (int) $backorderWarehouse['id']])->all();
        app(ApproveSalesOrder::class)->execute($reviewer, $order->id, 2, $warehouseByLine);
        $approved = $order->fresh('lines');
        $issueOrder = DB::table('sales_order_issues')->where('order_id', $order->id)->first();
        $allocations = DB::table('sales_order_allocations')->where('order_id', $order->id)->orderBy('line_id')->get();
        $reservations = DB::table('inventory_reservations')->where('source_module', 'sales')->where('source_id', (string) $order->id)->orderBy('id')->get();
        $need($approved->status === 'approved' && $issueOrder && str_starts_with($approved->number, 'SO-') && $allocations->count() === 2 && $reservations->count() === 2,
            'Independent Sales approval did not create an issued order and one Inventory reservation per product.');
        foreach ($reservations as $reservation) {
            $need($reservation->requested === '2.00000000' && $reservation->reserved === '1.50000000',
                'Approved sales demand should reserve only available synthetic stock and retain the unavailable quantity as backorder.');
        }
        $reservationCount = $reservations->count();
        $beforeUnstagedIssue = DB::table('inventory_movements')->where('source_module', 'Sales')->where('source_id', (string) $order->id)->where('operation', 'reservation_issue')->count();
        $expect(fn () => app(InventoryApi::class)->fulfillReservation(
            $reviewer, (int) $reservations[0]->id, '0.50000000', $uuid()), 'generic reservation fulfillment without a staged Sales delivery instruction', 'conflict');
        $need(DB::table('inventory_movements')->where('source_module', 'Sales')->where('source_id', (string) $order->id)->where('operation', 'reservation_issue')->count() === $beforeUnstagedIssue,
            'A generic Inventory fulfillment call without a staged Sales delivery instruction created a stock movement.');
        app(ApproveSalesOrder::class)->execute($reviewer, $order->id, 2, $warehouseByLine);
        $need(DB::table('sales_order_issues')->where('order_id', $order->id)->count() === 1
            && DB::table('inventory_reservations')->where('source_module', 'sales')->where('source_id', (string) $order->id)->count() === $reservationCount,
            'Approved-order replay duplicated official issue or reservations.');
        $alteredWarehouse = $warehouseByLine;
        $alteredWarehouse[$order->lines[0]->id] = (int) $ctx['warehouse'];
        $expect(fn () => app(ApproveSalesOrder::class)->execute($reviewer, $order->id, 2, $alteredWarehouse),
            'altered approved warehouse allocation', 'conflict');
        $ctx['detail_stage'] = 'partial and final real Sales delivery fulfillment';
        $deliveries = app(SalesDeliveries::class);
        $partialData = ['creation_key' => $uuid(), 'reference' => 'Synthetic partial delivery from reserved stock', 'lines' => $order->lines->map(fn ($line) => ['line_id' => $line->id, 'quantity' => '0.50000000'])->all()];
        $partialDelivery = $deliveries->create($reviewer, $order->id, $partialData);
        $deliveries->confirm($reviewer, $partialDelivery);
        $deliveries->confirm($reviewer, $partialDelivery);
        $need(DB::table('sales_delivery_instructions')->where('id', $partialDelivery)->value('status') === 'confirmed'
            && DB::table('inventory_reservation_events as e')->join('inventory_reservations as r', 'r.id', '=', 'e.reservation_id')
                ->where('r.source_id', (string) $order->id)->where('e.action', 'fulfilled')->where('e.quantity', '0.50000000')->count() === 2,
            'Partial delivery did not fulfill exact reservation quantities once per product.');
        $finalData = ['creation_key' => $uuid(), 'reference' => 'Synthetic final delivery of reserved balance', 'lines' => $order->lines->map(fn ($line) => ['line_id' => $line->id, 'quantity' => '1.00000000'])->all()];
        $finalDelivery = $deliveries->create($reviewer, $order->id, $finalData);
        $deliveries->confirm($reviewer, $finalDelivery);
        $deliveryMovements = DB::table('inventory_movements')->where('source_module', 'Sales')->where('source_id', (string) $order->id)->where('operation', 'reservation_issue')->count();
        $deliveryJournals = DB::table('inventory_movement_journals as j')->join('inventory_movements as m', 'm.id', '=', 'j.movement_id')
            ->where('m.source_module', 'Sales')->where('m.source_id', (string) $order->id)->where('m.operation', 'reservation_issue')->count();
        $afterReservations = DB::table('inventory_reservations')->where('source_module', 'sales')->where('source_id', (string) $order->id)->get();
        $need($deliveryMovements === 4 && $deliveryJournals === 4 && $afterReservations->every(fn ($r) => $r->fulfilled === '1.50000000' && $r->reserved === '1.50000000')
            && DB::table('sales_delivery_instructions')->where('order_id', $order->id)->where('status', 'confirmed')->count() === 2,
            'Partial/final deliveries did not create actual FIFO issues/COGS journals and exhaust only the reserved quantity.');
        $deliveryDocs = DB::table('sales_delivery_instructions')->where('order_id', $order->id)->where('status', 'confirmed')->pluck('document_id');
        $need($deliveryDocs->every(fn ($id) => DB::table('system_official_documents')->where('id', $id)->where('document_type', 'sales_delivery')->exists()),
            'Confirmed delivery did not produce official immutable documents.');
        $progress = app(SalesOrderProgress::class)->read($reviewer, $approved);
        $need($progress['reservation'] === 'backorder' && $progress['delivery'] === 'partial'
            && count($progress['lines']) === 2 && collect($progress['lines'])->every(fn ($line) => $line['delivered'] === '1.50000000' && $line['backorder'] === '0.50000000'),
            'Sales order progress must report 1.5 of 2 delivered with a real 0.5 backorder for each product.');
        $ordersApi = app(SalesOrdersApi::class);
        $detail = $ordersApi->getOrder($reviewer, $order->id);
        $need($detail['reservation'] === 'backorder' && $detail['delivery'] === 'partial' && count($detail['fulfillment']) === 2,
            'Sales order detail projection omitted partial delivery/backorder progress.');
        $ctx['detail_stage'] = 'genuine priced supply replenishment and backorder completion';
        $extraSupply = $inventoryDocs->save($maker, 'adjustment', ['creation_key' => $uuid(), 'warehouse_id' => $backorderWarehouse['id'],
            'reason' => 'Synthetic reviewed FIFO supply for genuine Sales backorder completion', 'lines' => [
                ['product_id' => $product->id, 'quantity' => '0.50000000', 'unit_cost' => '10.0000000000000000', 'cost_reference' => 'Synthetic approved supply evidence QA-SUP-3'],
                ['product_id' => $secondProduct->id, 'quantity' => '0.50000000', 'unit_cost' => '10.0000000000000000', 'cost_reference' => 'Synthetic approved supply evidence QA-SUP-4'],
            ]]);
        $extraSupply = $inventoryDocs->transition($maker, $extraSupply['id'], 'submit', $extraSupply['version']);
        $extraSupply = $inventoryDocs->transition($reviewer, $extraSupply['id'], 'approve', $extraSupply['version']);
        $extraSupply = $inventoryDocs->transition($maker, $extraSupply['id'], 'post', $extraSupply['version']);
        $need($extraSupply['status'] === 'posted', 'Additional genuine backorder supply was not posted via reviewed Inventory actions.');
        foreach ($reservations as $reservation) {
            $replenishKey = $uuid();
            $replenished = app(InventoryApi::class)->replenishReservation($reviewer, (int) $reservation->id, '0.50000000', $replenishKey);
            app(InventoryApi::class)->replenishReservation($reviewer, (int) $reservation->id, '0.50000000', $replenishKey);
            $expect(fn () => app(InventoryApi::class)->replenishReservation($reviewer, (int) $reservation->id, '0.25000000', $replenishKey),
                'changed quantity on replenishment replay', 'conflict');
            $need($replenished['reserved'] === '2.00000000', 'Sales backorder replenishment did not allocate the extra half unit.');
        }
        foreach ($order->lines as $line) {
            $third = $deliveries->create($reviewer, $order->id, ['creation_key' => $uuid(), 'reference' => 'Synthetic final backorder delivery', 'lines' => [['line_id' => $line->id, 'quantity' => '0.50000000']]]);
            $deliveries->confirm($reviewer, $third);
            $deliveries->confirm($reviewer, $third);
        }
        $completeProgress = app(SalesOrderProgress::class)->read($reviewer, $approved);
        $need($completeProgress['reservation'] === 'fulfilled' && $completeProgress['delivery'] === 'fulfilled'
            && collect($completeProgress['lines'])->every(fn ($line) => $line['delivered'] === '2.00000000' && $line['backorder'] === '0.00000000'),
            'Real replenishment and delivery did not complete each 2-unit Sales line.');
        $need($ordersApi->getOrder($reviewer, $order->id)['delivery'] === 'fulfilled', 'Sales order detail did not report completed fulfillment.');
        $ctx['detail_stage'] = null;
        unset($ctx['detail_stage']);
        $ctx['quotation_id'] = $quote->id;
        $ctx['quotation_order_id'] = $order->id;

        return ['quotation_id' => $quote->id, 'issue_id' => $issue->id, 'official_number' => $number, 'official_sha256' => $pdf['sha256'], 'product_ids' => [$product->id, $secondProduct->id], 'tax' => $tax,
            'written_acceptance_sha256' => $acceptedRow->sha256, 'acceptance_retry_idempotent' => true, 'altered_acceptance_rejected' => true,
            'source_lock_held' => true, 'other_source_lock_available' => true, 'converted_sales_order_id' => $order->id, 'conversion_status' => $order->status,
            'conversion_retry_idempotent' => true, 'altered_key_rejected' => true, 'altered_branch_rejected' => true, 'no_conversion_side_effects' => true,
            'submitted_review_id' => $review->id, 'sales_order_issue_order_id' => $issueOrder->order_id, 'allocation_count' => $allocations->count(),
            'backorder_reservations' => $reservations->map(fn ($r) => ['requested' => $r->requested, 'reserved' => $r->reserved])->all(),
            'approval_replay_idempotent' => true, 'altered_warehouse_rejected' => true, 'supply_adjustment_id' => $supply['id'],
            'confirmed_deliveries' => [$partialDelivery, $finalDelivery], 'delivery_issue_movements' => $deliveryMovements,
            'delivery_cogs_journals' => $deliveryJournals, 'reserved_quantity_fulfilled' => true, 'unreserved_backorder_preserved' => true];
    }, ['real financial setup and dated synthetic tax rules', 'detailed GL AP cash bank FIFO opening and internal FIFO issue']);

    $record('approved PO official issuance PDF and reported-tax rejection', function () use (&$ctx, $uuid, $need, $expect) {
        $supplier = Supplier::factory()->create(['status' => 'active']);
        $ctx['supplier'] = $supplier->id;
        $orders = app(PurchaseOrders::class);
        $data = ['creation_key' => $uuid(), 'branch_id' => $ctx['branch'], 'supplier_id' => $supplier->id, 'order_date' => '2024-06-02', 'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => 'synthetic', 'notes' => 'synthetic audit', 'tax_mode' => 'exclusive', 'reported_tax' => null, 'lines' => [['product_id' => $ctx['product'], 'quantity' => '3.00000000', 'unit_price' => '0.33500000', 'discount_amount' => '0.00000000']]];
        $order = $orders->save($ctx['maker'], $data, null, 0);
        $orders->transition($ctx['maker'], $order, 1, 'submit');
        $expect(fn () => $orders->transition($ctx['maker'], $order, 2, 'approve'), 'PO creator self-approval');
        $orders->transition($ctx['reviewer'], $order, 2, 'approve');
        $orders->issue($ctx['reviewer'], $order);
        $issue = DB::table('purchasing_order_issues')->where('order_id', $order)->first();
        $need((bool) $issue, 'Issued PO source missing.');
        $docs = app(OfficialDocuments::class);
        $pdf = $docs->retrieve((string) $issue->document_id);
        $need(str_starts_with($pdf['bytes'], '%PDF-'), 'Stored PDF is not PDF bytes.');
        $need(hash_equals($pdf['sha256'], hash('sha256', $pdf['bytes'])), 'PDF SHA-256 mismatch.');
        $immutability = [];
        foreach ([
            'update' => fn () => DB::table('system_official_documents')->where('id', $issue->document_id)->update(['pdf_base64' => 'invalid synthetic mutation']),
            'delete' => fn () => DB::table('system_official_documents')->where('id', $issue->document_id)->delete(),
        ] as $operation => $attempt) {
            $sp = 'pdf_immutable_'.$operation;
            DB::statement('SAVEPOINT '.$sp);
            $blocked = false;
            try {
                $attempt();
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'Immutable financial evidence')) {
                    throw $e;
                }
                $blocked = true;
            } finally {
                DB::statement('ROLLBACK TO SAVEPOINT '.$sp);
                DB::statement('RELEASE SAVEPOINT '.$sp);
            }
            $need($blocked, 'Official PDF '.$operation.' was not rejected by the database immutability trigger.');
            $immutability[$operation] = 'rejected';
        }
        $pdfAfter = $docs->retrieve((string) $issue->document_id);
        $need(hash_equals($pdf['sha256'], $pdfAfter['sha256']) && hash_equals($pdf['sha256'], hash('sha256', $pdfAfter['bytes'])), 'PDF changed after denied update/delete attempts.');
        $number = $issue->official_number;
        $count = DB::table('system_official_documents')->where('id', $issue->document_id)->count();
        $orders->issue($ctx['reviewer'], $order);
        $need(DB::table('purchasing_order_issues')->where('order_id', $order)->value('official_number') === $number && DB::table('system_official_documents')->where('id', $issue->document_id)->count() === $count, 'PO issuance retry changed document or number.');
        $bad = $data;
        $bad['creation_key'] = $uuid();
        $bad['reported_tax'] = '999.00';
        $badOrder = $orders->save($ctx['maker'], $bad, null, 0);
        $orders->transition($ctx['maker'], $badOrder, 1, 'submit');
        $orders->transition($ctx['reviewer'], $badOrder, 2, 'approve');
        $expect(fn () => $orders->issue($ctx['reviewer'], $badOrder), 'supplier-reported tax override');
        $need(! DB::table('purchasing_order_issues')->where('order_id', $badOrder)->exists(), 'Tax-mismatched PO issued.');
        $ctx['order'] = $order;
        $purchasingAccess = app(PurchasingAccess::class);
        $allowedBranches = array_column($purchasingAccess->branches($ctx['reviewer'], 'order_view'), 'id');
        $projection = app(PurchaseOrderProjection::class);
        $filtered = $projection->query()->whereIn('branch_id', $allowedBranches);
        $projection->filter($filtered, 'not_received', $number);
        $matches = $filtered->pluck('id')->map(fn ($id) => (int) $id)->all();
        $need($matches === [$order], 'M05 not-received/status + official-number search filters omitted the scoped issued PO.');
        $ctx['m05_not_received_filter'] = true;

        return ['order_id' => $order, 'official_number' => $number, 'document_id' => $issue->document_id, 'pdf_bytes' => strlen($pdf['bytes']), 'sha256' => $pdf['sha256'], 'net' => $ctx['tax']['net'], 'tax' => $ctx['tax']['tax'], 'total' => $ctx['tax']['total'], 'same_issue_retry' => true, 'bad_reported_tax_rejected' => true, 'pdf_update_delete' => $immutability, 'm05_branch_scoped_not_received_and_number_search' => true];
    }, ['exact tax arithmetic', 'inclusive price discount and future exemption effective date']);

    $record('unauthorized actor and rollback on pending receipt', function () use (&$ctx, $uuid, $need, $expect) {
        $line = DB::table('purchasing_order_lines')->where('order_id', $ctx['order'])->first();
        $receive = app(PurchaseReceiving::class);
        $data = ['creation_key' => $uuid(), 'order_id' => $ctx['order'], 'warehouse_id' => $ctx['warehouse'], 'receipt_date' => '2024-06-03', 'delivery_reference' => 'SYNTHETIC-DELIVERY-PARTIAL', 'notes' => 'partial', 'lines' => [['order_line_id' => $line->id, 'quantity' => '1.00000000']]];
        $id = $receive->save($ctx['maker'], $data, null, 0);
        $receive->submit($ctx['maker'], $id, 1);
        $before = DB::table('inventory_movements')->count();
        $expect(fn () => app(PostPurchaseReceipt::class)->execute($ctx['unauthorized'], $id, 2), 'actor with no receipt grant/scope', 'forbidden');
        $need(DB::table('purchasing_receipts')->where('id', $id)->value('status') === 'integration_pending', 'Denied posting changed receipt state.');
        $need(DB::table('inventory_movements')->count() === $before, 'Denied posting left stock movement.');
        $ctx['partial'] = $id;
        $ctx['partial_line'] = DB::table('purchasing_receipt_lines')->where('receipt_id', $id)->value('id');

        return ['receipt_id' => $id, 'status' => 'integration_pending', 'denied_actor' => $ctx['unauthorized'], 'movement_count_before_after' => [$before, DB::table('inventory_movements')->count()]];
    }, ['approved PO official issuance PDF and reported-tax rejection']);

    $record('mid-flight failure rolls back all source effects', function () use (&$ctx, $expect, $need) {
        $id = $ctx['partial'];
        $line = $ctx['partial_line'];
        $enabled = true;
        $before = [
            'movements' => DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $id)->count(),
            'layers' => DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$id.':'.$line)->count(),
            'journals' => DB::table('accounting_journals')->where('source_key', 'purchase-receipt:'.$id)->count(),
            'documents' => DB::table('system_official_documents')->count(),
        ];
        Event::listen(ProcurementChanged::class, function ($event) use (&$enabled, $id) {
            if ($enabled && $event->type === 'purchase_receipt' && $event->id === $id && $event->action === 'receipt.posted') {
                throw new RuntimeException('FINANCIAL_AUDIT_INJECTED_AFTER_RECEIPT_POSTED');
            }
        });
        try {
            $expect(fn () => app(PostPurchaseReceipt::class)->execute($ctx['reviewer'], $id, 2), 'sentinel after inventory/FIFO/GRNI/source writes', 'sentinel');
        } finally {
            $enabled = false;
        }
        $after = [
            'movements' => DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $id)->count(),
            'layers' => DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$id.':'.$line)->count(),
            'journals' => DB::table('accounting_journals')->where('source_key', 'purchase-receipt:'.$id)->count(),
            'documents' => DB::table('system_official_documents')->count(),
        ];
        $need($before === $after, 'Injected failure left partial effects: '.json_encode([$before, $after]));
        $need(DB::table('purchasing_receipts')->where('id', $id)->value('status') === 'integration_pending', 'Injected failure changed receipt state.');

        return ['receipt_id' => $id, 'sentinel' => 'FINANCIAL_AUDIT_INJECTED_AFTER_RECEIPT_POSTED', 'counts_before' => $before, 'counts_after' => $after, 'rolled_back' => true];
    }, ['unauthorized actor and rollback on pending receipt']);

    $record('partial receipt post, atomic references, value and retry', function () use (&$ctx, $need) {
        $id = $ctx['partial'];
        app(PostPurchaseReceipt::class)->execute($ctx['reviewer'], $id, 2);
        $line = DB::table('purchasing_order_lines')->where('order_id', $ctx['order'])->first();
        $r = DB::table('purchasing_receipts')->find($id);
        $need($r->status === 'posted' && $r->capitalized_amount === '0.34', 'Partial receipt should capitalize 0.34; got '.$r->capitalized_amount);
        $before = DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $id)->count();
        app(PostPurchaseReceipt::class)->execute($ctx['reviewer'], $id, 2);
        $need(DB::table('inventory_movements')->where('source_module', 'Purchasing')->where('operation', 'purchase_receipt')->where('source_id', (string) $id)->count() === $before && $before === 1, 'Posted retry duplicated or missed stock movement.');
        $journal = DB::table('accounting_journals')->where('source_key', 'purchase-receipt:'.$id)->first();
        $need($journal && $journal->status === 'posted', 'Receipt GRNI journal missing.');
        $need((int) $r->journal_id === (int) $journal->id, 'Receipt posted journal_id does not match the actual GRNI journal.');
        $lines = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->get();
        $dr = '0.00';
        $cr = '0.00';
        foreach ($lines as $jl) {
            $dr = bcadd($dr, (string) $jl->debit, 2);
            $cr = bcadd($cr, (string) $jl->credit, 2);
        }
        $need(count($lines) === 2 && $dr === '0.34' && $cr === '0.34', 'Receipt GL not two-line/balanced at 0.34.');
        $receiptLine = DB::table('purchasing_receipt_lines')->where('receipt_id', $id)->value('id');
        $layer = DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$id.':'.$receiptLine)->first();
        $need($layer && $layer->original_value === '0.34' && $layer->original_quantity === '1.00000000', 'Receipt FIFO source/value mismatch.');
        $posting = DB::table('purchasing_receipt_postings')->where('receipt_id', $id)->where('line_id', $receiptLine)->first();
        $movement = $posting ? DB::table('inventory_movements')->find($posting->movement_id) : null;
        $need($posting && (int) $posting->layer_id === (int) $layer->id && $movement && $movement->operation === 'purchase_receipt' && $movement->source_module === 'Purchasing' && $movement->source_id === (string) $id && $movement->source_line === (string) $receiptLine && (int) $layer->movement_id === (int) $movement->id, 'Receipt posting row does not reconcile to its actual movement/FIFO source.');
        $projection = app(PurchaseOrderProjection::class);
        $ids = array_column(app(PurchasingAccess::class)->branches($ctx['reviewer'], 'order_view'), 'id');
        $filtered = $projection->query()->whereIn('branch_id', $ids);
        $projection->filter($filtered, 'partially_received', '');
        $need($filtered->where('purchasing_orders.id', $ctx['order'])->exists(), 'M05 partially-received status filter omitted the partially posted PO.');
        $ctx['partial'] = $id;

        return ['receipt_id' => $id, 'receipt_journal_id' => $r->journal_id, 'movement_count' => $before, 'movement_id' => $movement->id, 'fifo_layer_id' => $layer->id, 'amount' => $r->capitalized_amount, 'journal_id' => $journal->id, 'retry_idempotent' => true, 'm05_partially_received_filter' => true];
    }, ['mid-flight failure rolls back all source effects']);

    $record('final receipt exhausts cumulative order value and exact stock delta', function () use (&$ctx, $uuid, $need) {
        $line = DB::table('purchasing_order_lines')->where('order_id', $ctx['order'])->first();
        $receive = app(PurchaseReceiving::class);
        $data = ['creation_key' => $uuid(), 'order_id' => $ctx['order'], 'warehouse_id' => $ctx['warehouse'], 'receipt_date' => '2024-06-04', 'delivery_reference' => 'SYNTHETIC-DELIVERY-FINAL', 'notes' => 'final', 'lines' => [['order_line_id' => $line->id, 'quantity' => '2.00000000']]];
        $id = $receive->save($ctx['maker'], $data, null, 0);
        $receive->submit($ctx['maker'], $id, 1);
        app(PostPurchaseReceipt::class)->execute($ctx['reviewer'], $id, 2);
        $r = DB::table('purchasing_receipts')->find($id);
        $need($r->status === 'posted' && $r->capitalized_amount === '0.67', 'Final receipt value must be 0.67; got '.$r->capitalized_amount);
        $journal = DB::table('accounting_journals')->where('source_key', 'purchase-receipt:'.$id)->first();
        $need($journal && $journal->status === 'posted' && (int) $r->journal_id === (int) $journal->id, 'Final receipt posted_journal_id does not match its GRNI journal.');
        $sum = '0.00';
        foreach (DB::table('purchasing_receipts')->whereIn('id', [$ctx['partial'], $id])->pluck('capitalized_amount') as $amount) {
            $sum = bcadd($sum, (string) $amount, 2);
        }
        $need($sum === '1.01', 'Cumulative capitalized value should exhaust 1.01, got '.$sum);
        $partialLine = DB::table('purchasing_receipt_lines')->where('receipt_id', $ctx['partial'])->value('id');
        $finalLine = DB::table('purchasing_receipt_lines')->where('receipt_id', $id)->value('id');
        $layers = DB::table('inventory_fifo_layers')->whereIn('source_key', ['purchase-receipt:'.$ctx['partial'].':'.$partialLine, 'purchase-receipt:'.$id.':'.$finalLine])->get();
        $fifoValue = '0.00';
        foreach ($layers as $fifoLayer) {
            $fifoValue = bcadd($fifoValue, (string) $fifoLayer->original_value, 2);
        }
        $need($layers->count() === 2 && $fifoValue === '1.01', 'FIFO source layers do not sum to 1.01.');
        $posting = DB::table('purchasing_receipt_postings')->where('receipt_id', $id)->where('line_id', $finalLine)->first();
        $movement = $posting ? DB::table('inventory_movements')->find($posting->movement_id) : null;
        $finalLayer = DB::table('inventory_fifo_layers')->where('source_key', 'purchase-receipt:'.$id.':'.$finalLine)->first();
        $need($posting && (int) $posting->layer_id === (int) $finalLayer->id && $movement && $movement->source_id === (string) $id && $movement->source_line === (string) $finalLine && (int) $finalLayer->movement_id === (int) $movement->id, 'Final receipt source posting does not match movement/FIFO references.');
        $balance = DB::table('inventory_balances')->where('warehouse_id', $ctx['warehouse'])->where('product_id', $ctx['product'])->value('on_hand');
        $need($balance === '3.00000000', 'Expected on-hand stock delta of 3, got '.(string) $balance);
        $projection = app(PurchaseOrderProjection::class);
        $ids = array_column(app(PurchasingAccess::class)->branches($ctx['reviewer'], 'order_view'), 'id');
        $filtered = $projection->query()->whereIn('branch_id', $ids);
        $projection->filter($filtered, 'received', '');
        $need($filtered->where('purchasing_orders.id', $ctx['order'])->exists(), 'M05 received status filter omitted the fully received PO.');
        $ctx['final'] = $id;

        return ['receipt_id' => $id, 'receipt_journal_id' => $r->journal_id, 'journal_id' => $journal->id, 'posting_movement_id' => $movement->id, 'posting_layer_id' => $finalLayer->id, 'capitalized_amount' => $r->capitalized_amount, 'cumulative_amount' => $sum, 'fifo_layer_count' => $layers->count(), 'fifo_value' => $fifoValue, 'on_hand' => $balance, 'm05_received_filter' => true];
    }, ['partial receipt post, atomic references, value and retry']);

    $record('pre-Go-Live rejection', function () use ($expect) {
        $expect(fn () => app(FinancialActivation::class)->requirePostingDate('2024-05-31'), 'posting before Go-Live');

        return ['rejected_date' => '2024-05-31', 'go_live_date' => '2024-06-01'];
    }, ['opening declaration independent approval and real activation']);

    $record('deferred PostgreSQL constraints immediate', function () {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return ['constraint_mode' => 'IMMEDIATE', 'executed' => true];
    }, ['final receipt exhausts cumulative order value and exact stock delta']);
} finally {
    if ($db->transactionLevel() > 0) {
        $db->commit();
    }
    // Retain isolated audit fixture storage until backup/restore verification completes.
}
$allPass = ! collect($results)->contains(fn ($r) => $r['status'] !== 'PASS');
$json = json_encode(['database' => $actual->db, 'server_version_num' => $actual->version, 'results' => $results, 'all_pass' => $allPass], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
file_put_contents(dirname(__DIR__, 2).'/docs/qa/evidence/phase0-recovery-seed.json', $json.PHP_EOL, LOCK_EX);
echo $json.PHP_EOL;
if (! $allPass) {
    exit(1);
}

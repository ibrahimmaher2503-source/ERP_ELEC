<?php

// After real browser linking/issue, post the matching Purchasing and Sales owner documents.
use App\Models\User;
use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Domain\Project;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\ApproveSalesOrder;
use App\Modules\Sales\Application\SalesDeliveries;
use App\Modules\Sales\Application\SalesOrderActions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = getenv('PHASE2_STORAGE_ROOT');
if (! $storage || ! str_starts_with(str_replace('\\', '/', $storage), str_replace('\\', '/', $root).'/.tmp/phase2-projects-browser/')) {
    throw new RuntimeException('ABORT: source posting requires isolated private browser storage under workspace .tmp.');
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$port = (int) config('database.connections.pgsql.port');
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || $actual->db !== 'erp_elec_new_test' || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $actual->port !== $port || ! in_array($port, [55435, 55437, 55440], true)) {
    throw new RuntimeException('ABORT before source posting: expected isolated test clone on loopback 55435/55437/55440.');
}
$projectId = (int) (getenv('PHASE2_E2E_PROJECT_ID') ?: 0);
$salesId = (int) (getenv('PHASE2_E2E_SALES_ORDER_ID') ?: 0);
$salesLineId = (int) (getenv('PHASE2_E2E_SALES_LINE_ID') ?: 0);
$project = Project::query()->findOrFail($projectId);
$maker = User::query()->whereKey((int) $project->manager_id)->firstOrFail();
$reviewerId = DB::table('access_grants')->where('permission_code', 'M04.sales_order_approve')->where('branch_id', $project->branch_id)
    ->where('user_id', '<>', $maker->id)->value('user_id');
$reviewer = User::query()->whereKey((int) $reviewerId)->firstOrFail();
$linkablePurchase = DB::table('project_source_links')->where('project_id', $projectId)->where('source_type', 'purchase_order')->firstOrFail();
$poId = (int) $linkablePurchase->source_id;
$purchaseLine = DB::table('purchasing_order_lines')->where('order_id', $poId)->firstOrFail();
$receiptLine = DB::table('purchasing_receipt_lines as l')->join('purchasing_receipts as r', 'r.id', '=', 'l.receipt_id')
    ->where('r.order_id', $poId)->where('r.status', 'posted')->first(['l.id', 'l.order_line_id']);
$poLineId = (int) $purchaseLine->id;
$receiptLineId = (int) ($receiptLine->id ?? 0);
$warehouseId = (int) (getenv('PHASE2_E2E_WAREHOUSE_ID') ?: DB::table('user_warehouse_scopes')->where('user_id', $maker->id)->where('branch_id', $project->branch_id)->value('warehouse_id'));
$need = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$need($projectId > 0 && $salesId > 0 && $salesLineId > 0 && $poId > 0 && $poLineId > 0 && $receiptLineId > 0,
    'Browser acceptance IDs for the linked Sales/PO/receipt are incomplete.');
$need($project->status === 'active' && DB::table('project_source_links')->where('project_id', $projectId)->where('source_type', 'sales_order')->where('source_id', $salesId)->exists()
    && DB::table('project_source_links')->where('project_id', $projectId)->where('source_type', 'purchase_order')->where('source_id', $poId)->exists(),
    'Browser did not persist the Sales and Purchasing source links before posting.');
$existingSummary = app(ProjectFinancials::class)->summary((int) $maker->id, $projectId);
$existingCustomerInvoice = DB::table('rp_customer_invoices')->where('project_id', $projectId)->where('status', 'posted')->orderByDesc('id')->first();
$existingSupplierInvoice = DB::table('rp_supplier_invoices')->where('project_id', $projectId)->where('status', 'posted')->orderByDesc('id')->first();
if ($existingSummary['sales'][0]['id'] === $salesId && $existingSummary['sales'][0]['delivery'] === 'fulfilled'
    && $existingSummary['subledger']['grni_cleared'] === $existingSummary['purchasing']['received_value']
    && $existingSummary['subledger']['pending_invoices'] === 0 && $existingSummary['subledger']['unallocated_invoices'] === 0
    && $existingSummary['fifo_gl_difference'] === '0.00' && $existingCustomerInvoice && $existingSupplierInvoice) {
    echo json_encode(['database' => $actual->db, 'port' => $port, 'project_id' => $projectId,
        'sales_order_id' => $salesId, 'sales_delivery_id' => null, 'customer_invoice_id' => (int) $existingCustomerInvoice->id,
        'supplier_invoice_id' => (int) $existingSupplierInvoice->id, 'supplier_journal_id' => null, 'customer_journal_id' => null,
        'project_summary' => $existingSummary, 'source_postings_already_reconciled' => true],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
$date = now('Africa/Cairo')->toDateString();
$purchaseLine = DB::table('purchasing_order_lines')->where('order_id', $poId)->firstOrFail();
$supplier = (int) DB::table('purchasing_orders')->where('id', $poId)->value('supplier_id');
$purchaseApi = app(ReceivablesPayablesApi::class);
DB::beginTransaction();
try {
    // Complete and invoice the linked one-unit Sales Order after the browser has linked it.
    app(SalesOrderActions::class)->submit((int) $maker->id, $salesId, 1);
    app(ApproveSalesOrder::class)->execute((int) $reviewer->id, $salesId, 2, [$salesLineId => $warehouseId]);
    $deliveryId = app(SalesDeliveries::class)->create((int) $maker->id, $salesId,
        ['creation_key' => (string) Str::uuid(), 'reference' => 'Phase 2 browser linked Sales delivery',
            'lines' => [['line_id' => $salesLineId, 'quantity' => '1.00000000']]]);
    app(SalesDeliveries::class)->confirm((int) $reviewer->id, $deliveryId);
    $deliveryLineId = (int) DB::table('sales_delivery_instruction_lines')->where('instruction_id', $deliveryId)->value('id');
    $customerInvoice = $purchaseApi->createCustomerInvoice((int) $maker->id, ['creation_key' => (string) Str::uuid(),
        'customer_id' => (int) $project->customer_id, 'invoice_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' + 30 days')),
        'lines' => [['delivery_line_id' => $deliveryLineId, 'quantity' => '1.00000000']]]);
    $purchaseApi->validateCustomerInvoice((int) $maker->id, $customerInvoice, 1);
    $customerPost = $purchaseApi->postCustomerInvoice((int) $maker->id, $customerInvoice, 2);

    // Match the new linked, actually received PO to exactly its received quantity to clear GRNI.
    $supplierInvoice = $purchaseApi->createSupplierInvoice((int) $maker->id, ['creation_key' => (string) Str::uuid(),
        'supplier_id' => $supplier, 'supplier_invoice_number' => 'PB2-'.strtoupper(Str::random(10)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $poLineId, 'quantity' => '1.00000000', 'unit_price' => (string) $purchaseLine->unit_price,
            'discount_amount' => '0.00', 'matches' => [['receipt_line_id' => $receiptLineId, 'quantity' => '1.00000000']]]]]);
    $purchaseApi->validateSupplierInvoice((int) $maker->id, $supplierInvoice, 1);
    $purchaseApi->matchSupplierInvoice((int) $maker->id, $supplierInvoice, 2);
    $purchaseApi->approveSupplierInvoice((int) $maker->id, $supplierInvoice, 3);
    $supplierPost = $purchaseApi->postSupplierInvoice((int) $maker->id, $supplierInvoice, 4);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $summary = app(ProjectFinancials::class)->summary((int) $maker->id, $projectId);
    $need($summary['purchasing']['received_value'] === '10.00' && $summary['subledger']['grni_cleared'] === '10.00'
        && $summary['subledger']['unallocated_invoices'] === 0 && $summary['subledger']['pending_invoices'] === 0,
        'Linked received PO invoice did not clear project GRNI and invoice attribution: '.json_encode(['purchasing' => $summary['purchasing'], 'subledger' => $summary['subledger']]));
    $need(count($summary['sales']) === 1 && $summary['sales'][0]['id'] === $salesId && $summary['sales'][0]['delivery'] === 'fulfilled',
        'Linked Sales Order was not reflected as fulfilled in the project summary.');
    $need($summary['fifo_gl_difference'] === '0.00' && $summary['accounting']['pending_journals'] === 0,
        'Project FIFO and GL did not reconcile after the browser material issue and linked Sales/PO posts.');
    DB::commit();
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    throw $error;
}
$need(DB::table('rp_supplier_invoices')->where('id', $supplierInvoice)->where('status', 'posted')->exists()
    && DB::table('rp_customer_invoices')->where('id', $customerInvoice)->where('status', 'posted')->exists(),
    'Source invoices did not persist after matching/fulfilment actions.');
echo json_encode(['database' => $actual->db, 'port' => $port, 'project_id' => $projectId,
    'sales_order_id' => $salesId, 'sales_delivery_id' => $deliveryId, 'customer_invoice_id' => $customerInvoice,
    'supplier_invoice_id' => $supplierInvoice, 'supplier_journal_id' => $supplierPost['journal_id'] ?? null,
    'customer_journal_id' => $customerPost['journal_id'] ?? null, 'project_summary' => $summary],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

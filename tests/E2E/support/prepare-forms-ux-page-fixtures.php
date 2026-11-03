<?php

// Explicit synthetic drafts for the private form-review database; no approvals or postings.
use App\Models\User;
use App\Modules\FieldMaintenance\Domain\MaintenanceRequest;
use App\Modules\FieldMaintenance\Domain\MaintenanceWorkOrder;
use App\Modules\Inventory\Domain\InventoryDocument;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\Tender;
use App\Modules\Purchasing\Domain\PurchaseOrder;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\ReceivablesPayables\Domain\CustomerInvoice;
use App\Modules\ReceivablesPayables\Domain\SupplierInvoice;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\ServiceContracts\Domain\ServiceContract;
use App\Modules\WorkshopRepairs\Domain\WorkshopIntake;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\OptimizationRegressionDatabaseGuard;

$root = dirname(__DIR__, 3);
$private = $root.'/.tmp/forms-ux-20261102/private';
$bootstrap = json_decode(file_get_contents($private.'/bootstrap.json'), true, flags: JSON_THROW_ON_ERROR);
$actor = json_decode(file_get_contents($private.'/actor.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($bootstrap['env'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$env = $bootstrap['env'] + ['E2E_CLUSTER_MARKER' => $private.'/.codex-forms-ux-owned.json'];
$target = OptimizationRegressionDatabaseGuard::target($root, $env);
if ($target['port'] !== 55467) {
    throw new RuntimeException('This fixture only owns the private 55467 database.');
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath($private.'/storage');
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_user, current_setting('data_directory') as data_directory");
$receipt = DB::selectOne('select nonce from codex_test_guard.ownership_receipts');
OptimizationRegressionDatabaseGuard::verify($target, $identity, $receipt);
$output = $private.'/page-fixtures.json';
if (is_file($output)) {
    echo "Existing page fixture receipt preserved.\n";
    exit;
}
$ids = DB::transaction(function () use ($actor) {
    $user = (int) $actor['user_id'];
    $branch = (int) $actor['branch_id'];
    User::factory()->disabledDemo()->create();
    $customer = Customer::factory()->create(['status' => 'active']);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $product = Product::factory()->create(['created_by' => $user, 'updated_by' => $user]);
    $project = Project::factory()->create(['branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'created_by' => $user, 'manager_id' => $user]);
    $tender = Tender::factory()->create(['branch_id' => $branch, 'created_by' => $user, 'responsible_id' => $user]);
    $request = MaintenanceRequest::factory()->create(['branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'created_by' => $user, 'responsible_id' => $user]);
    $workOrder = MaintenanceWorkOrder::factory()->create(['branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'created_by' => $user, 'responsible_id' => $user]);
    $intake = WorkshopIntake::factory()->create(['branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'created_by' => $user]);
    $contract = ServiceContract::factory()->create(['branch_id' => $branch, 'customer_id' => $customer->id, 'created_by' => $user]);
    $requisition = PurchaseRequisition::factory()->create(['branch_id' => $branch, 'created_by' => $user]);
    $order = PurchaseOrder::factory()->create(['branch_id' => $branch, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'created_by' => $user]);
    $quotation = Quotation::factory()->create(['customer_id' => $customer->id, 'created_by' => $user, 'updated_by' => $user]);
    $salesOrder = SalesOrder::factory()->create(['branch_id' => $branch, 'created_by' => $user, 'updated_by' => $user, 'customer_id' => $customer->id]);
    $document = InventoryDocument::factory()->create(['warehouse_id' => (int) $actor['warehouse_id'], 'created_by' => $user]);
    $customerInvoice = CustomerInvoice::factory()->create(['branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'created_by' => $user]);
    $supplierInvoice = SupplierInvoice::factory()->create(['branch_id' => $branch, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'created_by' => $user]);

    return [
        'customer' => $customer->id, 'supplier' => $supplier->id, 'product' => $product->id,
        'project' => $project->id, 'tender' => $tender->id, 'request' => $request->id, 'workOrder' => $workOrder->id,
        'intake' => $intake->id, 'contract' => $contract->id, 'requisition' => $requisition->id,
        'order' => $order->id, 'salesOrder' => $salesOrder->id, 'quotation' => $quotation->id,
        'document' => $document->id, 'customerInvoice' => $customerInvoice->id, 'supplierInvoice' => $supplierInvoice->id,
        'branch' => $branch, 'user' => $user, 'role' => DB::table('roles')->where('code', 'Administrator')->value('id'),
    ];
});
file_put_contents($output, json_encode(['port' => 55467, 'database' => 'erp_elec_new_test', 'ids' => $ids], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Prepared synthetic drafts for '.count($ids)." page fixture keys; no approval/posting records created.\n";

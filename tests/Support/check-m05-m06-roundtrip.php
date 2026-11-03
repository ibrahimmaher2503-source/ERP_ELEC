<?php

use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryLedger;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Inventory\Domain\InventoryDocument;
use App\Modules\Inventory\Domain\InventoryDocumentLine;
use App\Modules\Inventory\Presentation\InventoryDocumentPage;
use App\Modules\Inventory\Presentation\InventoryExportController;
use App\Modules\Inventory\Presentation\InventoryPage;
use App\Modules\Inventory\Presentation\WarehousesPage;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\Purchasing\Application\RequisitionActions;
use App\Modules\Purchasing\Application\RfqActions;
use App\Modules\Purchasing\Application\SupplierOffers;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRfq;
use App\Modules\Purchasing\Presentation\OfferPage;
use App\Modules\Purchasing\Presentation\OrderPage;
use App\Modules\Purchasing\Presentation\ReceiptPage;
use App\Modules\Purchasing\Presentation\RequisitionPage;
use App\Modules\Purchasing\Presentation\RfqPage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M05/M06 regression check requires the exact separate PostgreSQL test database.');
}
$check = static function (bool $ok, string $label): void {
    if (! $ok) {
        throw new RuntimeException($label);
    } echo "PASS {$label}\n";
};
$expect = static function (string $class, Closure $fn, string $label) use ($check): Throwable {
    try {
        $fn();
    } catch (Throwable $e) {
        $check($e instanceof $class, $label.' (actual '.$e::class.': '.$e->getMessage().')');

        return $e;
    }
    throw new RuntimeException($label.' (expected exception was not thrown)');
};
$grant = static function (User $u, array $codes, int $branch): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $u->id, 'permission_code' => $code, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn () => (string) Str::uuid();
$today = now('Africa/Cairo')->toDateString();
DB::beginTransaction();
try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        DB::table('companies')->insert([
            'id' => 1,
            'code' => 'M05M06-CHECK',
            'name' => 'Synthetic regression company',
            'legal_name' => 'Synthetic M05/M06 Test Company',
            'tax_registration' => 'M05M06-TEST',
            'address' => 'Synthetic test address, Cairo, Egypt',
            'currency' => 'EGP',
            'setup_complete' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $company = DB::table('companies')->where('id', 1)->first();
    $companyIdentity = [];
    if (blank($company->legal_name)) {
        $companyIdentity['legal_name'] = 'Synthetic M05/M06 Test Company';
    }
    if (blank($company->address)) {
        $companyIdentity['address'] = 'Synthetic test address, Cairo, Egypt';
    }
    if ($companyIdentity !== []) {
        DB::table('companies')->where('id', 1)->update($companyIdentity + ['version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
    $branch = Branch::factory()->create(['code' => 'CHECK-'.strtoupper(Str::random(8))]);
    $creator = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$creator, $reviewer] as $u) {
        DB::table('user_branch_scopes')->insert(['user_id' => $u->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $createCodes = ['M01.branch_view', 'M02.product_view', 'M03.supplier_view', 'M05.requisition_view', 'M05.requisition_create', 'M05.requisition_update', 'M05.requisition_submit', 'M05.rfq_view', 'M05.rfq_create', 'M05.rfq_update', 'M05.rfq_issue', 'M05.rfq_dispatch', 'M05.offer_view', 'M05.offer_create', 'M05.offer_update', 'M05.award_propose', 'M05.comparison_view', 'M05.order_view', 'M05.order_create', 'M05.order_update', 'M05.order_submit', 'M05.receipt_view', 'M05.receipt_create', 'M06.warehouse_view', 'M06.warehouse_create', 'M06.warehouse_update', 'M06.receipt_create'];
    $reviewCodes = ['M05.requisition_view', 'M05.requisition_approve', 'M05.rfq_view', 'M05.offer_view', 'M05.award_approve', 'M05.order_view', 'M05.order_approve'];
    $grant($creator, $createCodes, $branch->id);
    $grant($reviewer, $reviewCodes, $branch->id);
    $grant($creator, ['M05.requisition_approve', 'M05.award_approve', 'M05.order_approve'], $branch->id);
    $grant($creator, ['M06.count_create', 'M06.count_submit', 'M06.count_approve', 'M06.receipt_approve', 'M06.receipt_post', 'M06.issue_create', 'M06.issue_post', 'M06.adjustment_create', 'M06.adjustment_approve', 'M06.adjustment_post', 'M06.transfer_create', 'M06.transfer_dispatch', 'M06.transfer_receive', 'M06.transfer_approve', 'M06.balance_view', 'M06.movement_view', 'M06.reservation_view'], $branch->id);
    foreach (['M11.setup_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.opening_manage', 'M11.opening_entry', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $creator->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M11.setup_view', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate'] as $permission) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $reviewer->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($creator, ['M11.opening_entry', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_post'], $branch->id);
    $grant($reviewer, ['M11.opening_review', 'M11.opening_approve', 'M11.financial_activate'], $branch->id);
    $grant($reviewer, ['M06.warehouse_view', 'M06.balance_view', 'M06.receipt_post'], $branch->id);
    $grant($reviewer, ['M06.receipt_approve', 'M06.issue_approve', 'M06.count_approve', 'M06.adjustment_create', 'M06.adjustment_approve', 'M06.adjustment_post', 'M06.transfer_approve'], $branch->id);
    foreach (['M06.warehouse_view', 'M06.warehouse_create', 'M06.warehouse_update'] as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $creator->id, 'permission_code' => $code, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M02.product_view', 'M03.supplier_view'] as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $creator->id, 'permission_code' => $code, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('access_grants')->insertOrIgnore(['user_id' => $reviewer->id, 'permission_code' => 'M02.product_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insertOrIgnore(['user_id' => $reviewer->id, 'permission_code' => 'M03.supplier_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_branch_scopes')->where('user_id', $creator->id)->update(['updated_at' => now()]);
    $warehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => 'CHECK-WH-'.strtoupper(Str::random(6)), 'name' => 'Regression warehouse', 'branch_id' => $branch->id, 'active' => true]);
    $warehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('code'), 'name' => 'Regression warehouse edit two', 'branch_id' => $branch->id, 'active' => true], $warehouse['id'], $warehouse['version']);
    $warehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('code'), 'name' => 'Regression warehouse edit three', 'branch_id' => $branch->id, 'active' => true], $warehouse['id'], $warehouse['version']);
    foreach ([$creator, $reviewer] as $u) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $u->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    Livewire::actingAs($creator)->test(WarehousesPage::class)->call('edit', $warehouse['id'])->set('form.name', 'Regression warehouse UI third save')->call('save');
    $check(DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('name') === 'Regression warehouse UI third save', 'Warehouse persists two service edits and a third reopened Livewire edit/save');
    $warehouseCode = DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('code');
    $expect(ValidationException::class, fn () => app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => $warehouseCode, 'name' => 'Duplicate code', 'branch_id' => $branch->id, 'active' => true]), 'Warehouse create rejects a duplicate code');
    $destinationWarehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => 'CHECK-DST-'.strtoupper(Str::random(6)), 'name' => 'Regression destination', 'branch_id' => $branch->id, 'active' => true]);
    foreach ([$creator, $reviewer] as $u) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $u->id, 'warehouse_id' => $destinationWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $req = app(RequisitionActions::class);
    $rfq = app(RfqActions::class);
    $offers = app(SupplierOffers::class);
    $orders = app(PurchaseOrders::class);
    $receiving = app(PurchaseReceiving::class);
    $reqData = ['creation_key' => $uuid(), 'branch_id' => $branch->id, 'request_date' => $today, 'required_date' => null, 'department' => 'Regression', 'purpose' => 'Lifecycle regression fixture', 'notes' => 'first', 'lines' => [['product_id' => $product->id, 'quantity' => '5.00000000', 'required_date' => null, 'notes' => 'line first']]];
    $requisition = $req->save($creator->id, $reqData, null, 0);
    $assertReq = static function ($doc, string $purpose, string $quantity) use ($check): void {
        $fresh = PurchaseRequisition::with('lines')->findOrFail($doc->id);
        $check($fresh->purpose === $purpose && $fresh->lines->first()->quantity === $quantity, 'Requisition persists expected reopened values and exact decimal');
    };
    $assertReq($requisition, 'Lifecycle regression fixture', '5.00000000');
    $reqData['purpose'] = 'Lifecycle regression edit';
    $reqData['notes'] = 'second';
    $reqData['lines'][0]['quantity'] = '6.12500000';
    $reqData['lines'][0]['notes'] = 'line second';
    $requisition = $req->save($creator->id, $reqData, $requisition->id, $requisition->version);
    $assertReq($requisition, 'Lifecycle regression edit', '6.12500000');
    $reqData['purpose'] = 'Lifecycle regression third';
    $reqData['lines'][0]['quantity'] = '7.25000000';
    $requisition = $req->save($creator->id, $reqData, $requisition->id, $requisition->version);
    $assertReq($requisition, 'Lifecycle regression third', '7.25000000');
    $duplicateReq = $req->save($creator->id, $reqData, null, 0);
    $check($duplicateReq->id === $requisition->id, 'Requisition same-key replay is idempotent');
    $uiKey = $uuid();
    $uiReq = Livewire::actingAs($creator)->test(RequisitionPage::class)
        ->set('form.branch_id', (string) $branch->id)->set('form.request_date', $today)->set('form.required_date', null)->set('form.department', null)->set('form.notes', null)->set('form.purpose', 'Livewire locked key regression')
        ->set('form.lines', [['product_id' => $product->id, 'quantity' => '1.00000000', 'required_date' => null, 'notes' => null]])
        ->set('form.creation_key', $uiKey)->call('save');
    $serverKey = $uiReq->get('creationKey');
    $check($serverKey !== $uiKey && DB::table('purchase_requisitions')->where('creation_key', $serverKey)->exists() && ! DB::table('purchase_requisitions')->where('creation_key', $uiKey)->exists(), 'Requisition Livewire save ignores forged form creation_key and uses locked server key');
    $req->transition($creator->id, $requisition->id, $requisition->version, 'submit');
    $requisition->refresh();
    $expect(ValidationException::class, fn () => $req->transition($creator->id, $requisition->id, $requisition->version, 'approve'), 'Requisition blocks creator self-approval');
    $requisition = $req->transition($reviewer->id, $requisition->id, $requisition->version, 'approve');
    $expect(ValidationException::class, fn () => $req->save($creator->id, $reqData, $requisition->id, $requisition->version), 'Approved requisition rejects later content edits');

    $sourceLine = $requisition->lines()->first();
    $rfqData = ['creation_key' => $uuid(), 'requisition_id' => $requisition->id, 'subject' => 'Regression RFQ', 'request_date' => $today, 'deadline' => null, 'requirements' => '', 'lines' => [['requisition_line_id' => $sourceLine->id, 'quantity' => '7.25000000']], 'supplier_ids' => [$supplier->id]];
    $rfqDoc = $rfq->save($creator->id, $rfqData, null, 0);
    $rfqData['subject'] = 'Regression RFQ second';
    $rfqDoc = $rfq->save($creator->id, $rfqData, $rfqDoc->id, $rfqDoc->version);
    $rfqData['subject'] = 'Regression RFQ third';
    $rfqDoc = $rfq->save($creator->id, $rfqData, $rfqDoc->id, $rfqDoc->version);
    $check(DB::table('purchasing_rfqs')->where('id', $rfqDoc->id)->value('subject') === 'Regression RFQ third' && DB::table('purchasing_rfq_lines')->where('rfq_id', $rfqDoc->id)->value('quantity') === '7.25000000' && DB::table('purchasing_rfq_suppliers')->where('rfq_id', $rfqDoc->id)->count() === 1, 'RFQ survives create, two reopen/edit/save cycles with supplier and line persistence');
    $excessRfq = $rfqData;
    $excessRfq['creation_key'] = $uuid();
    $excessRfq['lines'][0]['quantity'] = '7.25000001';
    $expect(ValidationException::class, fn () => $rfq->save($creator->id, $excessRfq, null, 0), 'RFQ rejects allocation above approved requisition quantity');
    Livewire::actingAs($creator)->test(RfqPage::class, ['rfq' => $rfqDoc->id])->set('form.subject', 'RFQ UI edit two')->call('save')->set('form.subject', 'RFQ UI edit three')->call('save');
    $check(DB::table('purchasing_rfqs')->where('id', $rfqDoc->id)->value('subject') === 'RFQ UI edit three', 'RFQ Livewire reopens persisted draft, edits, saves, and reopens for a third save');
    $rfqDoc = PurchaseRfq::findOrFail($rfqDoc->id);
    $rfq->issue($creator->id, $rfqDoc->id, $rfqDoc->version);

    $rfqLine = DB::table('purchasing_rfq_lines')->where('rfq_id', $rfqDoc->id)->first();
    $offerData = ['creation_key' => $uuid(), 'rfq_id' => $rfqDoc->id, 'supplier_id' => $supplier->id, 'previous_id' => null, 'supplier_reference' => 'REG-1', 'document_date' => $today, 'valid_until' => null, 'currency' => 'EGP', 'lead_days' => 3, 'payment_terms' => '30 days', 'warranty' => '', 'notes' => 'offer first', 'reported_tax' => null, 'lines' => [['rfq_line_id' => $rfqLine->id, 'quantity' => '7.25000000', 'unit_price' => '12.00000000', 'discount_amount' => '0.00000000', 'specification' => 'first']]];
    $offerId = $offers->save($creator->id, $offerData, null, 0);
    $offerData['notes'] = 'offer second';
    $offerData['lines'][0]['specification'] = 'second';
    $offerId = $offers->save($creator->id, $offerData, $offerId, 1);
    $offerData['notes'] = 'offer third';
    $offerData['lines'][0]['specification'] = 'third';
    $offerId = $offers->save($creator->id, $offerData, $offerId, 2);
    $check(DB::table('purchasing_offers')->where('id', $offerId)->value('notes') === 'offer third' && DB::table('purchasing_offer_lines')->where('offer_id', $offerId)->value('specification') === 'third', 'Supplier offer survives create, two edit cycles, and exact line persistence');
    Livewire::actingAs($creator)->test(OfferPage::class, ['offer' => $offerId])->set('form.notes', 'Offer UI edit two')->call('save')->set('form.notes', 'Offer UI edit three')->call('save');
    $check(DB::table('purchasing_offers')->where('id', $offerId)->value('notes') === 'Offer UI edit three', 'Supplier offer Livewire reopens persisted draft and completes repeated edit/save cycles');
    $excessOffer = $offerData;
    $excessOffer['creation_key'] = $uuid();
    $excessOffer['lines'][0]['quantity'] = '7.25000001';
    $expect(ValidationException::class, fn () => $offers->save($creator->id, $excessOffer, null, 0), 'Supplier offer rejects quantity above issued RFQ line');
    $foreignOffer = $offerData;
    $foreignOffer['creation_key'] = $uuid();
    $foreignOffer['supplier_id'] = Supplier::factory()->create(['status' => 'active'])->id;
    $expect(ValidationException::class, fn () => $offers->save($creator->id, $foreignOffer, null, 0), 'Supplier offer rejects a supplier outside the issued RFQ invite list');
    $offerVersion = (int) DB::table('purchasing_offers')->where('id', $offerId)->value('version');
    $offers->freeze($creator->id, $offerId, $offerVersion);
    $awardId = $offers->propose($creator->id, $offerId, 'Synthetic independent reviewer lifecycle');
    $expect(ValidationException::class, fn () => $offers->decide($creator->id, $awardId, 1, true), 'Award proposer cannot approve own award');
    $offers->decide($reviewer->id, $awardId, 1, true);

    $orderData = ['creation_key' => $uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'award_id' => $awardId, 'order_date' => $today, 'expected_date' => null, 'currency' => 'EGP', 'payment_terms' => '30 days', 'notes' => 'order first', 'reported_tax' => null, 'lines' => [['product_id' => $product->id, 'quantity' => '7.25000000', 'unit_price' => '12.00000000', 'discount_amount' => '0.00000000']]];
    $orderId = $orders->save($creator->id, $orderData, null, 0);
    $orderData['notes'] = 'order second';
    $orderId = $orders->save($creator->id, $orderData, $orderId, 1);
    $orderData['notes'] = 'order third';
    $orderId = $orders->save($creator->id, $orderData, $orderId, 2);
    $check(DB::table('purchasing_orders')->where('id', $orderId)->value('notes') === 'order third' && DB::table('purchasing_order_lines')->where('order_id', $orderId)->value('net_amount') === '87.00000000', 'Purchase order survives repeated edits and exact decimal line amount');
    $uiOrderData = $orderData;
    $uiOrderData['creation_key'] = $uuid();
    $uiOrderData['award_id'] = null;
    $uiOrderData['notes'] = 'Order UI first';
    $uiOrderId = $orders->save($creator->id, $uiOrderData, null, 0);
    Livewire::actingAs($creator)->test(OrderPage::class, ['order' => $uiOrderId])->set('form.notes', 'Order UI edit two')->call('save')->set('form.notes', 'Order UI edit three')->call('save');
    $check(DB::table('purchasing_orders')->where('id', $uiOrderId)->value('notes') === 'Order UI edit three' && DB::table('purchasing_orders')->where('id', $uiOrderId)->value('award_id') === null, 'Purchase order Livewire repeated saves preserve nullable award_id');
    $directOrderData = $uiOrderData;
    $directOrderData['creation_key'] = $uuid();
    $directOrderData['notes'] = 'Direct order internal review';
    $directOrderId = $orders->save($creator->id, $directOrderData, null, 0);
    $orders->transition($creator->id, $directOrderId, 1, 'submit');
    $expect(ValidationException::class, fn () => $orders->transition($creator->id, $directOrderId, 2, 'approve'), 'Direct purchase order still blocks creator self-approval');
    $orders->transition($reviewer->id, $directOrderId, 2, 'approve');
    $directReview = DB::table('purchasing_order_reviews')->where('order_id', $directOrderId)->where('action', 'approve')->first();
    $check(DB::table('purchasing_orders')->where('id', $directOrderId)->value('status') === 'approved' && (int) DB::table('purchasing_orders')->where('id', $directOrderId)->value('approved_by') === $reviewer->id && json_decode($directReview->snapshot, true)['header']['notes'] === 'Direct order internal review', 'No-award direct PO allows an independently authorized internal approval with an immutable persisted review snapshot');
    $orderReplay = $orders->save($creator->id, $orderData, null, 0);
    $check($orderReplay === $orderId, 'Purchase order same-key create replay is idempotent after reopen/edit cycles');
    $changedOrder = $orderData;
    $changedOrder['notes'] = 'Changed replay payload';
    $expect(ValidationException::class, fn () => $orders->save($creator->id, $changedOrder, null, 0), 'Purchase order rejects same-key replay with changed payload');
    $orders->transition($creator->id, $orderId, 3, 'submit');
    $expect(ValidationException::class, fn () => $orders->transition($creator->id, $orderId, 4, 'approve'), 'Purchase order blocks creator self-approval');
    $orders->transition($reviewer->id, $orderId, 4, 'approve');
    $expect(ValidationException::class, fn () => $orders->save($creator->id, $orderData, $orderId, 5), 'Approved purchase order rejects later content edits');
    $orderLine = DB::table('purchasing_order_lines')->where('order_id', $orderId)->first();

    $receiptData = ['creation_key' => $uuid(), 'order_id' => $orderId, 'warehouse_id' => $warehouse['id'], 'receipt_date' => $today, 'delivery_reference' => 'REG-DELIVERY-1', 'notes' => 'receipt first', 'lines' => [['order_line_id' => $orderLine->id, 'quantity' => '2.00000000']]];
    $receiptId = $receiving->save($creator->id, $receiptData, null, 0);
    $receiptData['notes'] = 'receipt second';
    $receiptId = $receiving->save($creator->id, $receiptData, $receiptId, 1);
    $receiptData['notes'] = 'receipt third';
    $receiptId = $receiving->save($creator->id, $receiptData, $receiptId, 2);
    $check(DB::table('purchasing_receipts')->where('id', $receiptId)->value('notes') === 'receipt third' && DB::table('purchasing_receipt_lines')->where('receipt_id', $receiptId)->value('quantity') === '2.00000000', 'Purchase receipt survives repeated edits without posting inventory');
    Livewire::actingAs($creator)->test(ReceiptPage::class, ['receipt' => $receiptId])->set('form.notes', 'Receipt UI edit two')->call('save')->set('form.notes', 'Receipt UI edit three')->call('save');
    $check(DB::table('purchasing_receipts')->where('id', $receiptId)->value('notes') === 'Receipt UI edit three', 'Purchase receipt Livewire reopens draft and completes repeated edit/save cycles');
    $excessReceipt = $receiptData;
    $excessReceipt['creation_key'] = $uuid();
    $excessReceipt['lines'][0]['quantity'] = '9.00000000';
    $expect(ValidationException::class, fn () => $receiving->save($creator->id, $excessReceipt, null, 0), 'Purchase receipt rejects quantity above remaining approved order quantity');
    $receiptVersion = (int) DB::table('purchasing_receipts')->where('id', $receiptId)->value('version');
    $physicalBefore = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $receiving->submit($creator->id, $receiptId, $receiptVersion);
    $check(DB::table('purchasing_receipts')->where('id', $receiptId)->value('status') === 'integration_pending' && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === $physicalBefore, 'Receipt lifecycle records integration pending and does not fabricate stock posting');
    $auditCountAfterReceiptSubmit = DB::table('audit_entries')->count();
    $receiptVersionAfterSubmit = (int) DB::table('purchasing_receipts')->where('id', $receiptId)->value('version');
    $receiving->submit($creator->id, $receiptId, $receiptVersion);
    $check((int) DB::table('purchasing_receipts')->where('id', $receiptId)->value('version') === $receiptVersionAfterSubmit && DB::table('audit_entries')->count() === $auditCountAfterReceiptSubmit && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === $physicalBefore, 'Duplicate purchase receipt submit is idempotent and adds no audit or stock effects');

    $financialMappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label,$kind]) {
        $financialMappings[$purpose] = AccountingAccount::factory()->create(['code' => 'M05M06-'.strtoupper($purpose).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic '.$purpose, 'kind' => $kind])->id;
    }
    $openingEquity = AccountingAccount::factory()->create(['code' => 'M05M06-EQUITY-'.strtoupper(Str::random(5)), 'name' => 'Synthetic opening equity', 'kind' => 'equity'])->id;
    DB::table('accounting_periods')->insert(['name' => 'Synthetic M05/M06 current-year period', 'starts_on' => now('Africa/Cairo')->startOfYear()->toDateString(), 'ends_on' => now('Africa/Cairo')->endOfYear()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $financial = app(FinancialActivation::class);
    $financialVersion = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
    $financial->save($creator->id, $financialVersion, ['go_live_date' => $today, 'mappings' => $financialMappings]);
    $financialVersion++;
    $check(true, 'Financial activation date and all synthetic account mappings saved');
    foreach (['sale', 'purchase'] as $type) {
        $taxRule = ['code' => 'M05M06-'.strtoupper($type).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic validated '.$type.' rule', 'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => $today, 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic M05/M06 test fixture'];
        if ($type === 'purchase') {
            $taxRule['purchase_recoverable'] = true;
        }
        $financial->addTaxRule($creator->id, $financialVersion++, $taxRule);
    }
    $financial->validateTaxTreatment($creator->id, $financialVersion++, 'Synthetic M05/M06 isolated test validation');
    $check(DB::table('accounting_activation')->where('id', 1)->value('tax_validated') === true, 'Synthetic sale and purchase rules were explicitly validated');
    $openingLines = [
        ['kind' => 'gl', 'reference' => 'M05M06-INVENTORY-GL', 'branch_id' => $branch->id, 'account_id' => $financialMappings['inventory'], 'debit' => '100.00', 'credit' => '0.00'],
        ['kind' => 'gl', 'reference' => 'M05M06-EQUITY-GL', 'branch_id' => $branch->id, 'account_id' => $openingEquity, 'debit' => '0.00', 'credit' => '100.00'],
        ['kind' => 'inventory', 'reference' => 'M05M06-STOCK-OPEN', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse['id'], 'product_id' => $product->id, 'quantity' => '10.00000000', 'unit_cost' => '10.0000000000000000', 'amount' => '100.00'],
    ];
    $openings = app(OpeningBalances::class);
    $openingBatch = $openings->save($creator->id, $financialVersion, $uuid(), $openingLines, false, 'Synthetic M05/M06 detailed opening');
    $check((bool) $openingBatch, 'Balanced synthetic accounting/inventory opening batch saved');
    $openings->advance($creator->id, $openingBatch, 1, 'submit');
    $check(DB::table('accounting_opening_batches')->where('id', $openingBatch)->value('status') === 'submitted', 'Opening submitted for independent review');
    $openings->advance($reviewer->id, $openingBatch, 2, 'approve');
    $check(DB::table('accounting_opening_batches')->where('id', $openingBatch)->value('status') === 'approved', 'Independent reviewer approved the exact opening batch');
    app(ActivateFinancialPosting::class)->execute($reviewer->id, $openingBatch, 3);
    $check(DB::table('accounting_opening_batches')->where('id', $openingBatch)->value('status') === 'posted' && DB::table('inventory_movements')->where('operation', 'financial_opening')->where('source_id', (string) $openingBatch)->exists(), 'Financial setup independently approves and activates an exact synthetic Inventory opening before API checks');
    $doc = app(InventoryDocuments::class);
    $warehouseRow = DB::table('inventory_warehouses')->find($warehouse['id']);
    $expect(ValidationException::class, fn () => app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => $warehouseRow->code, 'name' => $warehouseRow->name, 'branch_id' => $branch->id, 'active' => false], $warehouseRow->id, $warehouseRow->version), 'Warehouse with posted stock cannot be deactivated');
    $inventoryApi = app(InventoryApi::class);
    $availability = $inventoryApi->getAvailability($creator->id, $warehouse['id'], $product->id);
    $check($availability['on_hand'] === '10.00000000' && $availability['reserved'] === '0.00000000' && $availability['available'] === '10.00000000' && $availability['in_transit'] === '0.00000000', 'Inventory API returns persisted exact availability after a valid independently reviewed opening post');
    $reconciliation = $inventoryApi->reconcileWarehouse($creator->id, $warehouse['id']);
    $check($reconciliation['reconciled'] && $reconciliation['products_checked'] === 1 && $reconciliation['discrepancies'] === [], 'Read-only warehouse reconciliation matches the immutable movement ledger and balance projection');
    $drift = null;
    try {
        DB::transaction(function () use ($warehouse, $product, $inventoryApi, $creator, &$drift): void {
            DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->update(['on_hand' => '9.00000000']);
            $drift = $inventoryApi->reconcileWarehouse($creator->id, $warehouse['id']);
            throw new RuntimeException('Rollback temporary reconciliation drift fixture.');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Rollback temporary reconciliation drift fixture.') {
            throw $e;
        }
    }
    $check($drift && ! $drift['reconciled'] && count($drift['discrepancies']) === 1 && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '10.00000000', 'Warehouse reconciliation reports projection drift without repair, and its savepoint fixture rolls back');
    $unauthorizedInventoryUser = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $expect(HttpException::class, fn () => $inventoryApi->reconcileWarehouse($unauthorizedInventoryUser->id, $warehouse['id']), 'Warehouse reconciliation requires explicit balance, movement, reservation and warehouse authority');
    $reservationViewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $reservationViewer->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $reservationViewer->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($reservationViewer, ['M01.branch_view', 'M06.reservation_view'], $branch->id);
    $reservationScreen = Livewire::actingAs($reservationViewer)->test(InventoryPage::class, ['screen' => 'reservations']);
    $reservationScreen->assertViewHas('canViewBalanceSummary', false)->assertDontSee('أرصدة متاحة منعدمة');
    $check(! DB::table('access_grants')->where('user_id', $reservationViewer->id)->where('permission_code', 'M06.balance_view')->exists(), 'Reservation-only Livewire actor sees no balance summary cards or empty-stock count');
    $barcode = 'M05M06-'.strtoupper(Str::random(12));
    $product->barcode = $barcode;
    $product->save();
    $barcodeUi = Livewire::actingAs($creator)->test(InventoryDocumentPage::class, ['kind' => 'count'])
        ->set('form.warehouse_id', (string) $warehouse['id'])->set('form.reason', 'Barcode count verification')->set('barcode', $barcode)->call('addByBarcode');
    $barcodeLines = $barcodeUi->get('form.lines');
    $barcodeUi->set('barcode', $barcode)->call('addByBarcode');
    $check(count($barcodeLines) === 1 && (int) $barcodeLines[0]['product_id'] === $product->id && $barcodeLines[0]['quantity'] === '' && count($barcodeUi->get('form.lines')) === 1, 'Count barcode selects the known product once without inferring quantity or duplicating the line');
    $barcodeUi->set('barcode', 'UNKNOWN-'.$barcode)->call('addByBarcode')->assertHasErrors('barcode');
    $check(count($barcodeUi->get('form.lines')) === 1 && (int) $barcodeUi->get('form.lines.0.product_id') === $product->id, 'Count barcode rejects an unknown product without changing the selected line');
    $barcodeUi->set('form.lines.0.quantity', '2.75000000')->call('save');
    $barcodeCountId = (int) $barcodeUi->get('document');
    $check($barcodeCountId > 0 && DB::table('inventory_document_lines')->where('document_id', $barcodeCountId)->value('quantity') === '2.75000000', 'Count barcode selection persists only after the operator enters and saves an explicit quantity');
    $invalidTransition = $expect(QueryException::class, fn () => DB::transaction(fn () => DB::table('inventory_documents')->where('id', $barcodeCountId)->update(['status' => 'posted', 'version' => DB::raw('version + 1'), 'approved_by' => $reviewer->id, 'approved_at' => now()])), 'Database guard rejects a raw count lifecycle transition that bypasses required states');
    $check(str_contains($invalidTransition->getMessage(), 'Invalid inventory lifecycle transition'), 'Rejected raw count transition reaches the lifecycle guard rather than failing on self-approval metadata');
    $check(DB::table('inventory_documents')->where('id', $barcodeCountId)->value('status') === 'draft', 'Rejected raw lifecycle transition leaves the persisted count in draft');
    $openingLine = ['product_id' => $product->id, 'base_unit_id' => $product->base_unit_id, 'sku' => $product->sku, 'product_name' => $product->name, 'serials' => []];
    $openingMovement = DB::table('inventory_movements')->where('operation', 'financial_opening')->where('source_id', (string) $openingBatch)->firstOrFail();
    $movementCount = DB::table('inventory_movements')->where('operation', 'financial_opening')->where('source_id', (string) $openingBatch)->count();
    $balanceBeforeCollision = $availability['on_hand'];
    $expect(ValidationException::class, fn () => DB::transaction(fn () => app(InventoryLedger::class)->post($creator->id, $warehouse['id'], $openingLine, '10.00000000', 'financial_opening', 'OtherOwner', 'different-source', 'different-line', $openingMovement->idempotency_key, null)), 'Inventory ledger idempotency rejects an existing key replayed with changed source identity');
    $check(DB::table('inventory_movements')->where('operation', 'financial_opening')->where('source_id', (string) $openingBatch)->count() === $movementCount && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === $balanceBeforeCollision, 'Changed-source idempotency collision leaves movement count and balance unchanged');
    $inventoryQuery = app(InventoryQuery::class);
    $openingRows = $inventoryQuery->movements($creator->id);
    $inventoryQuery->filter($openingRows, 'inventory_movements', ['operation' => 'financial_opening']);
    $wrongOperationRows = $inventoryQuery->movements($creator->id);
    $inventoryQuery->filter($wrongOperationRows, 'inventory_movements', ['operation' => 'issue_post']);
    $lowRows = $inventoryQuery->balances($creator->id);
    $inventoryQuery->filter($lowRows, 'inventory_balances', ['status' => 'low']);
    $check($openingRows->count() === 1 && $wrongOperationRows->count() === 0 && $lowRows->count() === 0, 'Shared inventory filters match the persisted opening movement and exclude wrong operations and non-low balances');

    $transferDraftId = null;
    foreach (['issue' => 'issue', 'adjustment' => 'adjustment', 'transfer' => 'transfer'] as $kind => $label) {
        $draftData = ['warehouse_id' => $warehouse['id'], 'reason' => 'UI '.$label.' first', 'creation_key' => $uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => $kind === 'adjustment' ? '-0.50000000' : '0.50000000']]];
        if ($kind === 'transfer') {
            $draftData['destination_id'] = $destinationWarehouse['id'];
        }
        $draft = $doc->save($creator->id, $kind, $draftData, null, 0);
        Livewire::actingAs($creator)->test(InventoryDocumentPage::class, ['kind' => $kind, 'document' => $draft['id']])->set('form.reason', 'UI '.$label.' edit two')->set('form.lines.0.quantity', $kind === 'adjustment' ? '-0.75000000' : '0.75000000')->call('save')->set('form.reason', 'UI '.$label.' edit three')->set('form.lines.0.quantity', $kind === 'adjustment' ? '-1.00000000' : '1.00000000')->call('save');
        $check(DB::table('inventory_documents')->where('id', $draft['id'])->value('reason') === 'UI '.$label.' edit three' && DB::table('inventory_document_lines')->where('document_id', $draft['id'])->value('quantity') === ($kind === 'adjustment' ? '-1.00000000' : '1.00000000'), 'Inventory '.$label.' Livewire draft reopens and completes repeated edit/save cycles');
        if ($kind === 'transfer') {
            $transferDraftId = $draft['id'];
        }
    }
    $sourceOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $sourceOnly->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $sourceOnly->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($sourceOnly, ['M06.transfer_create', 'M06.export'], $branch->id);
    $queryTransfer = app(InventoryQuery::class)->documents($sourceOnly->id, 'transfer')->where('id', $transferDraftId)->exists();
    $check(! $queryTransfer, 'Transfer document query requires the actor to cover both source and destination warehouses');
    Auth::loginUsingId($sourceOnly->id);
    $exportRequest = Request::create('/inventory/export/transfer', 'GET');
    app()->instance('request', $exportRequest);
    $exportResponse = app(InventoryExportController::class)('transfer');
    $transferExportPath = $exportResponse->getFile()->getPathname();
    $transferBook = (new Xlsx)->load($transferExportPath);
    $transferRows = $transferBook->getSheetByName('Data')->toArray();
    $transferBook->disconnectWorksheets();
    @unlink($transferExportPath);
    Auth::loginUsingId($creator->id);
    $check(count($transferRows) === 1, 'Transfer XLSX export excludes documents whose destination is outside the actor warehouse scope');

    $destinationBranch = Branch::factory()->create(['code' => 'CHECK-XBR-'.strtoupper(Str::random(7))]);
    DB::table('user_branch_scopes')->insert(['user_id' => $creator->id, 'branch_id' => $destinationBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($creator, ['M01.branch_view', 'M06.transfer_create', 'M06.warehouse_create'], $destinationBranch->id);
    $crossBranchWarehouse = app(InventoryActions::class)->saveWarehouse($creator->id, ['code' => 'CHECK-XBR-WH-'.strtoupper(Str::random(6)), 'name' => 'Cross branch destination', 'branch_id' => $destinationBranch->id, 'active' => true]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $creator->id, 'warehouse_id' => $crossBranchWarehouse['id'], 'branch_id' => $destinationBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    $mixedTransfer = $doc->save($creator->id, 'transfer', ['warehouse_id' => $warehouse['id'], 'destination_id' => $crossBranchWarehouse['id'], 'reason' => 'Cross branch mixed capability query fixture', 'creation_key' => $uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '0.25000000']]], null, 0);
    $observer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$branch->id, $destinationBranch->id] as $scopeBranch) {
        DB::table('user_branch_scopes')->insert(['user_id' => $observer->id, 'branch_id' => $scopeBranch, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ([[$warehouse['id'], $branch->id], [$crossBranchWarehouse['id'], $destinationBranch->id]] as [$scopeWarehouse,$scopeBranch]) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $observer->id, 'warehouse_id' => $scopeWarehouse, 'branch_id' => $scopeBranch, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($observer, ['M06.transfer_create'], $branch->id);
    $grant($observer, ['M06.transfer_receive'], $destinationBranch->id);
    $mixedVisible = app(InventoryQuery::class)->documents($observer->id, 'transfer')->where('id', $mixedTransfer['id'])->exists();
    $check(! $mixedVisible && app(InventoryQuery::class)->documents($creator->id, 'transfer')->where('id', $mixedTransfer['id'])->exists(), 'Transfer query does not union create authority at source with receive authority at destination across branches');

    $inventoryData = ['warehouse_id' => $warehouse['id'], 'reason' => 'Repeated count draft edit', 'lines' => [['product_id' => $product->id, 'quantity' => '4.50000000']]];
    $inventoryData['creation_key'] = $uuid();
    $inv = $doc->save($creator->id, 'count', $inventoryData, null, 0);
    $countCutoff = (int) DB::table('inventory_documents')->where('id', $inv['id'])->value('cutoff');
    $countRecorded = DB::table('inventory_document_lines')->where('document_id', $inv['id'])->value('recorded');
    $inventoryData['lines'][0]['quantity'] = '5.50000000';
    $inv = $doc->save($creator->id, 'count', $inventoryData, $inv['id'], $inv['version']);
    $inventoryData['lines'][0]['quantity'] = '6.50000000';
    $inv = $doc->save($creator->id, 'count', $inventoryData, $inv['id'], $inv['version']);
    $fresh = InventoryDocument::findOrFail($inv['id']);
    $line = InventoryDocumentLine::where('document_id', $inv['id'])->firstOrFail();
    $check($line->quantity === '6.50000000' && $fresh->status === 'draft', 'Inventory count document survives create, reopen, and two edit/save cycles');
    $check((int) $fresh->cutoff === $countCutoff && $line->recorded === $countRecorded, 'Count edits preserve the original stock cutoff and recorded quantity snapshot');
    Livewire::actingAs($creator)->test(InventoryDocumentPage::class, ['kind' => 'count', 'document' => $inv['id']])->set('form.lines.0.quantity', '7.50000000')->call('save')->set('form.lines.0.quantity', '8.50000000')->call('save');
    $fresh = InventoryDocument::findOrFail($inv['id']);
    $line = InventoryDocumentLine::where('document_id', $inv['id'])->firstOrFail();
    $check($line->quantity === '8.50000000' && (int) $fresh->cutoff === $countCutoff && $line->recorded === $countRecorded, 'Inventory count Livewire reopens saved draft through repeated saves while preserving its snapshot');
    $issueData = ['warehouse_id' => $warehouse['id'], 'reason' => 'Move stock after count began', 'creation_key' => $uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '1.00000000']]];
    $interveningIssue = $doc->save($creator->id, 'issue', $issueData, null, 0);
    $interveningIssue = $doc->transition($creator->id, $interveningIssue['id'], 'submit', $interveningIssue['version']);
    $interveningIssue = $doc->transition($reviewer->id, $interveningIssue['id'], 'approve', $interveningIssue['version']);
    $interveningIssue = $doc->transition($creator->id, $interveningIssue['id'], 'post', $interveningIssue['version']);
    $staleEdit = $inventoryData;
    $staleEdit['creation_key'] = $inventoryData['creation_key'];
    $staleEdit['lines'][0]['quantity'] = '8.00000000';
    $expect(ValidationException::class, fn () => $doc->save($creator->id, 'count', $staleEdit, $inv['id'], (int) $fresh->version), 'Editing a count draft after a valid stock posting rejects its stale snapshot');
    $freshAfterReject = InventoryDocument::findOrFail($inv['id']);
    $lineAfterReject = InventoryDocumentLine::where('document_id', $inv['id'])->firstOrFail();
    $check((int) $freshAfterReject->cutoff === $countCutoff && $lineAfterReject->recorded === $countRecorded && $lineAfterReject->quantity === '8.50000000', 'Rejected stale count edit preserves cutoff, recorded stock and original count quantity');

    $approvedCount = $doc->save($creator->id, 'count', ['warehouse_id' => $warehouse['id'], 'reason' => 'Valid count with approved variance', 'creation_key' => $uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '10.00000000']]], null, 0);
    $approvedCount = $doc->transition($creator->id, $approvedCount['id'], 'submit', $approvedCount['version']);
    $approvedCount = $doc->transition($reviewer->id, $approvedCount['id'], 'approve', $approvedCount['version']);
    $linkedAdjustmentId = (int) DB::table('inventory_documents')->where('id', $approvedCount['id'])->value('linked_document_id');
    $linkedAdjustment = DB::table('inventory_documents')->find($linkedAdjustmentId);
    $check($approvedCount['status'] === 'posted' && $linkedAdjustment && $linkedAdjustment->kind === 'adjustment' && $linkedAdjustment->status === 'draft' && (int) $linkedAdjustment->cutoff === (int) $approvedCount['cutoff'], 'Approved count creates a real linked adjustment carrying the source count cutoff');
    $linkedAdjustmentLine = DB::table('inventory_document_lines')->where('document_id', $linkedAdjustmentId)->firstOrFail();
    $linkedAdjustment = $doc->save($reviewer->id, 'adjustment', ['creation_key' => $linkedAdjustment->creation_key, 'warehouse_id' => $warehouse['id'], 'reason' => $linkedAdjustment->reason,
        'lines' => [['product_id' => $product->id, 'quantity' => $linkedAdjustmentLine->quantity, 'unit_cost' => '10.0000000000000000', 'cost_reference' => 'Synthetic FIFO-based count variance valuation']]], $linkedAdjustmentId, $linkedAdjustment->version);
    $check(DB::table('inventory_document_lines')->where('document_id', $linkedAdjustmentId)->value('unit_cost') === '10.0000000000000000'
        && DB::table('inventory_document_lines')->where('document_id', $linkedAdjustmentId)->value('cost_reference') === 'Synthetic FIFO-based count variance valuation', 'Positive count variance receives explicit synthetic FIFO unit-cost provenance before review');
    $linkedAdjustment = $doc->transition($reviewer->id, $linkedAdjustmentId, 'submit', $linkedAdjustment['version']);
    $linkedAdjustment = $doc->transition($creator->id, $linkedAdjustmentId, 'approve', $linkedAdjustment['version']);
    $secondIssueData = ['warehouse_id' => $warehouse['id'], 'reason' => 'Movement after adjustment approval', 'creation_key' => $uuid(), 'lines' => [['product_id' => $product->id, 'quantity' => '1.00000000']]];
    $secondIssue = $doc->save($creator->id, 'issue', $secondIssueData, null, 0);
    $secondIssue = $doc->transition($creator->id, $secondIssue['id'], 'submit', $secondIssue['version']);
    $secondIssue = $doc->transition($reviewer->id, $secondIssue['id'], 'approve', $secondIssue['version']);
    $secondIssue = $doc->transition($creator->id, $secondIssue['id'], 'post', $secondIssue['version']);
    $balanceBeforeStaleAdjustment = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $movementCountBeforeStaleAdjustment = DB::table('inventory_movements')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->count();
    $expect(ValidationException::class, fn () => $doc->transition($reviewer->id, $linkedAdjustmentId, 'post', $linkedAdjustment['version']), 'Linked count adjustment post rejects stock movement after independent approval');
    $check(DB::table('inventory_documents')->where('id', $linkedAdjustmentId)->value('status') === 'approved' && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === $balanceBeforeStaleAdjustment && DB::table('inventory_movements')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->count() === $movementCountBeforeStaleAdjustment, 'Rejected stale adjustment post leaves approved draft and physical ledger unchanged');

    foreach (['1.00000000', '-1.00000000'] as $delta) {
        $adjustmentLine = ['product_id' => $product->id, 'quantity' => $delta];
        if ($delta[0] !== '-') {
            $adjustmentLine += ['unit_cost' => '10.0000000000000000', 'cost_reference' => 'Synthetic signed variance FIFO valuation'];
        }
        $adjustment = $doc->save($creator->id, 'adjustment', ['warehouse_id' => $warehouse['id'], 'reason' => 'Verified signed adjustment '.$delta, 'creation_key' => $uuid(), 'lines' => [$adjustmentLine]], null, 0);
        $adjustment = $doc->transition($creator->id, $adjustment['id'], 'submit', $adjustment['version']);
        $adjustment = $doc->transition($reviewer->id, $adjustment['id'], 'approve', $adjustment['version']);
        $adjustment = $doc->transition($creator->id, $adjustment['id'], 'post', $adjustment['version']);
    }
    $finalOnHand = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $adjustmentMoves = DB::table('inventory_movements')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->where('operation', 'adjustment_post')->count();
    $check(bccomp((string) $finalOnHand, '8.00000000', 8) === 0 && (int) $adjustmentMoves === 2, 'Independently approved positive and negative adjustment documents post exact signed ledger movements (on_hand='.$finalOnHand.', count='.$adjustmentMoves.', cmp='.bccomp((string) $finalOnHand, '8.00000000', 8).')');
    $check($line->product_id === $product->id && $fresh->warehouse_id === $warehouse['id'], 'Inventory reopened document retains warehouse and product relationship');
    echo "M05/M06 lifecycle round-trip regression checks passed.\n";
} finally {
    DB::rollBack();
    echo "Disposable PostgreSQL test transaction rolled back.\n";
}

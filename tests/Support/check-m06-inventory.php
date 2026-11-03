<?php

use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Inventory\Application\InventoryConditions;
use App\Modules\Inventory\Application\InventoryCorrections;
use App\Modules\Inventory\Application\InventoryDocuments;
use App\Modules\Inventory\Application\InventoryLineImport;
use App\Modules\Inventory\Application\InventoryQuery;
use App\Modules\Inventory\Application\InventoryReservations;
use App\Modules\Inventory\Application\InventoryTransfers;
use App\Modules\Inventory\Domain\InventoryDocument;
use App\Modules\Inventory\Domain\InventoryDocumentLine;
use App\Modules\Inventory\Domain\InventoryHistoryReader;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Inventory\Presentation\InventoryDocumentPage;
use App\Modules\Inventory\Presentation\InventoryDocumentsPage;
use App\Modules\Inventory\Presentation\InventoryExportController;
use App\Modules\Inventory\Presentation\InventoryOperationsPage;
use App\Modules\Inventory\Presentation\InventoryPage;
use App\Modules\Inventory\Presentation\WarehousesPage;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M06 check requires the exact separate PostgreSQL test database.');
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
};
$expect = static function (string $class, Closure $action, string $message) use ($verify): Throwable {
    try {
        $action();
    } catch (Throwable $exception) {
        $verify($exception instanceof $class, $message.' (actual '.$exception::class.': '.$exception->getMessage().')');

        return $exception;
    }
    throw new RuntimeException($message.' (no exception)');
};
$grant = static function (User $user, array $permissions, ?int $branchId = null): void {
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insertOrIgnore([
            'user_id' => $user->id,
            'permission_code' => $permission,
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

$companyBaseline = DB::table('companies')->where('id', 1)->first();
$companyBaseline = $companyBaseline === null ? null : (array) $companyBaseline;
DB::beginTransaction();
$temporaryFiles = [];
try {
    $registered = DB::table('permission_catalog')->where('code', 'like', 'M06.%')->pluck('code')->sort()->values()->all();
    $expected = collect(array_keys(PermissionCatalog::DEFINITIONS))->filter(fn ($code) => str_starts_with($code, 'M06.'))->sort()->values()->all();
    $adminId = DB::table('roles')->where('code', 'Administrator')->value('id');
    $admin = DB::table('role_permissions')->where('role_id', $adminId)->where('permission_code', 'like', 'M06.%')->pluck('permission_code')->sort()->values()->all();
    $verify($registered === $expected && $admin === $expected, 'M06 permissions are registered and default only to Administrator');

    DB::table('companies')->insertOrIgnore(['id' => 1, 'code' => 'M06-CHECK', 'name' => 'M06 synthetic check', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('companies')->where('id', 1)->update([
        'name' => 'M06 synthetic check',
        'legal_name' => 'Synthetic M06 Inventory Check Company',
        'tax_registration' => 'M06-CHECK-REG',
        'address' => 'Synthetic test address, Cairo, Egypt',
        'setup_complete' => true,
        'updated_at' => now(),
    ]);
    $demoBranches = [];
    for ($i = 1; $i <= 4; $i++) {
        $demoBranches[] = Branch::factory()->create(['code' => 'DEMO-EG-M06-'.$i]);
    }
    $disabledDemo = User::factory()->disabledDemo()->create();
    $demoProduct = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $factoryWarehouse = Warehouse::factory()->create(['code' => 'M06-FACTORY-WH', 'branch_id' => $demoBranches[0]->id]);
    $factoryDocument = InventoryDocument::factory()->create(['warehouse_id' => $factoryWarehouse->id, 'created_by' => $disabledDemo->id]);
    $factoryLine = InventoryDocumentLine::factory()->create(['document_id' => $factoryDocument->id, 'product_id' => $demoProduct->id]);
    $verify($factoryWarehouse->exists && $factoryDocument->exists && $factoryLine->exists
        && $factoryDocument->status === 'draft' && $factoryDocument->approved_by === null
        && $factoryLine->quantity === '12.00000000' && $factoryLine->product_id === $demoProduct->id
        && ! $disabledDemo->active && $disabledDemo->email_verified_at === null,
        'Warehouse and draft document factories persist a valid synthetic graph without operational approvals or stock effects');
    $demoCounts = fn () => [Warehouse::where('code', 'like', 'DEMO-EG-WH-%')->count(), InventoryDocument::whereIn('warehouse_id', Warehouse::where('code', 'like', 'DEMO-EG-WH-%')->select('id'))->count(), InventoryDocumentLine::whereIn('document_id', InventoryDocument::whereIn('warehouse_id', Warehouse::where('code', 'like', 'DEMO-EG-WH-%')->select('id'))->select('id'))->count()];
    app(InventoryDemoSeeder::class)->run();
    $seededCounts = $demoCounts();
    app(InventoryDemoSeeder::class)->run();
    $verify($demoCounts() === $seededCounts && $seededCounts[0] === 4 && $seededCounts[1] === 4 && $seededCounts[2] === 4,
        'Explicit inventory demo seeder reruns without duplicate warehouse or draft-document records');
    $branch = Branch::factory()->create(['code' => 'M06-BR-'.strtoupper(bin2hex(random_bytes(3)))]);
    $secondBranch = Branch::factory()->create(['code' => 'M06-BR-'.strtoupper(bin2hex(random_bytes(3)))]);
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $approver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([$actor, $approver] as $scopedUser) {
        DB::table('user_branch_scopes')->insert(['user_id' => $scopedUser->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($actor, ['M01.branch_view'], $branch->id);
    DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $secondBranch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($actor, ['M01.branch_view'], $secondBranch->id);
    $grant($actor, ['M06.warehouse_create', 'M06.warehouse_update', 'M06.warehouse_view']);
    $warehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Synthetic inventory warehouse', 'branch_id' => $branch->id, 'active' => true]);
    $saved = DB::table('inventory_warehouses')->find($warehouse['id']);
    $verify($saved && $saved->name === 'Synthetic inventory warehouse' && (int) $saved->branch_id === $branch->id && $warehouse['version'] === 1, 'Warehouse action persists a versioned warehouse on an explicitly scoped active branch');
    $updated = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $saved->code, 'name' => 'Edited synthetic warehouse', 'branch_id' => $branch->id, 'active' => true], $warehouse['id'], $warehouse['version']);
    $verify($updated['version'] === 2 && DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('name') === 'Edited synthetic warehouse', 'Warehouse action reopens and saves an edit using the expected version');
    $expect(ValidationException::class, fn () => app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $saved->code, 'name' => 'Stale overwrite', 'branch_id' => $branch->id, 'active' => true], $warehouse['id'], 1), 'Warehouse edit rejects a stale version');
    $expect(ValidationException::class, fn () => app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $saved->code, 'name' => 'Moved warehouse', 'branch_id' => $secondBranch->id, 'active' => true], $warehouse['id'], $updated['version']), 'Warehouse branch is fixed even when the actor is scoped to both branches');
    $verify((int) DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('branch_id') === $branch->id, 'Rejected cross-branch warehouse edit preserves its original branch');

    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $expect(HttpException::class, fn () => app(InventoryActions::class)->saveWarehouse($denied->id, ['code' => 'M06-DENIED', 'name' => 'Denied warehouse', 'branch_id' => $branch->id, 'active' => true]), 'Warehouse creation requires explicit M06 permission');

    $grant($actor, ['M02.product_view']);
    $operators = ['M06.dashboard_view', 'M06.balance_view', 'M06.movement_view', 'M06.reservation_view', 'M06.reservation_manage',
        'M06.isolation_manage', 'M06.isolation_release', 'M06.correction_execute', 'M06.serial_view',
        'M06.receipt_create', 'M06.receipt_approve', 'M06.receipt_post', 'M06.issue_create', 'M06.issue_post', 'M06.transfer_create', 'M06.transfer_dispatch', 'M06.transfer_receive',
        'M06.count_create', 'M06.count_submit', 'M06.adjustment_create', 'M06.adjustment_post'];
    $grant($actor, $operators, $branch->id);
    $grant($actor, ['M06.receipt_approve'], $branch->id);
    $grant($actor, ['M06.adjustment_approve'], $branch->id);
    $grant($approver, ['M06.receipt_approve', 'M06.receipt_post', 'M06.issue_approve', 'M06.transfer_approve', 'M06.count_approve', 'M06.adjustment_approve'], $branch->id);
    $grant($approver, ['M06.warehouse_view', 'M06.balance_view', 'M02.product_view']);
    $grant($approver, ['M06.balance_view'], $branch->id);
    DB::table('user_warehouse_scopes')->insert([
        ['user_id' => $actor->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $approver->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $secondWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-DST-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Synthetic destination warehouse', 'branch_id' => $branch->id, 'active' => true]);
    DB::table('user_warehouse_scopes')->insert([
        ['user_id' => $actor->id, 'warehouse_id' => $secondWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $approver->id, 'warehouse_id' => $secondWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $unit = Unit::factory()->create();
    $product = Product::factory()->create(['base_unit_id' => $unit->id, 'purchase_unit_id' => $unit->id, 'sales_unit_id' => $unit->id, 'tracking' => 'quantity', 'kind' => 'bulk']);
    $correctionWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-CORR-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Correction source warehouse', 'branch_id' => $branch->id, 'active' => true]);
    $issueCorrectionWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-ISSUE-CORR-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Issue correction warehouse', 'branch_id' => $branch->id, 'active' => true]);
    foreach ([$correctionWarehouse, $issueCorrectionWarehouse] as $costWarehouse) {
        DB::table('user_warehouse_scopes')->insert([
            ['user_id' => $actor->id, 'warehouse_id' => $costWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $approver->id, 'warehouse_id' => $costWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    $today = now('Africa/Cairo')->toDateString();
    foreach ([$actor, $approver] as $financialUser) {
        $codes = $financialUser->id === $actor->id
            ? ['M11.setup_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.opening_manage', 'M11.opening_entry', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_post']
            : ['M11.setup_view', 'M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_post'];
        foreach ($codes as $code) {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $financialUser->id, 'permission_code' => $code, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $grant($actor, ['M11.opening_entry', 'M11.opening_review'], $branch->id);
    $grant($actor, ['M11.journal_post'], $branch->id);
    $grant($approver, ['M11.opening_review', 'M11.opening_approve', 'M11.financial_activate', 'M11.journal_post'], $branch->id);
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = AccountingAccount::factory()->create(['code' => 'M06-'.strtoupper($purpose).'-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Synthetic M06 '.$purpose, 'kind' => $kind])->id;
    }
    $equity = AccountingAccount::factory()->create(['code' => 'M06-EQUITY-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Synthetic M06 opening equity', 'kind' => 'equity'])->id;
    DB::table('accounting_periods')->insert(['name' => 'Synthetic M06 focused current-year period', 'starts_on' => now('Africa/Cairo')->startOfYear()->toDateString(), 'ends_on' => now('Africa/Cairo')->endOfYear()->toDateString(), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $financial = app(FinancialActivation::class);
    $financialVersion = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
    $financial->save($actor->id, $financialVersion, ['go_live_date' => $today, 'mappings' => $mappings]);
    $financialVersion++;
    $verify(true, 'Synthetic M11 mappings and Go-Live saved through FinancialActivation');
    foreach (['sale', 'purchase'] as $type) {
        $rule = ['code' => 'M06-'.strtoupper($type).'-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Synthetic M06 validated '.$type.' tax rule', 'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => $today, 'ends_on' => null, 'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic M06 acceptance fixture'];
        if ($type === 'purchase') {
            $rule['purchase_recoverable'] = true;
        }
        $financial->addTaxRule($actor->id, $financialVersion++, $rule);
    }
    $verify(true, 'Synthetic sale and purchase tax rules saved through FinancialActivation');
    $financial->validateTaxTreatment($actor->id, $financialVersion++, 'Synthetic M06 focused test tax validation');
    $verify(true, 'Synthetic M06 tax rules validated before activation');
    $openingLines = [
        ['kind' => 'gl', 'reference' => 'M06-OPENING-INVENTORY', 'branch_id' => $branch->id, 'account_id' => $mappings['inventory'], 'debit' => '150.00', 'credit' => '0.00'],
        ['kind' => 'gl', 'reference' => 'M06-OPENING-EQUITY', 'branch_id' => $branch->id, 'account_id' => $equity, 'debit' => '0.00', 'credit' => '150.00'],
        ['kind' => 'inventory', 'reference' => 'M06-OPENING-FIFO', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse['id'], 'product_id' => $product->id, 'quantity' => '10.00000000', 'unit_cost' => '10.0000000000000000', 'amount' => '100.00'],
        ['kind' => 'inventory', 'reference' => 'M06-CORRECTION-FIFO', 'branch_id' => $branch->id, 'warehouse_id' => $correctionWarehouse['id'], 'product_id' => $product->id, 'quantity' => '3.00000000', 'unit_cost' => '10.0000000000000000', 'amount' => '30.00'],
        ['kind' => 'inventory', 'reference' => 'M06-ISSUE-CORRECTION-FIFO', 'branch_id' => $branch->id, 'warehouse_id' => $issueCorrectionWarehouse['id'], 'product_id' => $product->id, 'quantity' => '2.00000000', 'unit_cost' => '10.0000000000000000', 'amount' => '20.00'],
    ];
    $openingBalances = app(OpeningBalances::class);
    $openingBatch = $openingBalances->save($actor->id, $financialVersion, (string) Str::uuid(), $openingLines, false, 'Synthetic independently reviewed M06 FIFO opening fixture');
    $verify(true, 'Balanced opening GL and FIFO source saved through OpeningBalances');
    $openingBalances->advance($actor->id, $openingBatch, 1, 'submit');
    $verify(true, 'Opening submitted by its preparer');
    $verify(app(AccountingAccess::class)->allows($approver->id, 'opening_approve')
        && app(AccountingAccess::class)->allows($approver->id, 'opening_review', $branch->id),
        'Independent M11 approver holds company approval and explicit branch review permissions');
    $openingBalances->advance($approver->id, $openingBatch, 2, 'approve');
    $verify(true, 'Opening independently approved');
    app(ActivateFinancialPosting::class)->execute($approver->id, $openingBatch, 3);
    $financialOpeningMovement = DB::table('inventory_movements')->where('operation', 'financial_opening')->where('source_id', (string) $openingBatch)->first();
    $verify($financialOpeningMovement && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '10.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $correctionWarehouse['id'])->where('product_id', $product->id)->value('on_hand') === '3.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $issueCorrectionWarehouse['id'])->where('product_id', $product->id)->value('on_hand') === '2.00000000'
        && DB::table('accounting_activation')->where('id', 1)->value('activated_at') !== null,
        'Current-year financial setup validates tax, independently approves balanced opening GL/FIFO for all correction sources, and activates real stock through owner actions');
    $lineImport = app(InventoryLineImport::class);
    $templatePath = $lineImport->template($actor->id, 'opening', $warehouse['id']);
    $temporaryFiles[] = $templatePath;
    $templateReader = new Xlsx;
    $templateBook = $templateReader->load($templatePath);
    $templateData = $templateBook->getSheetByName('Data');
    $templateRoundTrip = $templateData->getCell('A1')->getValue() === 'sku' && $templateData->getCell('B1')->getValue() === 'quantity';
    $templateBook->disconnectWorksheets();
    $importFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m06-import-'.Illuminate\Support\Str::uuid().'.xlsx';
    $temporaryFiles[] = $importFile;
    $importBook = (new Xlsx)->load($templatePath);
    $importData = $importBook->getSheetByName('Data');
    foreach ([2 => [$product->sku, '1.25000000'], 3 => ['UNKNOWN-M06-SKU', '2.00000000'], 4 => [$product->sku, '3.00000000']] as $rowNumber => [$sku, $quantity]) {
        $importData->setCellValueExplicit("A{$rowNumber}", $sku, DataType::TYPE_STRING);
        $importData->setCellValueExplicit("B{$rowNumber}", $quantity, DataType::TYPE_STRING);
    }
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($importBook))->save($importFile);
    $importBook->disconnectWorksheets();
    $uploadedImport = new UploadedFile($importFile, 'inventory-lines.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    $importBefore = [DB::table('inventory_documents')->count(), DB::table('inventory_document_lines')->count(), DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')];
    $preview = $lineImport->preview($actor->id, 'opening', $warehouse['id'], $uploadedImport);
    $verify($templateRoundTrip && count($preview) === 3 && $preview[0]['product_id'] === $product->id && $preview[0]['quantity'] === '1.25000000'
        && $preview[0]['error'] === null && $preview[1]['error'] !== null && $preview[2]['error'] !== null,
        'Inventory line template round-trips and preview reports a valid row, unknown SKU, and duplicate product');
    $verify($importBefore === [DB::table('inventory_documents')->count(), DB::table('inventory_document_lines')->count(), DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')],
        'Inventory spreadsheet preview performs no draft, line, movement, or stock writes');
    $validImportFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m06-valid-import-'.Illuminate\Support\Str::uuid().'.xlsx';
    $temporaryFiles[] = $validImportFile;
    $validImportBook = (new Xlsx)->load($templatePath);
    $validImportSheet = $validImportBook->getSheetByName('Data');
    $validImportSheet->setCellValueExplicit('A2', $product->sku, DataType::TYPE_STRING);
    $validImportSheet->setCellValueExplicit('B2', '1.75000000', DataType::TYPE_STRING);
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($validImportBook))->save($validImportFile);
    $validImportBook->disconnectWorksheets();
    $validUpload = UploadedFile::fake()->createWithContent('inventory-lines.xlsx', file_get_contents($validImportFile))->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $uiImportBefore = [DB::table('inventory_documents')->count(), DB::table('inventory_document_lines')->count(), DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')];
    $importUi = Livewire::actingAs($actor)->test(InventoryDocumentPage::class, ['kind' => 'opening'])
        ->set('form.warehouse_id', (string) $warehouse['id'])->set('form.reason', 'Spreadsheet application regression')->set('importFile', $validUpload)->call('previewImport');
    $importUi->assertSet('importPreview.0.product_id', $product->id)->assertSet('importPreview.0.quantity', '1.75000000')->assertSet('importPreview.0.error', null);
    $verify($uiImportBefore === [DB::table('inventory_documents')->count(), DB::table('inventory_document_lines')->count(), DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')],
        'Inventory Livewire preview retains a valid row without persisting draft or stock data');
    $importUi->call('applyImport')->assertSet('form.lines.0.product_id', $product->id)->assertSet('form.lines.0.quantity', '1.75000000');
    $verify($uiImportBefore === [DB::table('inventory_documents')->count(), DB::table('inventory_document_lines')->count(), DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')],
        'Inventory Livewire apply moves preview rows into the draft form without writing them');
    $importUi->call('save');
    $importDocument = (int) $importUi->get('document');
    $verify($importDocument > 0 && DB::table('inventory_documents')->where('id', $importDocument)->value('status') === 'draft'
        && DB::table('inventory_document_lines')->where('document_id', $importDocument)->value('quantity') === '1.75000000'
        && DB::table('inventory_movements')->count() === $uiImportBefore[2]
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === $uiImportBefore[3],
        'Inventory Livewire saves applied spreadsheet rows as a draft without posting stock');
    $uiWarehouses = Livewire::actingAs($actor)->test(WarehousesPage::class)->assertSee('المستودعات');
    $uiWarehouses->call('edit', $warehouse['id'])->assertSet('editing', true)->set('form.name', 'Warehouse UI edit')->call('save');
    $verify(DB::table('inventory_warehouses')->where('id', $warehouse['id'])->value('name') === 'Warehouse UI edit', 'Authenticated warehouse page reopens and saves through the versioned owner action');
    foreach (['dashboard', 'balances', 'movements', 'reservations'] as $screen) {
        Livewire::actingAs($actor)->test(InventoryPage::class, ['screen' => $screen]);
    }
    foreach (['transfer', 'count', 'adjustment', 'opening', 'issue'] as $kind) {
        Livewire::actingAs($actor)->test(InventoryDocumentsPage::class, ['kind' => $kind]);
    }
    Livewire::actingAs($actor)->test(InventoryDocumentPage::class, ['kind' => 'opening'])->assertSee('أرصدة افتتاحية');
    $verify(true, 'Authenticated inventory dashboards, document lists, and new-document form render for explicitly scoped permissions');
    $doc = app(InventoryDocuments::class);
    $document = static function (string $kind, int $warehouseId, ?int $destinationId, string $quantity, string $reason, ?string $unitCost = null, ?string $costReference = null) use ($doc, $actor, $product): array {
        $line = ['product_id' => $product->id, 'quantity' => $quantity];
        if ($unitCost !== null) {
            $line += ['unit_cost' => $unitCost, 'cost_reference' => $costReference];
        }

        return $doc->save($actor->id, $kind, [
            'warehouse_id' => $warehouseId, 'destination_id' => $destinationId, 'reason' => $reason,
            'lines' => [$line], 'creation_key' => (string) Illuminate\Support\Str::uuid(),
        ]);
    };
    $step = static function (int $actorId, array $row, string $action) use ($doc): array {
        return $doc->transition($actorId, $row['id'], $action, $row['version']);
    };

    $quietWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-QUIET-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Empty lifecycle warehouse', 'branch_id' => $branch->id, 'active' => true]);
    $quietRow = DB::table('inventory_warehouses')->find($quietWarehouse['id']);
    $quietOff = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $quietRow->code, 'name' => $quietRow->name, 'branch_id' => $branch->id, 'active' => false], $quietWarehouse['id'], $quietWarehouse['version']);
    $quietOn = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $quietRow->code, 'name' => $quietRow->name, 'branch_id' => $branch->id, 'active' => true], $quietWarehouse['id'], $quietOff['version']);
    $verify($quietOn['version'] === 3 && DB::table('inventory_warehouses')->where('id', $quietWarehouse['id'])->value('active') === true
        && ! DB::table('inventory_balances')->where('warehouse_id', $quietWarehouse['id'])->exists()
        && ! DB::table('inventory_documents')->where(fn ($q) => $q->where('warehouse_id', $quietWarehouse['id'])->orWhere('destination_id', $quietWarehouse['id']))->exists(),
        'An empty warehouse can be deactivated and reactivated without stock or document history');

    $pendingWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-PENDING-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Count-pending warehouse', 'branch_id' => $branch->id, 'active' => true]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $pendingWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $pendingCount = $document('count', $pendingWarehouse['id'], null, '0.00000000', 'Draft count blocks warehouse deactivation');
    $pendingRow = DB::table('inventory_warehouses')->find($pendingWarehouse['id']);
    $expect(ValidationException::class, fn () => app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $pendingRow->code, 'name' => $pendingRow->name, 'branch_id' => $branch->id, 'active' => false], $pendingWarehouse['id'], $pendingWarehouse['version']), 'A zero-stock warehouse with a live draft count cannot be deactivated');
    $verify(DB::table('inventory_warehouses')->where('id', $pendingWarehouse['id'])->value('active') === true
        && ! DB::table('inventory_balances')->where('warehouse_id', $pendingWarehouse['id'])->exists(),
        'Rejected deactivation preserves the active warehouse while its draft count has no stock effect');
    $cancelledCount = $step($actor->id, $pendingCount, 'cancel');
    $deactivatedPending = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => $pendingRow->code, 'name' => $pendingRow->name, 'branch_id' => $branch->id, 'active' => false], $pendingWarehouse['id'], $pendingWarehouse['version']);
    $verify($cancelledCount['status'] === 'cancelled' && DB::table('inventory_documents')->where('id', $pendingCount['id'])->exists()
        && DB::table('inventory_warehouses')->where('id', $pendingWarehouse['id'])->value('active') === false
        && $deactivatedPending['version'] === 2,
        'Cancelling the draft count permits warehouse deactivation and preserves document history');

    $reuseKey = (string) Illuminate\Support\Str::uuid();
    $sameKeyDraft = $doc->save($actor->id, 'opening', ['warehouse_id' => $warehouse['id'], 'reason' => 'Idempotency payload guard', 'lines' => [['product_id' => $product->id, 'quantity' => '1.00000000']], 'creation_key' => $reuseKey]);
    $expect(ValidationException::class, fn () => $doc->save($actor->id, 'opening', ['warehouse_id' => $warehouse['id'], 'reason' => 'Changed payload', 'lines' => [['product_id' => $product->id, 'quantity' => '2.00000000']], 'creation_key' => $reuseKey]), 'Reusing a document creation key with changed payload is rejected');
    $verify(DB::table('inventory_document_lines')->where('document_id', $sameKeyDraft['id'])->value('quantity') === '1.00000000', 'Rejected idempotency replay leaves the original draft line unchanged');

    $opening = $document('opening', $warehouse['id'], null, '10.00000000', 'Synthetic opening balance check');
    $opening = $step($actor->id, $opening, 'submit');
    $expect(ValidationException::class, fn () => $step($actor->id, $opening, 'approve'), 'A document creator cannot approve their own opening stock even with approval permission');
    $opening = $step($approver->id, $opening, 'approve');
    $beforeDuplicateOpening = [DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')];
    $expect(ValidationException::class, fn () => $step($actor->id, $opening, 'post'), 'Legacy M06 opening post cannot replace an already posted financial FIFO opening');
    $verify($beforeDuplicateOpening === [DB::table('inventory_movements')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')]
        && DB::table('inventory_documents')->where('id', $opening['id'])->value('status') === 'approved'
        && DB::table('inventory_movements')->where('document_id', $opening['id'])->doesntExist(),
        'Rejected duplicate opening preserves the approved draft, movement count, and exact FIFO-backed balance');
    DB::statement('SAVEPOINT m06_posted_line_move_attempt');
    $expect(QueryException::class, fn () => DB::table('inventory_document_lines')->where('document_id', $opening['id'])->update(['document_id' => $sameKeyDraft['id']]), 'Database guard rejects moving an independently approved opening line into another draft');
    DB::statement('ROLLBACK TO SAVEPOINT m06_posted_line_move_attempt');
    $verify(DB::table('inventory_document_lines')->where('document_id', $opening['id'])->count() === 1, 'Rejected approved-line reparenting leaves the immutable source line intact');
    $movementCount = DB::table('inventory_movements')->count();
    $expect(ValidationException::class, fn () => $step($actor->id, $opening, 'post'), 'Retrying the legacy duplicate opening remains denied');
    $verify(DB::table('inventory_movements')->count() === $movementCount && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '10.00000000', 'Opening denial replay adds no movement and preserves the exact financial FIFO balance');

    $balanceRow = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->first();
    $originalOnHand = $balanceRow->on_hand;
    $expect(HttpException::class, fn () => app(InventoryActions::class)->setMinimum($denied->id, $balanceRow->id, '2.00000000'), 'Minimum stock changes require explicit reorder permission');
    $grant($actor, ['M06.reorder_manage'], $branch->id);
    app(InventoryActions::class)->setMinimum($actor->id, $balanceRow->id, '2.00000000');
    $minimumRow = DB::table('inventory_balances')->find($balanceRow->id);
    $verify($minimumRow->minimum === '2.00000000' && $minimumRow->on_hand === $originalOnHand, 'Minimum level persists without changing physical quantity');

    $query = app(InventoryQuery::class);
    $verify($query->balances($actor->id)->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('available') === '10.00000000', 'Scoped balance query exposes the persisted available quantity');
    $authorization = app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor->id, 'M06.balance_view', $branch->id, $warehouse['id']));
    $verify($authorization->warehouseIds === [$warehouse['id']], 'Organization authorization returns the actual authorized warehouse id');
    $expect(HttpException::class, fn () => $query->balances($denied->id)->exists(), 'Balance query denies an actor without M06 balance permission and warehouse scope');
    $outsideScope = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($outsideScope, ['M06.balance_view'], $branch->id);
    $expect(HttpException::class, fn () => $query->balances($outsideScope->id)->exists(), 'Balance query denies permission without warehouse scope');
    $inactive = User::factory()->create(['active' => false, 'email_verified_at' => now()]);
    $grant($inactive, ['M06.balance_view'], $branch->id);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $inactive->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $expect(HttpException::class, fn () => $query->balances($inactive->id)->exists(), 'Balance query denies an inactive actor despite permission and warehouse scope');
    DB::statement('SAVEPOINT m06_immutable_attempt');
    $expect(QueryException::class, fn () => DB::table('inventory_movements')->where('id', $financialOpeningMovement->id)->delete(), 'Posted movement ledger rejects destructive edits to the real financial FIFO opening');
    DB::statement('ROLLBACK TO SAVEPOINT m06_immutable_attempt');

    $emptyIssue = $document('issue', $secondWarehouse['id'], null, '1.00000000', 'Synthetic no-stock guard');
    $emptyIssue = $step($actor->id, $emptyIssue, 'submit');
    $emptyIssue = $step($approver->id, $emptyIssue, 'approve');
    $expect(ValidationException::class, fn () => $step($actor->id, $emptyIssue, 'post'), 'Issue posting cannot create a negative stock balance');
    $verify(! DB::table('inventory_balances')->where('warehouse_id', $secondWarehouse['id'])->where('product_id', $product->id)->where('on_hand', '<', 0)->exists(), 'Failed issue leaves no negative or partial balance');

    app()->instance(SalesOrdersApi::class, new class($product->id, $branch->id) implements SalesOrdersApi
    {
        public function __construct(private int $productId, private int $branchId) {}

        public function getOrder(int $actorId, int $orderId): array
        {
            return [];
        }

        public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
        {
            throw new LogicException('Executive analytics are not used by this inventory check.');
        }

        public function inventoryReleaseAllowed(int $actor, string $sourceId, string $sourceLine): bool
        {
            return $sourceId !== 'live-order';
        }

        public function inventoryDemand(int $actor, string $sourceId, string $sourceLine): array
        {
            return ['product_id' => $this->productId, 'branch_id' => $this->branchId, 'quantity' => $sourceId === 'full-order' ? '4.00000000' : ($sourceId === 'none-order' ? '2.00000000' : '12.00000000'), 'eligible' => true];
        }
    });
    $reservations = app(InventoryReservations::class);
    $conditions = app(InventoryConditions::class);
    $reservationRequest = static fn (int $warehouseId, string $sourceId, string $quantity, string $key) => ['warehouse_id' => $warehouseId, 'product_id' => $product->id, 'quantity' => $quantity, 'source_module' => 'sales', 'source_id' => $sourceId, 'source_line' => '1', 'idempotency_key' => $key];

    $serialProduct = Product::factory()->create(['base_unit_id' => $unit->id, 'purchase_unit_id' => $unit->id, 'sales_unit_id' => $unit->id,
        'kind' => 'serialized_equipment', 'tracking' => 'serial', 'active' => true]);
    $serialWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-SERIAL-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Serialized stock warehouse', 'branch_id' => $branch->id, 'active' => true]);
    DB::table('user_warehouse_scopes')->insert([
        ['user_id' => $actor->id, 'warehouse_id' => $serialWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $approver->id, 'warehouse_id' => $serialWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $serialDoc = static fn (string $kind, int $warehouseId, int $productId, string $quantity, array $serials, string $reason) => $doc->save($actor->id, $kind, [
        'warehouse_id' => $warehouseId, 'reason' => $reason, 'creation_key' => (string) Illuminate\Support\Str::uuid(),
        'lines' => [['product_id' => $productId, 'quantity' => $quantity, 'serials' => $serials]],
    ]);
    $serialOpening = $serialDoc('opening', $serialWarehouse['id'], $serialProduct->id, '2.00000000', ['Serial-A', 'Serial-B'], 'Serialized opening test');
    $serialOpening = $step($actor->id, $serialOpening, 'submit');
    $serialOpening = $step($approver->id, $serialOpening, 'approve');
    $serialBefore = [DB::table('inventory_movements')->count(), DB::table('inventory_serials')->where('product_id', $serialProduct->id)->count(), DB::table('inventory_balances')->where('warehouse_id', $serialWarehouse['id'])->where('product_id', $serialProduct->id)->exists()];
    $expect(ValidationException::class, fn () => $step($actor->id, $serialOpening, 'post'), 'Serialized opening remains blocked until its owner supplies the accepted valued-stock contract');
    $verify($serialBefore === [DB::table('inventory_movements')->count(), DB::table('inventory_serials')->where('product_id', $serialProduct->id)->count(), DB::table('inventory_balances')->where('warehouse_id', $serialWarehouse['id'])->where('product_id', $serialProduct->id)->exists()],
        'Denied serialized opening creates no movement, serial identities, or balance');
    $serialIssue = $serialDoc('issue', $serialWarehouse['id'], $serialProduct->id, '1.00000000', ['  serial-a  '], 'Issue one serialized unit');
    $serialIssue = $step($actor->id, $serialIssue, 'submit');
    $serialIssue = $step($approver->id, $serialIssue, 'approve');
    $expect(ValidationException::class, fn () => $step($actor->id, $serialIssue, 'post'), 'Serialized issue cannot post against unvalued, nonexistent serial stock');
    $originalSalesApi = app(SalesOrdersApi::class);
    app()->instance(SalesOrdersApi::class, new class($serialProduct->id, $branch->id) implements SalesOrdersApi
    {
        public function __construct(private int $productId, private int $branchId) {}

        public function getOrder(int $actorId, int $orderId): array
        {
            return [];
        }

        public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
        {
            throw new LogicException('Executive analytics are not used by this inventory check.');
        }

        public function inventoryReleaseAllowed(int $actor, string $sourceId, string $sourceLine): bool
        {
            return $sourceId === 'serial-order-source';
        }

        public function inventoryDemand(int $actor, string $sourceId, string $sourceLine): array
        {
            return ['product_id' => $this->productId, 'branch_id' => $this->branchId, 'quantity' => '1.00000000', 'eligible' => $sourceId === 'serial-order-source'];
        }
    });
    $serialReservation = $reservations->request($actor->id, ['warehouse_id' => $serialWarehouse['id'], 'product_id' => $serialProduct->id,
        'quantity' => '1.00000000', 'source_module' => 'sales', 'source_id' => 'serial-order-source', 'source_line' => '1', 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
    app()->instance(SalesOrdersApi::class, $originalSalesApi);
    $verify($serialReservation['reserved'] === '0.00000000' && DB::table('inventory_serials')->where('product_id', $serialProduct->id)->doesntExist()
        && DB::table('inventory_balances')->where('warehouse_id', $serialWarehouse['id'])->where('product_id', $serialProduct->id)->value('reserved') === '0.00000000',
        'Serialized demand remains entirely backordered without real serial opening stock');
    $expect(ValidationException::class, fn () => $serialDoc('issue', $serialWarehouse['id'], $serialProduct->id, '2.00000000', ['Serial-C'], 'Missing serial selection'), 'Serialized line rejects a nonmatching unit count');
    $expect(ValidationException::class, fn () => $serialDoc('issue', $serialWarehouse['id'], $serialProduct->id, '2.00000000', ['Serial-C', ' serial-c '], 'Duplicate normalized serial'), 'Serialized line rejects duplicate normalized identifiers');
    $full = $reservations->request($actor->id, $reservationRequest($warehouse['id'], 'full-order', '4.00000000', (string) Illuminate\Support\Str::uuid()));
    $verify($full['reserved'] === '4.00000000', 'Reservation fully reserves eligible demand when stock is sufficient');
    $reservations->release($actor->id, $full['id'], (string) Illuminate\Support\Str::uuid());
    $liveReservation = $reservations->request($actor->id, $reservationRequest($warehouse['id'], 'live-order', '2.00000000', (string) Illuminate\Support\Str::uuid()));
    $reservedBeforeDeniedRelease = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('reserved');
    $expect(ValidationException::class, fn () => $reservations->release($actor->id, $liveReservation['id'], (string) Illuminate\Support\Str::uuid()), 'Reservation release requires an authorized source cancellation or reduction');
    $verify($reservations->status($actor->id, $liveReservation['id'])['released'] === '0.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('reserved') === $reservedBeforeDeniedRelease,
        'Denied release leaves reservation and balance unchanged');
    app()->instance(SalesOrdersApi::class, new class($product->id, $branch->id) implements SalesOrdersApi
    {
        public function __construct(private int $productId, private int $branchId) {}

        public function getOrder(int $actorId, int $orderId): array
        {
            return [];
        }

        public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
        {
            throw new LogicException('Executive analytics are not used by this inventory check.');
        }

        public function inventoryReleaseAllowed(int $actor, string $sourceId, string $sourceLine): bool
        {
            return true;
        }

        public function inventoryDemand(int $actor, string $sourceId, string $sourceLine): array
        {
            return ['product_id' => $this->productId, 'branch_id' => $this->branchId, 'quantity' => '12.00000000', 'eligible' => true];
        }
    });
    $reservations->release($actor->id, $liveReservation['id'], (string) Illuminate\Support\Str::uuid());
    $reservation = $reservations->request($actor->id, $reservationRequest($warehouse['id'], 'partial-order', '12.00000000', (string) Illuminate\Support\Str::uuid()));
    $verify($reservation['reserved'] === '10.00000000', 'Reservation is capped at available stock when demand exceeds balance');
    $replayed = $reservations->request($actor->id, $reservationRequest($warehouse['id'], 'partial-order', '12.00000000', $reservation['idempotency_key']));
    $verify($replayed['id'] === $reservation['id'] && DB::table('inventory_reservations')->where('id', $reservation['id'])->count() === 1, 'Reservation request retry returns its original persisted record');
    DB::statement('SAVEPOINT m06_reservation_request_mutation_attempt');
    $expect(QueryException::class, fn () => DB::table('inventory_reservations')->where('id', $reservation['id'])->update(['requested' => '11.00000000']), 'Database guard rejects changing the original reservation request quantity');
    DB::statement('ROLLBACK TO SAVEPOINT m06_reservation_request_mutation_attempt');
    DB::statement('SAVEPOINT m06_reservation_reserved_mutation_attempt');
    $expect(QueryException::class, fn () => DB::table('inventory_reservations')->where('id', $reservation['id'])->update(['reserved' => '9.00000000']), 'Database guard rejects changing the original reserved quantity');
    DB::statement('ROLLBACK TO SAVEPOINT m06_reservation_reserved_mutation_attempt');
    $verify(DB::table('inventory_reservations')->where('id', $reservation['id'])->value('requested') === '12.00000000' && DB::table('inventory_reservations')->where('id', $reservation['id'])->value('reserved') === '10.00000000', 'Rejected request-field mutations preserve persisted reservation values');
    $status = $reservations->status($actor->id, $reservation['id']);
    $verify($status['outstanding'] === '10.00000000' && $status['id'] === $reservation['id'], 'Reservation status returns authorized persisted totals');
    $expect(HttpException::class, fn () => $reservations->status($denied->id, $reservation['id']), 'Reservation status denies actors without reservation view permission');
    $adjustKey = (string) Illuminate\Support\Str::uuid();
    $adjusted = $reservations->adjust($actor->id, $reservation['id'], '6.00000000', $adjustKey);
    $adjustReplay = $reservations->adjust($actor->id, $reservation['id'], '6.00000000', $adjustKey);
    $oldReservation = $reservations->status($actor->id, $reservation['id']);
    $verify($adjusted['id'] !== $reservation['id'] && $adjustReplay['id'] === $adjusted['id']
        && $oldReservation['released'] === '10.00000000' && $oldReservation['fulfilled'] === '0.00000000'
        && $adjusted['reserved'] === '6.00000000' && $adjusted['outstanding'] === '6.00000000'
        && DB::table('inventory_reservations')->where('source_id', 'partial-order')->count() === 2
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('reserved') === '6.00000000',
        'Reservation adjustment atomically replaces outstanding quantity, preserves fulfillment, and replays by key');
    $releaseKey = (string) Illuminate\Support\Str::uuid();
    $released = $reservations->release($actor->id, $adjusted['id'], $releaseKey);
    $reservations->release($actor->id, $adjusted['id'], $releaseKey);
    $verify($released['released'] === '6.00000000' && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('reserved') === '0.00000000', 'Reservation release restores available stock exactly once');
    $none = $reservations->request($actor->id, $reservationRequest($secondWarehouse['id'], 'none-order', '2.00000000', (string) Illuminate\Support\Str::uuid()));
    $verify($none['reserved'] === '0.00000000', 'Reservation request records zero reserved quantity when no stock is available');

    $transfer = $document('transfer', $warehouse['id'], $secondWarehouse['id'], '3.00000000', 'Synthetic internal transfer check');
    $transfer = $step($actor->id, $transfer, 'submit');
    $transfer = $step($approver->id, $transfer, 'approve');
    $transfer = $step($actor->id, $transfer, 'dispatch');
    $verify($transfer['status'] === 'dispatched'
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '7.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $secondWarehouse['id'])->where('product_id', $product->id)->value('on_hand') === '0.00000000',
        'Transfer dispatch moves quantity out of source stock while destination remains unchanged in transit');
    $transfer = $step($actor->id, $transfer, 'receive');
    $transferMovements = DB::table('inventory_movements')->where('document_id', $transfer['id'])->count();
    $replayedTransfer = $step($actor->id, $transfer, 'receive');
    $verify($transferMovements === 2
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '7.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $secondWarehouse['id'])->where('product_id', $product->id)->value('on_hand') === '3.00000000',
        'Transfer dispatch and full receipt create matching internal legs without changing company stock');
    $verify($replayedTransfer['status'] === 'received' && DB::table('inventory_movements')->where('document_id', $transfer['id'])->count() === $transferMovements, 'Replaying transfer receipt creates no duplicate movement');
    $dispatchAfterReceive = $step($actor->id, $transfer, 'dispatch');
    $verify($dispatchAfterReceive['status'] === 'received' && DB::table('inventory_movements')->where('document_id', $transfer['id'])->count() === $transferMovements, 'Replaying transfer dispatch after full receipt returns the received document without duplicate ledger legs');

    $history = app(InventoryHistoryReader::class)->read($actor->id, $opening['id']);
    $verify($history !== [] && collect($history)->contains(fn ($row) => $row['action'] === 'approve')
        && ! collect($history)->contains(fn ($row) => $row['action'] === 'post'), 'Inventory history preserves approval while the duplicate opening post remains denied');

    $unscopedWarehouse = app(InventoryActions::class)->saveWarehouse($actor->id, ['code' => 'M06-UNSCOPED-'.strtoupper(bin2hex(random_bytes(3))), 'name' => 'Unscoped export target', 'branch_id' => $branch->id, 'active' => true]);
    $grant($actor, ['M06.export'], $branch->id);
    Auth::loginUsingId($actor->id);
    $privateExports = [];
    $export = static function (int $warehouseId, array $filters = []) use (&$privateExports, $app): array {
        $request = Request::create('/inventory/export/balances?'.http_build_query(['warehouse' => $warehouseId] + $filters), 'GET');
        $app->instance('request', $request);
        $response = app(InventoryExportController::class)('balances');
        $path = $response->getFile()->getPathname();
        $privateExports[] = $path;
        $book = (new Xlsx)->load($path);
        $rows = $book->getSheetByName('Data')->toArray();
        $book->disconnectWorksheets();

        return $rows;
    };
    $allowedExport = $export($warehouse['id']);
    $forgedExport = $export($unscopedWarehouse['id']);
    $emptyOnlyExport = $export($warehouse['id'], ['status' => 'empty']);
    $verify(count($allowedExport) > 1 && count($forgedExport) === 1, 'Inventory XLSX export includes scoped balances and excludes a forged unscoped warehouse filter');
    $verify(count($emptyOnlyExport) === 1, 'Inventory XLSX export applies the selected empty-stock filter to scoped balances');
    foreach ($privateExports as $path) {
        @unlink($path);
    }

    $fulfillReservation = $reservations->request($actor->id, $reservationRequest($warehouse['id'], 'fulfill-order', '4.00000000', (string) Illuminate\Support\Str::uuid()));
    $fulfillKey = (string) Illuminate\Support\Str::uuid();
    $beforeUnstagedFulfillment = [DB::table('inventory_movements')->where('source_module', 'Sales')->where('source_id', 'fulfill-order')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')];
    $expect(HttpException::class, fn () => $reservations->fulfill($actor->id, $fulfillReservation['id'], '2.00000000', $fulfillKey), 'Generic reservation fulfillment is denied without the owning Sales delivery instruction');
    $verify($beforeUnstagedFulfillment === [DB::table('inventory_movements')->where('source_module', 'Sales')->where('source_id', 'fulfill-order')->count(), DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand')],
        'Denied generic reservation fulfillment creates no Sales issue movement and leaves stock unchanged');
    $adjustFulfilledKey = (string) Illuminate\Support\Str::uuid();
    $replacement = $reservations->adjust($actor->id, $fulfillReservation['id'], '3.00000000', $adjustFulfilledKey);
    $preservedFulfillment = $reservations->status($actor->id, $fulfillReservation['id']);
    $verify($preservedFulfillment['fulfilled'] === '0.00000000' && $replacement['fulfilled'] === '0.00000000'
        && $replacement['reserved'] === '3.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '7.00000000',
        'Adjusting an unfulfilled reservation changes only outstanding demand and leaves physical stock unchanged');
    $remaining = $reservations->release($actor->id, $replacement['id'], (string) Illuminate\Support\Str::uuid());
    $verify($remaining['outstanding'] === '0.00000000'
        && DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '7.00000000',
        'Releasing the remaining reservation leaves exact on-hand quantity and no reserved remainder');

    $count = $document('count', $warehouse['id'], null, '5.00000000', 'Synthetic count snapshot');
    $verify(DB::table('inventory_documents')->where('id', $count['id'])->value('cutoff') !== null, 'Count persists its balance-version cutoff at creation');
    $balanceChange = $document('adjustment', $warehouse['id'], null, '1.00000000', 'Synthetic concurrent balance change', '10.0000000000000000', 'Synthetic approved count-race cost M06-1');
    $balanceChange = $step($actor->id, $balanceChange, 'submit');
    $balanceChange = $step($approver->id, $balanceChange, 'approve');
    $step($actor->id, $balanceChange, 'post');
    $count = $step($actor->id, $count, 'submit');
    $expect(ValidationException::class, fn () => $step($approver->id, $count, 'approve'), 'A stale count snapshot cannot be approved after its expected balance version changes');
    $verify(DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand') === '8.00000000', 'Rejected stale count leaves the new physical ledger balance intact');

    $summaryReservation = $reservations->request($actor->id, $reservationRequest($secondWarehouse['id'], 'summary-zero-available', '3.00000000', (string) Illuminate\Support\Str::uuid()));
    $primaryBalance = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->first();
    $destinationBalance = DB::table('inventory_balances')->where('warehouse_id', $secondWarehouse['id'])->where('product_id', $product->id)->first();
    app(InventoryActions::class)->setMinimum($actor->id, $primaryBalance->id, '9.00000000');
    app(InventoryActions::class)->setMinimum($actor->id, $destinationBalance->id, '1.00000000');
    $primaryBalance = DB::table('inventory_balances')->where('id', $primaryBalance->id)->first();
    $destinationBalance = DB::table('inventory_balances')->where('id', $destinationBalance->id)->first();
    $verify($summaryReservation['reserved'] === '3.00000000' && $primaryBalance->available === '8.00000000'
        && $destinationBalance->on_hand === '3.00000000' && $destinationBalance->reserved === '3.00000000'
        && $destinationBalance->available === '0.00000000' && $primaryBalance->minimum === '9.00000000'
        && $destinationBalance->minimum === '1.00000000',
        'Known persisted quantities establish one low-stock warehouse and one fully reserved empty-available warehouse');
    $openingMovement = $financialOpeningMovement;
    $isolationKey = (string) Illuminate\Support\Str::uuid();
    $isolationInput = ['warehouse_id' => $warehouse['id'], 'product_id' => $product->id, 'quantity' => '2.00000000',
        'reason' => 'Synthetic condition hold', 'source_movement_id' => $openingMovement->id, 'idempotency_key' => $isolationKey];
    $isolation = $conditions->isolate($actor->id, $isolationInput);
    $isolationReplay = $conditions->isolate($actor->id, $isolationInput);
    $heldBalance = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->first();
    $verify($isolationReplay['id'] === $isolation['id'] && $heldBalance->on_hand === '8.00000000'
        && $heldBalance->reserved === '0.00000000' && $heldBalance->unavailable === '2.00000000'
        && $heldBalance->available === '6.00000000',
        'Isolation links to a real positive movement and reduces available stock without changing physical or reserved quantity');
    $expect(ValidationException::class, fn () => $conditions->isolate($actor->id, [...$isolationInput, 'idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'quantity' => '7.00000000']), 'Isolation cannot consume reserved or unavailable quantity beyond available stock');
    $releaseKey = (string) Illuminate\Support\Str::uuid();
    $releasedIsolation = $conditions->release($actor->id, $isolation['id'], '1.00000000', $releaseKey);
    $replayedIsolationRelease = $conditions->release($actor->id, $isolation['id'], '1.00000000', $releaseKey);
    $releasedBalance = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->first();
    $verify($releasedIsolation['released'] === '1.00000000' && $replayedIsolationRelease['released'] === '1.00000000'
        && $releasedBalance->on_hand === '8.00000000' && $releasedBalance->unavailable === '1.00000000'
        && $releasedBalance->available === '7.00000000',
        'Isolation release replay restores availability once while preserving physical stock');
    $expect(ValidationException::class, fn () => $conditions->release($actor->id, $isolation['id'], '2.00000000', (string) Illuminate\Support\Str::uuid()), 'Isolation release cannot exceed its residual quantity');
    $knownLowExport = $export($warehouse['id'], ['status' => 'low']);
    $knownEmptyExport = $export($secondWarehouse['id'], ['status' => 'empty']);
    $lowExportRows = array_values(array_filter(array_slice($knownLowExport, 1), fn ($row) => in_array($product->sku, $row, true)));
    $emptyExportRows = array_values(array_filter(array_slice($knownEmptyExport, 1), fn ($row) => in_array($product->sku, $row, true)));
    $hasDecimal = static fn (array $row, string $expected): bool => collect($row)->contains(fn ($value) => is_numeric($value) && bccomp((string) $value, $expected, 8) === 0);
    $verify(count($lowExportRows) === 1 && $hasDecimal($lowExportRows[0], '8.00000000') && $hasDecimal($lowExportRows[0], '9.00000000')
        && count($emptyExportRows) === 1 && $hasDecimal($emptyExportRows[0], '0.00000000') && $hasDecimal($emptyExportRows[0], '3.00000000'),
        'Authorized filtered XLSX exports contain the independently known low and empty-available quantities');
    $lowBalances = Livewire::actingAs($actor)->test(InventoryPage::class, ['screen' => 'balances'])
        ->set('warehouse', (string) $warehouse['id'])->set('status', 'low');
    $viewData = static fn ($testable) => (function () {
        return $this->lastState->getResponse()->original->gatherData();
    })->call($testable);
    $lowData = $viewData($lowBalances);
    $lowRows = data_get($lowData, 'rows');
    $verify($lowRows && $lowRows->total() === 1 && $lowRows->items()[0]->warehouse_id === $warehouse['id']
        && $lowRows->items()[0]->available === '7.00000000' && $lowRows->items()[0]->minimum === '9.00000000',
        'InventoryPage low-stock filter returns the exact scoped balance row and minimum');
    $emptyBalances = Livewire::actingAs($actor)->test(InventoryPage::class, ['screen' => 'balances'])
        ->set('warehouse', (string) $secondWarehouse['id'])->set('status', 'empty');
    $emptyBalances->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->items()[0]->warehouse_id === $secondWarehouse['id']
        && $rows->items()[0]->on_hand === '3.00000000' && $rows->items()[0]->reserved === '3.00000000'
        && $rows->items()[0]->available === '0.00000000');
    $verify(true, 'InventoryPage empty-available filter retains the known physical and reserved quantities');
    $distributionPage = Livewire::actingAs($actor)->test(InventoryPage::class, ['screen' => 'dashboard']);
    $distributionData = $viewData($distributionPage);
    $distribution = data_get($distributionData, 'distribution');
    $stats = data_get($distributionData, 'stats');
    $verify($distribution
        && $distribution->firstWhere('warehouse_id', $warehouse['id'])?->products === 1
        && $distribution->firstWhere('warehouse_id', $secondWarehouse['id'])?->products === 1
        && $distribution->firstWhere('warehouse_id', $serialWarehouse['id'])?->products === 1
        && $distribution->firstWhere('warehouse_id', $correctionWarehouse['id'])?->products === 1
        && $distribution->firstWhere('warehouse_id', $issueCorrectionWarehouse['id'])?->products === 1
        && $stats['products'] === 2 && $stats['warehouses'] === 5 && $stats['low'] === 2 && $stats['empty'] === 2,
        'InventoryPage distribution and dashboard totals match all five explicitly scoped valued, reserved, correction, and serialized warehouses');
    $movementId = DB::table('inventory_movements')->where('document_id', $transfer['id'])->where('operation', 'transfer_receive')->value('id');
    $movementPage = Livewire::actingAs($actor)->test(InventoryPage::class, ['screen' => 'movements'])
        ->set('warehouse', (string) $secondWarehouse['id'])->set('operation', 'transfer_receive');
    $movementRows = data_get($viewData($movementPage), 'rows');
    $verify($movementRows && $movementRows->total() === 1 && $movementRows->items()[0]->id === $movementId
        && $movementRows->items()[0]->quantity === '3.00000000' && $movementRows->items()[0]->operation === 'transfer_receive',
        'InventoryPage movement operation filter returns the exact transfer receipt');
    $reservationPage = Livewire::actingAs($actor)->test(InventoryPage::class, ['screen' => 'reservations'])
        ->set('warehouse', (string) $secondWarehouse['id'])->set('search', 'summary-zero-available');
    $reservationRows = data_get($viewData($reservationPage), 'rows');
    $verify($reservationRows && $reservationRows->total() === 1 && $reservationRows->items()[0]->id === $summaryReservation['id']
        && $reservationRows->items()[0]->source_id === 'summary-zero-available',
        'InventoryPage reservation source search returns the exact persisted source row');
    $deniedMovement = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $deniedMovement->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $deniedMovement->id, 'warehouse_id' => $secondWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($deniedMovement, ['M06.balance_view'], $branch->id);
    $deniedMovementPage = Livewire::actingAs($deniedMovement)->test(InventoryPage::class, ['screen' => 'movements']);
    $deniedMovementStatus = (function () {
        return $this->lastState->getResponse()->getStatusCode();
    })->call($deniedMovementPage);
    $verify($deniedMovementStatus === 403, 'Balance-only authority cannot query movement history');

    $inventoryAccess = app(InventoryAccess::class);
    $scopeSnapshot = app(OrganizationApi::class)->inventoryScopes($actor->id);
    $verify(collect($scopeSnapshot)->contains(fn ($scope) => (int) $scope['warehouse_id'] === $warehouse['id'] && in_array('M06.balance_view', $scope['permissions'], true)), 'Organization bulk inventory scope includes effective warehouse permissions');
    DB::table('user_warehouse_scopes')->where('user_id', $actor->id)->where('warehouse_id', $warehouse['id'])->delete();
    $verify(! in_array($warehouse['id'], $inventoryAccess->ids($actor->id, 'balance_view'), true)
        && ! collect(app(OrganizationApi::class)->inventoryScopes($actor->id))->contains(fn ($scope) => (int) $scope['warehouse_id'] === $warehouse['id']),
        'Revoking a warehouse scope removes it from bulk scope and inventory query ids');
    DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $navigationActor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $navigationActor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $navigationActor->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($navigationActor, ['M06.balance_view'], $branch->id);
    $navigation = $inventoryAccess->navigation($navigationActor->id);
    $verify(($navigation['balances'] ?? false) === true && ! isset($navigation['warehouses']), 'Balance-only authority exposes balance navigation without requiring warehouse-view permission');

    $destinationReceiver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_branch_scopes')->insert(['user_id' => $destinationReceiver->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $destinationReceiver->id, 'warehouse_id' => $secondWarehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($destinationReceiver, ['M02.product_view', 'M06.transfer_receive'], $branch->id);
    $grant($destinationReceiver, ['M11.journal_post']);
    $grant($destinationReceiver, ['M11.journal_post'], $branch->id);
    $verify(app(InventoryAccess::class)->ids($destinationReceiver->id, 'transfer_receive') === [$secondWarehouse['id']],
        'Destination receiver is scoped to its destination only and lacks source dispatch authority');
    $partialTransfer = $document('transfer', $warehouse['id'], $secondWarehouse['id'], '3.00000000', 'Partial transfer discrepancy lifecycle');
    $partialTransfer = $step($actor->id, $partialTransfer, 'submit');
    $partialTransfer = $step($approver->id, $partialTransfer, 'approve');
    $partialTransfer = $step($actor->id, $partialTransfer, 'dispatch');
    $partialLine = DB::table('inventory_document_lines')->where('document_id', $partialTransfer['id'])->first();
    $transferReceipts = app(InventoryTransfers::class);
    $firstReceiptInput = ['idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'reason' => 'One accepted, one damaged, one missing',
        'evidence' => 'Synthetic receipt evidence', 'lines' => [['line_id' => $partialLine->id, 'accepted' => '1.00000000', 'damaged' => '1.00000000', 'missing' => '1.00000000', 'recovered_missing' => '0.00000000']]];
    $beforeUnauthorizedReceipt = [DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->count(), DB::table('inventory_transfer_receipts')->where('document_id', $partialTransfer['id'])->count()];
    $expect(HttpException::class, fn () => $transferReceipts->receive($destinationReceiver->id, $partialTransfer['id'], $firstReceiptInput), 'Destination-only receiver cannot perform current Inventory transfer-receive action requiring source scope');
    $verify($beforeUnauthorizedReceipt === [DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->count(), DB::table('inventory_transfer_receipts')->where('document_id', $partialTransfer['id'])->count()],
        'Denied destination-only receipt creates neither receipt evidence nor destination movement');
    $partialReceipt = $transferReceipts->receive($actor->id, $partialTransfer['id'], $firstReceiptInput);
    $partialMovementCount = DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->count();
    $partialReceiptReplay = $transferReceipts->receive($actor->id, $partialTransfer['id'], $firstReceiptInput);
    $partialQuantities = $transferReceipts->quantities($partialTransfer['id'])[$partialLine->id];
    $receiptRow = DB::table('inventory_transfer_receipts')->where('idempotency_key', $firstReceiptInput['idempotency_key'])->first();
    $damageMovement = DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->where('operation', 'transfer_receive')
        ->where('idempotency_key', 'like', 'transfer-receipt:'.$receiptRow->id.':'.$partialLine->id.':damaged:lot:%')->first();
    $damageLot = $damageMovement ? DB::table('inventory_transfer_lots as l')->join('inventory_transfer_allocations as a', 'a.lot_id', '=', 'l.id')
        ->where('l.document_id', $partialTransfer['id'])->where('l.line_id', (string) $partialLine->id)->where('a.movement_id', $damageMovement->id)->first(['l.id as lot_id', 'a.quantity']) : null;
    $damageIsolation = $damageMovement ? DB::table('inventory_isolations')->where('source_movement_id', $damageMovement->id)->first() : null;
    $damageKeyPattern = $damageMovement ? preg_match('/^transfer-receipt:([1-9][0-9]*):([1-9][0-9]*):damaged:lot:([1-9][0-9]*)$/D', $damageMovement->idempotency_key, $damageKeyParts) : 0;
    $verify($damageMovement && $damageLot && $damageIsolation && $damageKeyPattern === 1 && $damageKeyParts[1] === (string) $receiptRow->id
        && $damageKeyParts[2] === (string) $partialLine->id && $damageKeyParts[3] === (string) $damageLot->lot_id
        && $damageMovement->source_module === 'Inventory' && $damageMovement->source_id === (string) $partialTransfer['id']
        && $damageMovement->source_line === (string) $partialLine->id && $damageLot->quantity === '1.00000000'
        && $damageIsolation->quantity === '1.00000000'
        && $damageIsolation->idempotency_key === Uuid::uuid5(Uuid::NAMESPACE_URL, $damageMovement->idempotency_key.':damage')->toString(),
        'Damaged receipt isolation binds the exact transfer, line, FIFO lot, movement quantity, and deterministic per-lot idempotency source');
    $acceptedMovement = DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->where('operation', 'transfer_receive')
        ->where('quantity', '1.00000000')->where('id', '<>', $damageMovement->id)->orderBy('id')->first();
    $expect(HttpException::class, fn () => app(InventoryConditions::class)->recordTransferDamage($actor->id, [
        'warehouse_id' => $secondWarehouse['id'], 'product_id' => $product->id, 'source_movement_id' => $acceptedMovement->id, 'quantity' => $acceptedMovement->quantity,
        'reason' => 'Forged accepted movement as damage', 'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, $acceptedMovement->idempotency_key.':damage')->toString(), 'serials' => [],
    ]), 'An accepted FIFO movement cannot be forged as damaged stock');
    $partialDestination = DB::table('inventory_balances')->where('warehouse_id', $secondWarehouse['id'])->where('product_id', $product->id)->first();
    $verify($partialReceipt['status'] === 'dispatched' && $partialReceiptReplay['status'] === 'dispatched'
        && $partialMovementCount === 3 && DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->count() === $partialMovementCount
        && $partialQuantities['accepted'] === '1.00000000' && $partialQuantities['damaged'] === '1.00000000'
        && $partialQuantities['missing'] === '1.00000000' && $partialQuantities['outstanding'] === '0.00000000'
        && $partialQuantities['in_transit'] === '1.00000000' && $partialDestination->on_hand === '5.00000000'
        && $partialDestination->unavailable === '1.00000000' && $partialDestination->available === '1.00000000',
        'Fully scoped receiver persists accepted, damaged and missing receipt quantities with idempotent ledger, quarantine and transit totals');
    $recoveryInput = ['idempotency_key' => (string) Illuminate\Support\Str::uuid(), 'reason' => 'Previously missing unit recovered',
        'lines' => [['line_id' => $partialLine->id, 'accepted' => '1.00000000', 'damaged' => '0.00000000', 'missing' => '0.00000000', 'recovered_missing' => '1.00000000']]];
    $recoveredTransfer = $transferReceipts->receive($actor->id, $partialTransfer['id'], $recoveryInput);
    $recoveredQuantities = $transferReceipts->quantities($partialTransfer['id'])[$partialLine->id];
    $partialDestination = DB::table('inventory_balances')->where('warehouse_id', $secondWarehouse['id'])->where('product_id', $product->id)->first();
    $verify($recoveredTransfer['status'] === 'received' && $recoveredQuantities['accepted'] === '2.00000000'
        && $recoveredQuantities['damaged'] === '1.00000000' && $recoveredQuantities['missing'] === '0.00000000'
        && $recoveredQuantities['in_transit'] === '0.00000000' && $partialDestination->on_hand === '6.00000000'
        && $partialDestination->unavailable === '1.00000000' && $partialDestination->reserved === '3.00000000'
        && $partialDestination->available === '2.00000000',
        'Recovered missing unit closes transit while damaged stock remains unavailable and accepted stock increases availability');
    $transferCorrectionKey = (string) Illuminate\Support\Str::uuid();
    $transferSourceMovement = DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->where('operation', 'transfer_dispatch')->first();
    $corrections = app(InventoryCorrections::class);
    $beforePendingCorrection = DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->count();
    $pendingTransferCorrection = $corrections->reverse($actor->id, ['movement_id' => $transferSourceMovement->id, 'quantity' => '1.00000000',
        'reason' => 'Transfer reversal requires financial reconciliation', 'idempotency_key' => $transferCorrectionKey]);
    $pendingTransferCorrectionReplay = $corrections->reverse($actor->id, ['movement_id' => $transferSourceMovement->id, 'quantity' => '1.00000000',
        'reason' => 'Transfer reversal requires financial reconciliation', 'idempotency_key' => $transferCorrectionKey]);
    $verify($pendingTransferCorrection['status'] === 'pending_financial' && $pendingTransferCorrectionReplay['id'] === $pendingTransferCorrection['id']
        && $pendingTransferCorrection['correcting_movement_id'] === null
        && DB::table('inventory_movements')->where('document_id', $partialTransfer['id'])->count() === $beforePendingCorrection,
        'Source-linked transfer correction records pending financial status and never changes stock without eligible reversal effects');

    $correctionDoc = static function (string $kind, int $warehouseId, string $quantity, string $reason, ?string $unitCost = null, ?string $costReference = null) use ($doc, $actor, $product): array {
        $line = ['product_id' => $product->id, 'quantity' => $quantity];
        if ($unitCost !== null) {
            $line += ['unit_cost' => $unitCost, 'cost_reference' => $costReference];
        }

        return $doc->save($actor->id, $kind, [
            'warehouse_id' => $warehouseId, 'reason' => $reason, 'creation_key' => (string) Illuminate\Support\Str::uuid(),
            'lines' => [$line],
        ]);
    };
    $correctionOpening = $correctionDoc('adjustment', $correctionWarehouse['id'], '3.00000000', 'Costed correction reversal source adjustment', '10.0000000000000000', 'Synthetic M06 positive adjustment cost source');
    $correctionOpening = $step($actor->id, $correctionOpening, 'submit');
    $correctionOpening = $step($approver->id, $correctionOpening, 'approve');
    $correctionOpening = $step($actor->id, $correctionOpening, 'post');
    $positiveMovement = DB::table('inventory_movements')->where('document_id', $correctionOpening['id'])->where('operation', 'adjustment_post')->first();
    $openingCorrectionKey = (string) Illuminate\Support\Str::uuid();
    $openingCorrectionInput = ['movement_id' => $positiveMovement->id, 'quantity' => '1.00000000', 'reason' => 'Remove part of erroneous opening', 'idempotency_key' => $openingCorrectionKey];
    $openingCorrection = $corrections->reverse($actor->id, $openingCorrectionInput);
    $openingCorrectionReplay = $corrections->reverse($actor->id, $openingCorrectionInput);
    $correctingMovement = DB::table('inventory_movements')->find($openingCorrection['correcting_movement_id']);
    $verify($openingCorrection['status'] === 'posted' && $openingCorrectionReplay['id'] === $openingCorrection['id']
        && $correctingMovement->quantity === '-1.00000000' && $correctingMovement->reversal_id === $positiveMovement->id
        && DB::table('inventory_balances')->where('warehouse_id', $correctionWarehouse['id'])->where('product_id', $product->id)->value('on_hand') === '5.00000000',
        'Eligible source-linked positive movement correction posts one immutable negative reversing movement and replays exactly');
    $expect(ValidationException::class, fn () => $corrections->reverse($actor->id, [...$openingCorrectionInput, 'quantity' => '2.00000000']), 'Correction idempotency key rejects changed payload');
    $laterAdjustment = $correctionDoc('adjustment', $correctionWarehouse['id'], '1.00000000', 'Later movement closes correction window', '10.0000000000000000', 'Synthetic later adjustment cost');
    $laterAdjustment = $step($actor->id, $laterAdjustment, 'submit');
    $laterAdjustment = $step($approver->id, $laterAdjustment, 'approve');
    $laterAdjustment = $step($actor->id, $laterAdjustment, 'post');
    $movementCountBeforeStaleCorrection = DB::table('inventory_movements')->where('warehouse_id', $correctionWarehouse['id'])->where('product_id', $product->id)->count();
    $expect(ValidationException::class, fn () => $corrections->reverse($actor->id, ['movement_id' => $positiveMovement->id, 'quantity' => '1.00000000',
        'reason' => 'Stale source correction attempt', 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]), 'Later physical movement prevents correction of a stale source');
    $verify(DB::table('inventory_movements')->where('warehouse_id', $correctionWarehouse['id'])->where('product_id', $product->id)->count() === $movementCountBeforeStaleCorrection,
        'Rejected stale correction creates no partial reversing movement');

    $sourceIssue = $correctionDoc('issue', $issueCorrectionWarehouse['id'], '1.00000000', 'Source issue to correct');
    $sourceIssue = $step($actor->id, $sourceIssue, 'submit');
    $sourceIssue = $step($approver->id, $sourceIssue, 'approve');
    $sourceIssue = $step($actor->id, $sourceIssue, 'post');
    $negativeMovement = DB::table('inventory_movements')->where('document_id', $sourceIssue['id'])->where('operation', 'issue_post')->first();
    $issueCorrection = $corrections->reverse($actor->id, ['movement_id' => $negativeMovement->id, 'quantity' => '1.00000000',
        'reason' => 'Restore eligible erroneous issue', 'idempotency_key' => (string) Illuminate\Support\Str::uuid()]);
    $restoringMovement = DB::table('inventory_movements')->find($issueCorrection['correcting_movement_id']);
    $verify($issueCorrection['status'] === 'posted' && $restoringMovement->quantity === '1.00000000'
        && $restoringMovement->reversal_id === $negativeMovement->id
        && DB::table('inventory_balances')->where('warehouse_id', $issueCorrectionWarehouse['id'])->where('product_id', $product->id)->value('on_hand') === '2.00000000',
        'Eligible source-linked negative issue correction posts one exact positive restoring movement');

    $serialPage = Livewire::actingAs($actor)->test(InventoryOperationsPage::class, ['screen' => 'serials'])
        ->set('warehouse', (string) $serialWarehouse['id'])->set('search', 'Serial-B');
    $serialPageData = (function () {
        return $this->lastState->getResponse()->original->gatherData();
    })->call($serialPage);
    $serialPageRows = data_get($serialPageData, 'rows');
    $verify($serialPageRows && $serialPageRows->total() === 0 && data_get($serialPageData, 'history')->isEmpty(),
        'Serial operations screen does not fabricate an identity or history without a supported valued serial opening source');

    $uiIsolationPage = Livewire::actingAs($actor)->test(InventoryOperationsPage::class, ['screen' => 'isolations'])
        ->set('form.source_movement_id', (string) $openingMovement->id)->set('form.quantity', '1.00000000')
        ->set('form.reason', 'Livewire isolation persistence check')->call('execute');
    $uiIsolation = DB::table('inventory_isolations')->where('reason', 'Livewire isolation persistence check')->first();
    $verify($uiIsolation !== null && $uiIsolation->quantity === '1.00000000', 'Authorized isolation operations form persists a source-linked isolation');
    Livewire::actingAs($actor)->test(InventoryOperationsPage::class, ['screen' => 'isolations'])
        ->set('releaseQuantities.'.$uiIsolation->id, '1.00000000')->call('release', $uiIsolation->id);
    $uiIsolation = DB::table('inventory_isolations')->find($uiIsolation->id);
    $verify($uiIsolation->released === '1.00000000', 'Authorized isolation operations form releases and persists the residual quantity');

    $uiTransfer = $document('transfer', $warehouse['id'], $secondWarehouse['id'], '1.00000000', 'Livewire partial receipt persistence check');
    $uiTransfer = $step($actor->id, $uiTransfer, 'submit');
    $uiTransfer = $step($approver->id, $uiTransfer, 'approve');
    $uiTransfer = $step($actor->id, $uiTransfer, 'dispatch');
    $uiReceipt = Livewire::actingAs($actor)->test(InventoryDocumentPage::class, ['kind' => 'transfer', 'document' => $uiTransfer['id']])
        ->set('receiptReason', 'Livewire receiving evidence')->set('receiptEvidence', 'test-only receipt record')
        ->set('receiptLines.0.accepted', '1.00000000')->call('receivePartial');
    $uiReceiptRow = DB::table('inventory_transfer_receipts')->where('document_id', $uiTransfer['id'])->first();
    $uiReceiptLine = $uiReceiptRow ? DB::table('inventory_transfer_receipt_lines')->where('receipt_id', $uiReceiptRow->id)->first() : null;
    $verify($uiReceiptRow !== null && $uiReceiptLine !== null && $uiReceiptLine->accepted === '1.00000000'
        && DB::table('inventory_movements')->where('document_id', $uiTransfer['id'])->where('operation', 'transfer_receive')->count() === 1,
        'Fully scoped Livewire receiving form persists an accepted partial receipt and one destination movement');

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $verify(true, 'Deferred ledger, serial occupancy, balance projection, and receipt constraints hold at commit boundary');

    echo "M06 inventory focused checks passed.\n";
} finally {
    foreach ($privateExports ?? [] as $path) {
        @unlink($path);
    }
    foreach ($temporaryFiles ?? [] as $path) {
        @unlink($path);
    }
    DB::rollBack();
    $companyAfterRollback = DB::table('companies')->where('id', 1)->first();
    $companyAfterRollback = $companyAfterRollback === null ? null : (array) $companyAfterRollback;
    if ($companyAfterRollback !== $companyBaseline) {
        throw new RuntimeException('M06 synthetic company fixture did not restore the exact pre-check baseline after rollback.');
    }
    echo "COMPANY_BASELINE_RESTORED=1\n";
    echo "Disposable PostgreSQL test transaction rolled back.\n";
}

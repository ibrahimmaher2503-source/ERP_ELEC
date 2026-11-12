<?php

use App\Models\User;
use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\ProductActions;
use App\Modules\Products\Application\ProductDataDefinition;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Products\Presentation\CatalogPage;
use App\Modules\System\Application\DataOperations\OperationService;
use App\Modules\System\Domain\DataOperationRun;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use App\Modules\System\Presentation\DataOperationPage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Data operations check requires the separate PostgreSQL test database.');
}

function dataOperationsVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
}

function dataOperationsThrows(Closure $action, string $message, ?string $expectedClass = null): Throwable
{
    try {
        $action();
    } catch (Throwable $exception) {
        if ($expectedClass !== null && ! $exception instanceof $expectedClass) {
            throw new RuntimeException($message.' (unexpected '.get_class($exception).')', 0, $exception);
        }

        dataOperationsVerify(true, $message);

        return $exception;
    }

    throw new RuntimeException($message.' (no exception)');
}

function dataOperationsForbidden(Closure $action, string $message): void
{
    $exception = dataOperationsThrows($action, $message, HttpException::class);
    dataOperationsVerify($exception->getStatusCode() === 403, $message.' returns 403');
}

function dataOperationsGrant(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert([
            'user_id' => $user->id,
            'permission_code' => $permission,
            'branch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function dataOperationsProductInput(Product $product, array $overrides = []): array
{
    return array_replace($product->only([
        'sku', 'name', 'name_en', 'short_description', 'description', 'kind', 'tracking',
        'category_id', 'brand_id', 'model', 'base_unit_id', 'barcode', 'active',
    ]), $overrides);
}

function dataOperationsWriteWorkbook(string $template, string $path, array $columns, array $rows, ?string $version = null, ?array $formula = null): void
{
    $reader = new Xlsx;
    $workbook = $reader->load($template);

    try {
        $data = $workbook->getSheetByName('Data');
        foreach ($rows as $rowIndex => $row) {
            foreach ($columns as $columnIndex => $column) {
                $value = $row[$column['key']] ?? '';
                $cell = $data->getCell([$columnIndex + 1, $rowIndex + 2]);
                $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }

        if ($formula !== null) {
            $column = array_search($formula['field'], array_column($columns, 'key'), true);
            $data->getCell([$column + 1, $formula['row'] ?? 2])->setValue($formula['value']);
        }

        if ($version !== null) {
            $workbook->getSheetByName('_erp_template')->getCell('B2')->setValueExplicit($version, DataType::TYPE_STRING);
        }

        (new XlsxWriter($workbook))->save($path);
    } finally {
        $workbook->disconnectWorksheets();
    }
}

function dataOperationsUpload(string $path, string $name = 'products.xlsx'): UploadedFile
{
    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true);
}

function dataOperationsReadExport(string $path): array
{
    $reader = new Xlsx;
    $workbook = $reader->load($path);

    try {
        $sheet = $workbook->getSheetByName('Data');

        return [
            'headings' => $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, true, false)[0],
            'rows' => $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().$sheet->getHighestRow(), null, true, false),
            'name_value_type' => $sheet->getCell('B2')->getDataType(),
        ];
    } finally {
        $workbook->disconnectWorksheets();
    }
}

$suffix = strtoupper(bin2hex(random_bytes(5)));
$testDiskParent = storage_path('framework/testing/disks');
$testDiskRoot = $testDiskParent.DIRECTORY_SEPARATOR.'data-operations-'.$suffix;
File::ensureDirectoryExists($testDiskParent);
if (! str_starts_with(strtolower($testDiskRoot), strtolower($testDiskParent.DIRECTORY_SEPARATOR))) {
    throw new RuntimeException('Refusing to use a media test path outside the isolated test disk folder.');
}
File::ensureDirectoryExists($testDiskRoot);
$originalLocalRoot = config('filesystems.disks.local.root');
config(['filesystems.disks.local.root' => $testDiskRoot]);
Storage::forgetDisk('local');

$privateArtifacts = [];
DB::beginTransaction();
try {
    $capabilities = [
        'M02.product_bulk_update', 'M02.product_import', 'M02.product_export', 'M02.product_template',
    ];
    $permissions = DB::table('permission_catalog')->whereIn('code', $capabilities)->get(['code', 'scope']);
    dataOperationsVerify($permissions->count() === 4 && $permissions->every(fn ($row) => $row->scope === 'company'), 'bulk/import/export/template capabilities are registered at company scope');
    $administratorRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    $administratorCapabilities = DB::table('role_permissions')->where('role_id', $administratorRoleId)->whereIn('permission_code', $capabilities)->pluck('permission_code')->all();
    $nonAdministratorCapability = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('r.code', '<>', 'Administrator')->whereIn('rp.permission_code', $capabilities)->exists();
    dataOperationsVerify($administratorRoleId > 0 && count($administratorCapabilities) === count($capabilities) && ! $nonAdministratorCapability,
        'Data-operation capabilities default-deny for non-administrator roles while Administrator receives the registered keys');

    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $viewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $otherActor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $readOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_roles')->insert([
        'user_id' => $readOnly->id,
        'role_id' => DB::table('roles')->where('code', 'ManagerReadOnly')->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    dataOperationsVerify(! app(ProductAccess::class)->allows($readOnly->id, 'M02.product_bulk_update'),
        'ManagerReadOnly role membership does not grant a data-operation mutation');
    $grants = ['M02.product_view', 'M02.product_create', 'M02.product_update', 'M02.product_status', ...$capabilities];
    dataOperationsGrant($actor, $grants);
    dataOperationsGrant($viewer, ['M02.product_view']);
    dataOperationsGrant($otherActor, $grants);
    $previewFactory = DataOperationRun::factory()->make(['actor_id' => $actor->id]);
    dataOperationsVerify($previewFactory->status === 'preview' && $previewFactory->completed_at === null, 'technical receipt factory creates only an uncompleted preview fixture');

    $category = Category::factory()->create();
    $brand = Brand::factory()->create();
    $unit = Unit::factory()->state(['dimension' => 'count'])->create();
    $actions = app(ProductActions::class);
    $createProduct = static function (string $sku, string $name, array $overrides = []) use ($actor, $actions, $category, $brand, $unit): Product {
        return $actions->save($actor->id, array_replace([
            'sku' => $sku,
            'name' => $name,
            'name_en' => 'Data operations '.$sku,
            'short_description' => 'Short '.$sku,
            'description' => 'Description '.$sku,
            'kind' => 'bulk',
            'tracking' => 'quantity',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'model' => 'MODEL-'.$sku,
            'base_unit_id' => $unit->id,
            'barcode' => 'BC-'.$sku,
            'active' => true,
        ], $overrides), null, null, [], []);
    };
    $service = app(OperationService::class);
    $definition = app(ProductDataDefinition::class);
    $codec = app(WorkbookCodec::class);
    $columns = $definition->columns();

    foreach (['bulk', 'import', 'export', 'template'] as $capability) {
        dataOperationsForbidden(fn () => $service->{$capability === 'bulk' ? 'previewBulk' : ($capability === 'import' ? 'previewImport' : ($capability === 'export' ? 'export' : 'template'))}(
            $viewer->id,
            'products',
            ...match ($capability) {
                'bulk' => [[], ['model' => ['mode' => 'set', 'value' => 'DENIED']]],
                'import' => [dataOperationsUpload(__FILE__), 'create'],
                'export' => ['all'],
                default => [],
            },
        ), "{$capability} requires its own permission");
    }

    $productA = $createProduct('OPS-A-'.$suffix, 'Bulk target A');
    $productB = $createProduct('OPS-B-'.$suffix, 'Bulk target B');
    $beforeA = $productA->fresh()->toArray();
    $beforeB = $productB->fresh()->toArray();
    $invalidBulk = $service->previewBulk($actor->id, 'products', [$productA->id], ['internal_notes' => ['mode' => 'set', 'value' => 'not allowed']]);
    dataOperationsVerify($invalidBulk->items()->first()->status === 'invalid' && $productA->fresh()->name === $beforeA['name'], 'bulk preview rejects identity/sensitive fields without changing products');
    $unknownBulk = $service->previewBulk($actor->id, 'products', [$productA->id], ['unknown_field' => ['mode' => 'set', 'value' => 'x']]);
    dataOperationsVerify($unknownBulk->items()->first()->status === 'invalid' && $productA->fresh()->name === $beforeA['name'], 'unknown bulk fields fail closed without changing products');

    $bulk = $service->previewBulk($actor->id, 'products', [$productA->id, $productB->id], [
        'model' => ['mode' => 'set', 'value' => 'BULK-'.$suffix],
        'short_description' => ['mode' => 'clear'],
        'description' => ['mode' => 'keep'],
    ]);
    dataOperationsVerify($bulk->status === 'preview' && $bulk->summary['valid'] === 2, 'bulk preview persists two valid plans before execution');
    dataOperationsVerify($productA->fresh()->model === $beforeA['model'] && $productB->fresh()->model === $beforeB['model'], 'bulk preview does not mutate product records');
    dataOperationsVerify($bulk->items()->first()->after_values['model'] === 'BULK-'.$suffix && $bulk->items()->first()->after_values['short_description'] === null
        && $bulk->items()->first()->after_values['description'] === $beforeA['description'], 'bulk preview applies set/clear and preserves keep semantics');
    dataOperationsThrows(fn () => $service->owned($otherActor->id, $bulk->id), 'another actor cannot read the operation receipt', ModelNotFoundException::class);

    $manualA = $productA->fresh();
    $actions->save($actor->id, dataOperationsProductInput($manualA, ['name' => 'Concurrent edit '.$suffix]), $manualA->id, $manualA->version, [], []);
    $service->confirm($actor->id, $bulk->id, dispatch: false);
    $service->confirm($actor->id, $bulk->id, dispatch: false);
    $service->process($bulk->id);
    $auditA = DB::table('audit_entries')->where('source_module', 'Products')->where('source_id', $productA->id)->count();
    $auditB = DB::table('audit_entries')->where('source_module', 'Products')->where('source_id', $productB->id)->count();
    dataOperationsVerify($bulk->fresh()->status === 'completed' && $bulk->fresh()->summary['succeeded'] === 1 && $bulk->fresh()->summary['failed'] === 1, 'bulk execution reports a stale target while committing eligible rows');
    dataOperationsVerify($productA->fresh()->name === 'Concurrent edit '.$suffix && $productA->fresh()->model === $beforeA['model']
        && $productB->fresh()->model === 'BULK-'.$suffix && $productB->fresh()->short_description === null
        && $productB->fresh()->description === $beforeB['description'], 'bulk execution uses normal optimistic Actions and only changes selected fields');
    $service->process($bulk->id);
    $service->confirm($actor->id, $bulk->id, dispatch: false);
    dataOperationsVerify($productB->fresh()->version === $beforeB['version'] + 1
        && DB::table('audit_entries')->where('source_module', 'Products')->where('source_id', $productA->id)->count() === $auditA
        && DB::table('audit_entries')->where('source_module', 'Products')->where('source_id', $productB->id)->count() === $auditB,
        'repeated confirmation and processing do not repeat committed updates or audit entries');

    $template = $service->template($actor->id, 'products');
    $privateArtifacts[] = $template;
    dataOperationsVerify(is_file($template) && filesize($template) > 0, 'authorized actor receives a private XLSX template');
    $emptyRows = $codec->read($template, 'products', $definition->version(), $columns);
    $templateBook = (new Xlsx)->load($template);
    try {
        dataOperationsVerify($emptyRows === [] && $templateBook->getSheetNames() === ['Data', 'Instructions', 'Lookups', '_erp_template']
            && $templateBook->getSheetByName('_erp_template')->getCell('B2')->getValue() === $definition->version(), 'official template round-trips with protected entity/version metadata');
    } finally {
        $templateBook->disconnectWorksheets();
    }

    $writeWorkbook = static function (array $rows, ?string $version = null, ?array $formula = null) use ($template, $columns, $testDiskRoot, &$privateArtifacts, $suffix): string {
        $path = $testDiskRoot.DIRECTORY_SEPARATOR.'workbook-'.strtolower($suffix).'-'.bin2hex(random_bytes(3)).'.xlsx';
        dataOperationsWriteWorkbook($template, $path, $columns, $rows, $version, $formula);
        $privateArtifacts[] = $path;

        return $path;
    };
    $row = static fn (string $sku, string $name, string $barcode, string $categoryCode, string $unitCode, string $brandCode = ''): array => [
        'sku' => $sku, 'name' => $name, 'name_en' => '', 'kind' => 'bulk', 'tracking' => 'quantity',
        'category_code' => $categoryCode, 'brand_code' => $brandCode, 'base_unit_code' => $unitCode,
        'barcode' => $barcode, 'model' => 'IMPORT-'.$sku, 'short_description' => '', 'description' => '', 'active' => '1',
    ];
    $productCount = Product::count();
    $badVersion = $writeWorkbook([$row('BAD-VERSION-'.$suffix, 'Wrong version', 'BC-BAD-V-'.$suffix, $category->code, $unit->code)], 'M02-products-old');
    $versionError = dataOperationsThrows(fn () => $service->previewImport($actor->id, 'products', dataOperationsUpload($badVersion), 'create'), 'old workbook template versions are rejected', ValidationException::class);
    dataOperationsVerify(str_contains(implode(' ', $versionError->errors()['file'] ?? []), 'template version'), 'wrong-version error explains the incompatibility');
    $formula = $writeWorkbook([$row('FORMULA-'.$suffix, 'Will become formula', 'BC-FORMULA-'.$suffix, $category->code, $unit->code)], null, ['field' => 'name', 'value' => '=1+1']);
    $formulaError = dataOperationsThrows(fn () => $service->previewImport($actor->id, 'products', dataOperationsUpload($formula), 'create'), 'formula cells are rejected before preview', ValidationException::class);
    dataOperationsVerify(str_contains(implode(' ', $formulaError->errors()['file'] ?? []), 'Formula cells'), 'formula rejection identifies the unsafe workbook content');
    dataOperationsVerify(Product::count() === $productCount, 'invalid workbook metadata and formulas never mutate Products');

    $duplicates = $writeWorkbook([
        $row('DUP-SKU-'.$suffix, 'Duplicate one', 'BC-DUP-S1-'.$suffix, $category->code, $unit->code),
        $row('DUP-SKU-'.$suffix, 'Duplicate two', 'BC-DUP-S2-'.$suffix, $category->code, $unit->code),
        $row('UNIQUE-S1-'.$suffix, 'Barcode duplicate one', 'BC-DUPLICATE-'.$suffix, $category->code, $unit->code),
        $row('UNIQUE-S2-'.$suffix, 'Barcode duplicate two', 'BC-DUPLICATE-'.$suffix, $category->code, $unit->code),
    ]);
    $duplicateRun = $service->previewImport($actor->id, 'products', dataOperationsUpload($duplicates), 'create');
    $duplicateItems = $duplicateRun->items()->orderBy('row_number')->get();
    dataOperationsVerify($duplicateRun->summary['invalid'] === 4 && $duplicateItems->take(2)->every(fn ($item) => str_contains($item->error, 'sku'))
        && $duplicateItems->skip(2)->every(fn ($item) => str_contains($item->error, 'barcode')), 'duplicate SKUs and barcodes are reported on every affected row');
    $unknownReference = $writeWorkbook([$row('UNKNOWN-REF-'.$suffix, 'Unknown category', 'BC-UNKNOWN-'.$suffix, 'NO-SUCH-CATEGORY', $unit->code)]);
    $unknownReferenceRun = $service->previewImport($actor->id, 'products', dataOperationsUpload($unknownReference), 'create');
    dataOperationsVerify($unknownReferenceRun->summary['invalid'] === 1 && str_contains($unknownReferenceRun->items()->first()->error, 'كود المرجع غير موجود'), 'unknown reference codes produce a row validation error');
    dataOperationsVerify(Product::count() === $productCount, 'invalid import previews leave Product data unchanged');

    $createRow = $row('IMPORT-CREATE-'.$suffix, 'Imported create '.$suffix, 'BC-IMPORT-CREATE-'.$suffix, $category->code, $unit->code, $brand->code);
    $createWorkbook = $writeWorkbook([$createRow]);
    $createRun = $service->previewImport($actor->id, 'products', dataOperationsUpload($createWorkbook), 'create');
    dataOperationsVerify($createRun->summary['valid'] === 1 && ! Product::where('sku', $createRow['sku'])->exists(), 'create import preview validates without inserting a Product');
    dataOperationsThrows(fn () => $service->owned($otherActor->id, $createRun->id), 'operation receipt is hidden from a different actor', ModelNotFoundException::class);
    DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M02.product_import')->delete();
    dataOperationsForbidden(fn () => $service->confirm($actor->id, $createRun->id, dispatch: false), 'execution rechecks current import permission after preview');
    dataOperationsGrant($actor, ['M02.product_import']);
    $service->confirm($actor->id, $createRun->id, dispatch: false);
    $service->confirm($actor->id, $createRun->id, dispatch: false);
    $service->process($createRun->id);
    $created = Product::where('sku', $createRow['sku'])->firstOrFail();
    $createAuditCount = DB::table('audit_entries')->where('source_module', 'Products')->where('source_id', $created->id)->count();
    dataOperationsVerify($createRun->fresh()->status === 'completed' && $createRun->fresh()->summary['created'] === 1
        && $created->name === $createRow['name'] && $created->barcode === $createRow['barcode'], 'confirmed import creates a Product through ProductActions and completes its receipt');
    $service->process($createRun->id);
    dataOperationsVerify($created->fresh()->version === 1
        && DB::table('audit_entries')->where('source_module', 'Products')->where('source_id', $created->id)->count() === $createAuditCount,
        'reprocessing a completed import adds no duplicate product or audit entry');

    $updateProduct = $createProduct('IMPORT-UPDATE-'.$suffix, 'Original update target', ['name_en' => 'Keep English', 'model' => 'CLEAR-ME', 'description' => 'Keep description']);
    $staleProduct = $createProduct('IMPORT-STALE-'.$suffix, 'Original stale target');
    $updateRow = $row($updateProduct->sku, 'Updated name '.$suffix, '', '', $unit->code);
    $updateRow['name_en'] = '';
    $updateRow['category_code'] = '';
    $updateRow['brand_code'] = '';
    $updateRow['model'] = '#CLEAR';
    $updateRow['description'] = '';
    $staleRow = $row($staleProduct->sku, 'Stale import should not win', '', '', $unit->code);
    $staleRow['name_en'] = '';
    $staleRow['category_code'] = '';
    $staleRow['brand_code'] = '';
    $staleRow['barcode'] = '';
    $staleRow['model'] = '';
    $staleRow['short_description'] = '';
    $staleRow['description'] = '';
    $updateWorkbook = $writeWorkbook([$updateRow, $staleRow]);
    $updateRun = $service->previewImport($actor->id, 'products', dataOperationsUpload($updateWorkbook), 'update');
    dataOperationsVerify($updateRun->summary['valid'] === 2 && $updateProduct->fresh()->name === 'Original update target'
        && $updateRun->items()->first()->after_values['name_en'] === 'Keep English'
        && $updateRun->items()->first()->after_values['description'] === 'Keep description'
        && $updateRun->items()->first()->after_values['model'] === null, 'update preview preserves blank cells and applies only explicit #CLEAR');
    $service->confirm($actor->id, $updateRun->id, dispatch: false);
    $currentStale = $staleProduct->fresh();
    $actions->save($actor->id, dataOperationsProductInput($currentStale, ['name' => 'Concurrent import edit '.$suffix]), $currentStale->id, $currentStale->version, [], []);
    $service->process($updateRun->id);
    dataOperationsVerify($updateProduct->fresh()->name === $updateRow['name'] && $updateProduct->fresh()->name_en === 'Keep English'
        && $updateProduct->fresh()->barcode === 'BC-IMPORT-UPDATE-'.$suffix && $updateProduct->fresh()->description === 'Keep description'
        && $updateProduct->fresh()->model === null && $updateRun->fresh()->summary['succeeded'] === 1
        && $updateRun->fresh()->summary['failed'] === 1 && $staleProduct->fresh()->name === 'Concurrent import edit '.$suffix,
        'update import persists through ProductActions, keeps blank fields, and rejects stale rows');

    $revokedRun = $service->previewImport($actor->id, 'products', dataOperationsUpload($updateWorkbook), 'update');
    $service->confirm($actor->id, $revokedRun->id, dispatch: false);
    DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M02.product_import')->delete();
    $service->process($revokedRun->id);
    dataOperationsGrant($actor, ['M02.product_import']);
    dataOperationsVerify($revokedRun->fresh()->summary['failed'] === 1 && $revokedRun->fresh()->summary['succeeded'] === 0
        && $revokedRun->fresh()->summary['skipped'] === 1
        && $updateProduct->fresh()->name === $updateRow['name']
        && $staleProduct->fresh()->name === 'Concurrent import edit '.$suffix,
        'worker rechecks current import permission and fails closed after revocation');

    $formulaProduct = $createProduct('EXPORT-FORMULA-'.$suffix, '=1+1', ['barcode' => 'BC-EXPORT-FORMULA-'.$suffix]);
    $selectedExport = $service->export($actor->id, 'products', 'selected', [], [$formulaProduct->id, $created->id]);
    $privateArtifacts[] = $selectedExport;
    $selectedRows = dataOperationsReadExport($selectedExport);
    dataOperationsVerify(count($selectedRows['rows']) === 2 && $selectedRows['name_value_type'] === DataType::TYPE_STRING
        && in_array('=1+1', array_column($selectedRows['rows'], 1), true), 'selected export opens as XLSX and formula-like product text stays a string');
    $filteredExport = $service->export($actor->id, 'products', 'filtered', ['search' => $formulaProduct->sku]);
    $privateArtifacts[] = $filteredExport;
    $filteredRows = dataOperationsReadExport($filteredExport);
    dataOperationsVerify(count($filteredRows['rows']) === 1 && $filteredRows['rows'][0][0] === $formulaProduct->sku, 'filtered export contains only records matching the server-side filter');
    dataOperationsForbidden(fn () => $service->export($viewer->id, 'products', 'all'), 'exports require the separate export permission');
    dataOperationsForbidden(fn () => $service->template($viewer->id, 'products'), 'template download requires its separate permission');

    $allSelectedIds = Product::where('sku', 'ilike', '%'.$suffix)->orderBy('sku')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    dataOperationsVerify(count($allSelectedIds) === 6, 'selection fixture contains only the six products created by this check');
    $guard = Auth::guard();
    $previousActor = $guard->user();
    $guard->setUser($actor);
    try {
        $catalog = Livewire::test(CatalogPage::class)->set('search', $suffix)->set('perPage', 5)
            ->set('selected', [$allSelectedIds[0]])->assertSet('selected', [$allSelectedIds[0]])
            ->call('clearSelection')->call('selectVisible')->assertSet('selected', array_slice($allSelectedIds, 0, 5));
        $catalog->call('setPage', 2)->call('selectVisible')->assertSet('selected', $allSelectedIds)
            ->call('selectVisible')->assertSet('selected', array_slice($allSelectedIds, 0, 5))
            ->call('clearSelection')->assertSet('selected', []);
        dataOperationsVerify(true, 'catalog supports one/many visible selections, preserves page selections, toggles the visible page and clears');
        Livewire::test(DataOperationPage::class)
            ->assertSee('تحميل قالب Excel')
            ->assertSee($definition->version())
            ->assertSee('نزّل القالب أولًا');
        dataOperationsVerify(true, 'shared operation page renders the authorized template-first workflow');
        Livewire::test(DataOperationPage::class, ['entity' => 'products', 'runId' => $createRun->id])
            ->assertSee('اكتمل التنفيذ')
            ->assertSee($createRow['sku']);
        dataOperationsVerify(true, 'shared operation page renders a persisted completed import receipt');
    } finally {
        if ($previousActor !== null) {
            $guard->setUser($previousActor);
        } else {
            $guard->forgetUser();
        }
    }

    dataOperationsVerify($service->owned($actor->id, $createRun->id)->actor_id === $actor->id, 'run receipt remains readable by its authorized owner');
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::forgetDisk('local');
    config(['filesystems.disks.local.root' => $originalLocalRoot]);
    Storage::forgetDisk('local');
    File::deleteDirectory($testDiskRoot);
    foreach ($privateArtifacts as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    echo "Disposable data-operations database transaction rolled back.\n";
}

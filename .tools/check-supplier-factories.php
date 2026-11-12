<?php

use App\Models\User;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Parties\Application\SupplierAccess;
use App\Modules\Parties\Application\SupplierActions;
use App\Modules\Parties\Application\SupplierCatalogQuery;
use App\Modules\Parties\Application\SupplierCategories;
use App\Modules\Parties\Application\SupplierDataDefinition;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\Parties\Domain\SupplierContact;
use App\Modules\Parties\Domain\SupplierDocument;
use App\Modules\Parties\Domain\SupplierNote;
use App\Modules\Parties\Presentation\CatalogPage;
use App\Modules\Parties\Presentation\CustomerDetailsPage;
use App\Modules\Parties\Presentation\CustomerFormPage;
use App\Modules\Parties\Presentation\SupplierCatalogPage;
use App\Modules\Parties\Presentation\SupplierCategoriesPage;
use App\Modules\Parties\Presentation\SupplierDetailsPage;
use App\Modules\Parties\Presentation\SupplierFormPage;
use App\Modules\System\Application\DataOperations\OperationService;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Database\Seeders\SupplierDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
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

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};
$expect = static function (string $exceptionClass, Closure $action, string $message, ?string $field = null) use ($verify): Throwable {
    try {
        $action();
    } catch (Throwable $exception) {
        $verify($exception instanceof $exceptionClass, $message);
        if ($field !== null) {
            $verify($exception instanceof ValidationException && array_key_exists($field, $exception->errors()), $message.' reports '.$field);
        }

        return $exception;
    }

    throw new RuntimeException($message.' (no exception)');
};
$writeWorkbook = static function (string $template, string $path, array $columns, array $rows, ?string $version = null, ?array $formula = null): void {
    $workbook = (new Xlsx)->load($template);
    try {
        $sheet = $workbook->getSheetByName('Data');
        foreach ($rows as $rowIndex => $row) {
            foreach ($columns as $columnIndex => $column) {
                $sheet->getCell([$columnIndex + 1, $rowIndex + 2])->setValueExplicit((string) ($row[$column['key']] ?? ''), DataType::TYPE_STRING);
            }
        }
        if ($formula !== null) {
            $index = array_search($formula['field'], array_column($columns, 'key'), true);
            $sheet->getCell([$index + 1, $formula['row'] ?? 2])->setValue($formula['value']);
        }
        if ($version !== null) {
            $workbook->getSheetByName('_erp_template')->getCell('B2')->setValueExplicit($version, DataType::TYPE_STRING);
        }
        (new XlsxWriter($workbook))->save($path);
    } finally {
        $workbook->disconnectWorksheets();
    }
};
$readWorkbook = static function (string $path): array {
    $workbook = (new Xlsx)->load($path);
    try {
        $sheet = $workbook->getSheetByName('Data');

        return [
            'rows' => $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().$sheet->getHighestRow(), null, true, false),
            'data_type' => $sheet->getCell('B2')->getDataType(),
        ];
    } finally {
        $workbook->disconnectWorksheets();
    }
};
$httpGet = static function (User $user, string $path) {
    $guard = Auth::guard('web');
    $guard->forgetUser();
    $guard->setUser($user);
    $request = Request::create($path, 'GET');
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    try {
        return $response;
    } finally {
        $kernel->terminate($request, $response);
    }
};

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Supplier factory check requires the exact separate PostgreSQL test database.');
}

$diskParent = storage_path('framework/testing/disks');
$diskRoot = $diskParent.DIRECTORY_SEPARATOR.'supplier-factory-check-'.strtoupper(bin2hex(random_bytes(5)));
if (! str_starts_with($diskRoot, $diskParent.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing to use a supplier test path outside the isolated testing folder.');
}
config(['filesystems.disks.local.root' => $diskRoot]);
Storage::forgetDisk('local');
File::ensureDirectoryExists($diskRoot);
$privateArtifacts = [];
DB::beginTransaction();

try {
    $category = SupplierCategory::factory()->create();
    $supplier = Supplier::factory()->create(['category_id' => $category->id]);
    $contact = SupplierContact::factory()->create(['supplier_id' => $supplier->id]);
    $address = SupplierAddress::factory()->create(['supplier_id' => $supplier->id]);
    $note = SupplierNote::factory()->create(['supplier_id' => $supplier->id]);
    $document = SupplierDocument::factory()->create(['supplier_id' => $supplier->id]);
    $pdf = Storage::disk('local')->get($document->path);
    preg_match('/startxref\n([0-9]+)\n%%EOF\n$/', $pdf, $xrefMatch);

    $verify($category->exists && $category->active, 'Supplier category factory persists an active classification');
    $verify($supplier->exists && $supplier->entity_type === 'organization' && in_array($supplier->supplier_type, ['manufacturer', 'distributor', 'service_provider', 'other'], true) && $supplier->status === 'active' && $supplier->version === 1 && (int) $supplier->category_id === (int) $category->id, 'Supplier factory persists a valid categorized profile');
    $verify($contact->exists && (int) $contact->supplier_id === (int) $supplier->id && $contact->active && ! $contact->is_primary, 'Supplier contact factory persists an owner-local relationship');
    $verify($address->exists && (int) $address->supplier_id === (int) $supplier->id && in_array($address->purpose, ['registered', 'office', 'pickup', 'other'], true), 'Supplier address factory uses supplier-specific address purposes');
    $verify($note->exists && (int) $note->supplier_id === (int) $supplier->id && $note->author_id === null, 'Supplier note factory uses synthetic content without fabricated authorship');
    $verify($document->exists && (int) $document->supplier_id === (int) $supplier->id && str_starts_with($document->path, 'suppliers/'.$supplier->id.'/') && Storage::disk('local')->exists($document->path) && $document->mime === 'application/pdf' && $document->size === strlen($pdf) && str_starts_with($pdf, "%PDF-1.4\n") && str_contains($pdf, '(Synthetic supplier demo reference) Tj') && isset($xrefMatch[1]) && substr($pdf, (int) $xrefMatch[1], 4) === 'xref', 'Supplier document factory writes an exact-size private synthetic PDF with a valid cross-reference');

    $permissionNames = ['supplier_view', 'supplier_create', 'supplier_update', 'supplier_status', 'supplier_contact_manage', 'supplier_address_manage', 'supplier_document_view', 'supplier_document_manage', 'supplier_notes_view', 'supplier_notes_update', 'supplier_history_view', 'supplier_bulk_update', 'supplier_import', 'supplier_export', 'supplier_template', 'supplier_category_manage'];
    $permissions = array_map(static fn (string $name): string => 'M03.'.$name, $permissionNames);
    $catalogCodes = DB::table('permission_catalog')->whereIn('code', $permissions)->pluck('code')->all();
    $verify(count($catalogCodes) === count($permissions), 'All sixteen Supplier permissions are registered as explicit catalog entries');
    $administratorRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    $administratorSupplierPermissions = DB::table('role_permissions')->where('role_id', $administratorRoleId)->whereIn('permission_code', $permissions)->pluck('permission_code')->all();
    $nonAdministratorSupplierPermissions = DB::table('role_permissions')->where('role_id', '<>', $administratorRoleId)->whereIn('permission_code', $permissions)->pluck('permission_code')->all();
    $verify(count($administratorSupplierPermissions) === count($permissions) && $nonAdministratorSupplierPermissions === [], 'Administrator receives all registered Supplier permissions while other roles receive no automatic grants');

    $noAccess = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $managerReadOnlyRoleId = (int) DB::table('roles')->where('code', 'ManagerReadOnly')->value('id');
    DB::table('user_roles')->insert(['user_id' => $noAccess->id, 'role_id' => $managerReadOnlyRoleId, 'created_at' => now(), 'updated_at' => now()]);
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $noAccess->id, 'permission_code' => 'M03.customer_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach (['M03.customer_view', 'M03.customer_create', 'M03.customer_update'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $verify(! app(SupplierAccess::class)->allows($noAccess->id, 'M03.supplier_view'), 'Customer permission does not grant Supplier visibility');
    $expect(HttpException::class, fn () => app(SupplierActions::class)->save($noAccess->id, ['code' => 'DENIED-SUP-'.$supplier->id, 'name' => 'Denied Supplier', 'entity_type' => 'organization', 'supplier_type' => 'other', 'status' => 'active']), 'Supplier creation is denied without explicit Supplier permissions');

    $actions = app(SupplierActions::class);
    $input = ['code' => 'SUP-'.$supplier->id.'-CHECK', 'name' => 'Synthetic Supplier '.$supplier->id, 'entity_type' => 'organization', 'supplier_type' => 'distributor', 'status' => 'active', 'city' => 'Cairo', 'email' => 'supplier.'.$supplier->id.'@example.test'];
    $created = $actions->save($actor->id, $input);
    $verify($created->exists && $created->version === 1 && $created->code === strtoupper($input['code']), 'Authorized supplier create persists a canonical versioned profile');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, $input), 'Duplicate supplier codes are rejected', 'code');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$input, 'code' => 'BAD-'.$supplier->id, 'supplier_type' => 'buyer']), 'Unsupported supplier types are rejected', 'supplier_type');
    $updated = $actions->save($actor->id, [...$input, 'name' => 'Updated Synthetic Supplier '.$supplier->id], $created->id, $created->version);
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$input, 'name' => 'Stale edit'], $updated->id, 1), 'Stale supplier updates are rejected', 'code');
    $verify($updated->fresh()->name === 'Updated Synthetic Supplier '.$supplier->id && $updated->version === 2, 'Supplier update persists once and increments its version');
    $status = $actions->changeStatus($actor->id, $updated->id, $updated->version, 'inactive');
    $verify($status->fresh()->status === 'inactive' && $status->version === 3, 'Supplier status action applies an authorized status change');

    $primary = $actions->saveContact($actor->id, $updated->id, ['name' => 'Primary supplier contact', 'email' => 'primary.'.$supplier->id.'@example.test', 'active' => true, 'is_primary' => true]);
    $replacement = $actions->saveContact($actor->id, $updated->id, ['name' => 'Replacement supplier contact', 'active' => true, 'is_primary' => true]);
    $verify(! $primary->fresh()->is_primary && $replacement->fresh()->is_primary && $updated->contacts()->where('is_primary', true)->count() === 1, 'Supplier contacts retain one primary contact');
    $registered = $actions->saveAddress($actor->id, $updated->id, ['label' => 'Registered', 'purpose' => 'registered', 'line' => '10 Synthetic Test Road', 'country' => 'Egypt', 'city' => 'Cairo', 'is_primary' => true]);
    $registeredReplacement = $actions->saveAddress($actor->id, $updated->id, ['label' => 'Main registered office', 'purpose' => 'registered', 'line' => '12 Synthetic Test Road', 'country' => 'Egypt', 'city' => 'Cairo', 'is_primary' => true]);
    $pickup = $actions->saveAddress($actor->id, $updated->id, ['label' => 'Pickup', 'purpose' => 'pickup', 'line' => '20 Synthetic Test Road', 'country' => 'Egypt', 'city' => 'Cairo', 'is_primary' => true]);
    $verify(! $registered->fresh()->is_primary && $registeredReplacement->fresh()->is_primary && $pickup->fresh()->is_primary, 'Supplier address primary uniqueness is enforced per purpose');
    $expect(ModelNotFoundException::class, fn () => $actions->saveContact($actor->id, $updated->id, ['name' => 'Cross-supplier edit', 'active' => true, 'is_primary' => false], $contact->id), 'A contact owned by another supplier cannot be edited');
    $expect(ModelNotFoundException::class, fn () => $actions->saveAddress($actor->id, $updated->id, ['label' => 'Cross-supplier edit', 'purpose' => 'other', 'line' => 'Test', 'is_primary' => false], $address->id), 'An address owned by another supplier cannot be edited');

    $noteAction = $actions->addNote($actor->id, $updated->id, 'Synthetic internal coordination note.');
    $verify($noteAction->author_id === $actor->id && $updated->notes()->whereKey($noteAction->id)->exists(), 'Supplier note records the actual actor and stays supplier-local');
    $uploaded = $actions->upload($actor->id, $updated->id, UploadedFile::fake()->createWithContent('private supplier reference.pdf', "%PDF-1.4\nSynthetic test fixture\n%%EOF\n"));
    $verify($uploaded->exists && $uploaded->mime === 'application/pdf' && $uploaded->size > 0 && Storage::disk('local')->exists($uploaded->path) && str_starts_with($uploaded->path, 'suppliers/'.$updated->id.'/'), 'Supplier upload stores accepted files on the private disk');
    $expect(ValidationException::class, fn () => $actions->upload($actor->id, $updated->id, UploadedFile::fake()->create('unsafe.exe', 2)), 'Supplier upload rejects unsupported files', 'file');
    $verify(app(SupplierCatalogQuery::class)->query($actor->id, ['search' => 'Updated Synthetic Supplier '.$supplier->id])->whereKey($updated->id)->exists(), 'Supplier catalog searches its own normalized supplier text');
    $supplierApi = app(SuppliersApi::class);
    $identity = $supplierApi->getSupplier(new GetSupplierRequest($actor->id, $updated->id, false));
    $verify($identity->id === $updated->id && $identity->code === $updated->code && $identity->status === 'inactive', 'Supplier lookup contract returns only the approved supplier identity fields');
    $searchResults = $supplierApi->searchSuppliers(new SearchSuppliersRequest($actor->id, $supplier->code, 20));
    $verify(collect($searchResults)->contains(fn ($row) => $row->id === $supplier->id), 'Supplier search contract returns matching active supplier identities');
    $expect(SupplierContractException::class, fn () => $supplierApi->getSupplier(new GetSupplierRequest($noAccess->id, $updated->id, false)), 'Supplier lookup contract denies a customer-only actor');
    $categoryManaged = app(SupplierCategories::class)->save($actor->id, ['code' => 'SUP-CAT-'.$supplier->id, 'name' => 'Synthetic supplier category', 'active' => true]);
    $verify($categoryManaged->exists && $categoryManaged->active, 'Supplier category mutation uses its independent category permission');

    $definition = app(SupplierDataDefinition::class);
    $operationService = app(OperationService::class);
    $codec = app(WorkbookCodec::class);
    $columns = $definition->columns();
    $selected = Supplier::factory()->create(['code' => 'BULK-SELECTED-'.$supplier->id, 'status' => 'active']);
    $unselected = Supplier::factory()->create(['code' => 'BULK-UNSELECTED-'.$supplier->id, 'status' => 'active']);
    $bulkRun = $operationService->previewBulk($actor->id, 'suppliers', [$selected->id], ['status' => ['mode' => 'set', 'value' => 'inactive']]);
    $verify($bulkRun->entity === 'suppliers' && $bulkRun->kind === 'bulk' && $bulkRun->status === 'preview' && $bulkRun->summary['valid'] === 1, 'Selected supplier bulk preview stores one valid proposed change');
    $verify($selected->fresh()->status === 'active' && $unselected->fresh()->status === 'active', 'Supplier bulk preview does not mutate selected or unselected records');
    $operationService->confirm($actor->id, $bulkRun->id, dispatch: false);
    $operationService->process($bulkRun->id);
    $verify($bulkRun->fresh()->status === 'completed' && $selected->fresh()->status === 'inactive' && $unselected->fresh()->status === 'active', 'Supplier bulk execution changes only the selected supplier');

    $template = $operationService->template($actor->id, 'suppliers');
    $privateArtifacts[] = $template;
    $emptyRows = $codec->read($template, 'suppliers', $definition->version(), $columns);
    $templateBook = (new Xlsx)->load($template);
    try {
        $verify($definition->id() === 'suppliers' && $definition->version() === 'M03-suppliers-v1' && $emptyRows === [] && $templateBook->getSheetNames() === ['Data', 'Instructions', 'Lookups', '_erp_template'] && $templateBook->getSheetByName('_erp_template')->getCell('B2')->getValue() === 'M03-suppliers-v1', 'Supplier-specific XLSX template has all sheets and the current entity/version metadata');
        $verify(in_array('category_code', array_column($columns, 'key'), true) && ! in_array('category_id', array_column($columns, 'key'), true), 'Supplier workbook imports category_code instead of exposing internal category IDs');
    } finally {
        $templateBook->disconnectWorksheets();
    }
    $workbookPath = static function (array $rows, ?string $version = null, ?array $formula = null) use ($template, $columns, $diskRoot, &$privateArtifacts, $writeWorkbook, $supplier): string {
        $path = $diskRoot.DIRECTORY_SEPARATOR.'suppliers-'.$supplier->id.'-'.bin2hex(random_bytes(4)).'.xlsx';
        $writeWorkbook($template, $path, $columns, $rows, $version, $formula);
        $privateArtifacts[] = $path;

        return $path;
    };
    $uploadWorkbook = static fn (string $path) => new UploadedFile($path, 'suppliers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true);
    $importRow = static fn (string $code, string $name): array => ['code' => $code, 'name' => $name, 'entity_type' => 'organization', 'supplier_type' => 'manufacturer', 'category_code' => $categoryManaged->code, 'status' => 'active'];
    $staleTemplate = $workbookPath([$importRow('OLD-TEMPLATE-'.$supplier->id, 'Old template')], 'M03-suppliers-old');
    $staleError = $expect(ValidationException::class, fn () => $operationService->previewImport($actor->id, 'suppliers', $uploadWorkbook($staleTemplate), 'create'), 'Supplier import rejects an outdated template version', 'file');
    $verify(str_contains(implode(' ', $staleError->errors()['file'] ?? []), 'template version'), 'Supplier template-version error is clear');
    $formulaFile = $workbookPath([$importRow('FORMULA-'.$supplier->id, 'Candidate')], null, ['field' => 'name', 'value' => '=1+1']);
    $formulaError = $expect(ValidationException::class, fn () => $operationService->previewImport($actor->id, 'suppliers', $uploadWorkbook($formulaFile), 'create'), 'Supplier import rejects formula cells before preview', 'file');
    $verify(str_contains(implode(' ', $formulaError->errors()['file'] ?? []), 'Formula cells'), 'Supplier formula rejection identifies unsafe workbook content');

    $importCode = 'IMPORT-SUP-'.$supplier->id;
    $importFile = $workbookPath([$importRow($importCode, 'Imported Supplier '.$supplier->id)]);
    $importRun = $operationService->previewImport($actor->id, 'suppliers', $uploadWorkbook($importFile), 'create');
    $verify($importRun->status === 'preview' && $importRun->template_version === $definition->version() && $importRun->summary['valid'] === 1 && ! Supplier::where('code', $importCode)->exists(), 'Supplier XLSX import creates a versioned preview without persisting the supplier');
    $operationService->confirm($actor->id, $importRun->id, dispatch: false);
    $operationService->process($importRun->id);
    $imported = Supplier::where('code', $importCode)->firstOrFail();
    $verify($importRun->fresh()->status === 'completed' && $imported->name === 'Imported Supplier '.$supplier->id && (int) $imported->category_id === (int) $categoryManaged->id, 'Confirmed supplier import resolves category_code and persists through SupplierActions');

    $formulaSupplier = Supplier::factory()->create(['code' => 'FORMULA-EXPORT-'.$supplier->id, 'name' => '=1+1']);
    $exportPath = $operationService->export($actor->id, 'suppliers', 'selected', [], [$formulaSupplier->id, $imported->id]);
    $privateArtifacts[] = $exportPath;
    $exportData = $readWorkbook($exportPath);
    $verify(count($exportData['rows']) === 2 && $exportData['data_type'] === DataType::TYPE_STRING && in_array('=1+1', array_column($exportData['rows'], 1), true), 'Selected supplier export opens as XLSX and formula-like supplier text remains literal');

    $guard = Auth::guard();
    $previousActor = $guard->user();
    $guard->setUser($actor);
    try {
        Livewire::test(SupplierCatalogPage::class)->set('search', $updated->code)->assertSee($updated->code);
        Livewire::test(SupplierFormPage::class)->assertSee('form.supplier_type');
        Livewire::test(SupplierFormPage::class)
            ->set('form.code', 'UI-SUP-'.$supplier->id)
            ->set('form.name', 'UI Created Synthetic Supplier '.$supplier->id)
            ->set('form.entity_type', 'organization')
            ->set('form.supplier_type', 'distributor')
            ->set('form.category_id', (string) $categoryManaged->id)
            ->set('form.status', 'active')
            ->set('form.country', 'Egypt')
            ->set('form.city', 'Cairo')
            ->set('contact.name', 'UI Primary Supplier Contact')
            ->set('contact.active', true)
            ->set('contact.is_primary', true)
            ->set('billing.label', 'UI Registered Address')
            ->set('billing.line', '55 Synthetic Supplier Road')
            ->set('billing.country', 'Egypt')
            ->set('billing.city', 'Cairo')
            ->set('shipping.label', 'UI Office Address')
            ->set('shipping.line', '65 Synthetic Supplier Road')
            ->set('shipping.country', 'Egypt')
            ->set('shipping.city', 'Cairo')
            ->set('note', 'UI synthetic supplier note.')
            ->call('save');
        $uiSupplier = Supplier::where('code', 'UI-SUP-'.$supplier->id)->firstOrFail();
        $verify($uiSupplier->name === 'UI Created Synthetic Supplier '.$supplier->id && $uiSupplier->contacts()->where('is_primary', true)->count() === 1 && $uiSupplier->addresses()->where('is_primary', true)->count() === 2 && $uiSupplier->addresses()->where('purpose', 'registered')->exists() && $uiSupplier->addresses()->where('purpose', 'office')->exists() && $uiSupplier->notes()->count() === 1, 'Supplier create form saves its profile, primary contact, registered/office addresses, and note');
        Livewire::test(SupplierFormPage::class)->set('form.code', 'UI-MISSING-TYPE-'.$supplier->id)->set('form.name', 'Missing supplier type')->set('form.entity_type', 'organization')->set('form.status', 'active')->call('save')->assertHasErrors('supplier_type');
        Livewire::test(SupplierFormPage::class, ['supplier' => $uiSupplier->id])->set('form.name', 'UI Edited Synthetic Supplier '.$supplier->id)->call('save');
        $verify($uiSupplier->fresh()->name === 'UI Edited Synthetic Supplier '.$supplier->id && $uiSupplier->fresh()->version === 2, 'Supplier edit form saves through the versioned Supplier action');
        $details = Livewire::test(SupplierDetailsPage::class, ['supplier' => $uiSupplier->id])->assertSee($uiSupplier->code);
        foreach (['overview', 'contacts', 'financial', 'documents'] as $tab) {
            $details->set('tab', $tab)->assertSet('tab', $tab);
        }
        $editContact = $uiSupplier->contacts()->where('is_primary', true)->firstOrFail();
        Livewire::test(SupplierDetailsPage::class, ['supplier' => $uiSupplier->id])->call('editContact', $editContact->id)->set('contact.name', 'Edited in supplier details')->call('saveContact');
        $verify($editContact->fresh()->name === 'Edited in supplier details', 'Supplier detail contact editor persists through SupplierActions');
        $editAddress = $uiSupplier->addresses()->where('purpose', 'registered')->firstOrFail();
        Livewire::test(SupplierDetailsPage::class, ['supplier' => $uiSupplier->id])->call('editAddress', $editAddress->id)->set('address.line', 'Updated detail address')->call('saveAddress');
        $verify($editAddress->fresh()->line === 'Updated detail address', 'Supplier detail address editor persists through SupplierActions');

        Livewire::test(SupplierCategoriesPage::class)->assertSee($categoryManaged->code);
        Livewire::test(SupplierCategoriesPage::class)->call('edit')->set('form.code', 'UI-SUP-CAT-'.$supplier->id)->set('form.name', 'UI Supplier Category')->set('form.active', true)->call('save');
        $verify(SupplierCategory::where('code', 'UI-SUP-CAT-'.$supplier->id)->where('active', true)->exists(), 'Supplier category page renders and creates a category with explicit category authority');

        $customer = Customer::factory()->create(['code' => 'REG-CUSTOMER-'.$supplier->id]);
        Livewire::test(CatalogPage::class)->set('search', $customer->code)->assertSee($customer->code);
        Livewire::test(CustomerFormPage::class)->assertDontSee('form.supplier_type');
        Livewire::test(CustomerFormPage::class)
            ->set('form.code', 'UI-CUSTOMER-SAVED-'.$supplier->id)
            ->set('form.name', 'UI Saved Customer '.$supplier->id)
            ->set('form.entity_type', 'organization')
            ->set('form.status', 'active')
            ->set('form.segment', 'synthetic-check')
            ->call('save');
        $verify(Customer::where('code', 'UI-CUSTOMER-SAVED-'.$supplier->id)->where('name', 'UI Saved Customer '.$supplier->id)->exists(), 'Customer form still saves through the existing Customer action');
        Livewire::test(CustomerDetailsPage::class, ['customer' => $customer->id])->assertSee($customer->code);
        $verify(true, 'Supplier list/create/details render, and shared Customers list/form/details still render without supplier-only fields');
    } finally {
        if ($previousActor !== null) {
            $guard->setUser($previousActor);
        } else {
            $guard->forgetUser();
        }
    }

    config(['session.driver' => 'array']);
    $httpDetails = $httpGet($actor, '/suppliers/'.$updated->id);
    $verify($httpDetails->getStatusCode() === 200, 'Real HTTP request resolves the Supplier details route for an authorized actor');
    $httpIndex = $httpGet($actor, '/suppliers');
    $httpCreate = $httpGet($actor, '/suppliers/create');
    $httpImport = $httpGet($actor, '/suppliers/import');
    $verify($httpIndex->getStatusCode() === 200 && $httpCreate->getStatusCode() === 200 && $httpImport->getStatusCode() === 200, 'Real HTTP requests resolve the Supplier index, create, and import routes');
    $httpReceipt = $httpGet($actor, '/data-operations/'.$importRun->id);
    $verify($httpReceipt->getStatusCode() === 200, 'Real HTTP request binds the actor-owned Supplier import operation receipt');
    $httpDocument = $httpGet($actor, '/suppliers/'.$supplier->id.'/documents/'.$document->id);
    $verify($httpDocument->getStatusCode() === 200 && str_contains((string) $httpDocument->headers->get('Content-Type'), 'application/pdf') && str_contains((string) $httpDocument->headers->get('Cache-Control'), 'private'), 'Real HTTP document route binds a supplier-owned private document');
    $httpWrongOwner = $httpGet($actor, '/suppliers/'.$updated->id.'/documents/'.$document->id);
    $verify($httpWrongOwner->getStatusCode() === 404, 'Real HTTP document route rejects a document owned by another supplier');

    $securityTables = ['access_grants', 'access_audits', 'user_roles', 'user_branch_scopes', 'audit_entries'];
    $securityCounts = [];
    foreach ($securityTables as $table) {
        $securityCounts[$table] = DB::table($table)->count();
    }
    $supplierCodes = ['DEMO-SUP-CAIRO-001', 'DEMO-SUP-ALEX-001', 'DEMO-SUP-GIZA-001', 'DEMO-SUP-CAIRO-002', 'DEMO-SUP-MANS-001', 'DEMO-SUP-INDIVIDUAL-001'];
    app(SupplierDemoSeeder::class)->run();
    $demo = Supplier::whereIn('code', $supplierCodes)->orderBy('code')->get();
    $verify($demo->count() === count($supplierCodes), 'Explicit supplier demo seeder creates its identifiable synthetic supplier set');
    $verify($demo->every(fn (Supplier $row) => $row->contacts()->count() === 1 && $row->addresses()->where('is_primary', true)->count() === 2 && $row->notes()->count() === 1 && $row->documents()->count() === 1), 'Demo suppliers have coherent contact, purpose-specific addresses, note and private-document relationships');
    $demo->first()->update(['name' => 'Owner edit preserved by rerun']);
    app(SupplierDemoSeeder::class)->run();
    $rerun = Supplier::whereIn('code', $supplierCodes)->orderBy('code')->get();
    $verify($rerun->count() === count($supplierCodes) && $rerun->first()->name === 'Owner edit preserved by rerun' && $rerun->sum(fn (Supplier $row) => $row->contacts()->count() + $row->addresses()->count() + $row->notes()->count() + $row->documents()->count()) === 30, 'Supplier demo seeder reruns without duplicates or overwriting existing supplier data');
    foreach ($securityCounts as $table => $count) {
        $verify(DB::table($table)->count() === $count, "Supplier demo seeding adds no {$table} history or grants");
    }

    DB::rollBack();
    echo "Supplier factory and demo persistence checks passed.\n";
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    throw $exception;
} finally {
    Storage::disk('local')->delete(Storage::disk('local')->allFiles());
    File::deleteDirectory($diskRoot);
    Storage::forgetDisk('local');
}

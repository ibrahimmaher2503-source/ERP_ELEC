<?php

use App\Models\User;
use App\Modules\Parties\Application\CustomerAccess;
use App\Modules\Parties\Application\CustomerActions;
use App\Modules\Parties\Application\CustomerCatalogQuery;
use App\Modules\Parties\Application\CustomerDataDefinition;
use App\Modules\Parties\Application\Contracts\{CustomerContractException,CustomersApi,GetCustomerRequest};
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use App\Modules\Parties\Domain\CustomerContact;
use App\Modules\Parties\Domain\CustomerDocument;
use App\Modules\Parties\Domain\CustomerNote;
use App\Modules\Parties\Presentation\CatalogPage;
use App\Modules\Parties\Presentation\CustomerDetailsPage;
use App\Modules\Parties\Presentation\CustomerFormPage;
use App\Modules\System\Application\DataOperations\OperationService;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use App\Modules\System\Presentation\DataOperationPage;
use Database\Seeders\CustomerDemoSeeder;
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

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M03 customer checks require the exact separate PostgreSQL test database.');
}

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
$writeCustomerWorkbook = static function (string $template, string $path, array $columns, array $rows, ?string $version = null, ?array $formula = null): void {
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
$readCustomerWorkbook = static function (string $path): array {
    $workbook = (new Xlsx)->load($path);
    try {
        $sheet = $workbook->getSheetByName('Data');

        return [
            'headings' => $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, true, false)[0],
            'rows' => $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().$sheet->getHighestRow(), null, true, false),
            'data_type' => $sheet->getCell('B2')->getDataType(),
        ];
    } finally {
        $workbook->disconnectWorksheets();
    }
};
$suffix = strtoupper(bin2hex(random_bytes(5)));
$diskParent = storage_path('framework/testing/disks');
$diskRoot = $diskParent.DIRECTORY_SEPARATOR.'m03-customer-check-'.$suffix;
if (! str_starts_with($diskRoot, $diskParent.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing to use a customer test path outside the isolated testing folder.');
}
config(['filesystems.disks.local.root' => $diskRoot]);
Storage::forgetDisk('local');
File::ensureDirectoryExists($diskRoot);
$privateArtifacts = [];
DB::beginTransaction();

try {
    $codes = DB::table('permission_catalog')->where('code', 'like', 'M03.%')->orderBy('code')->pluck('code')->all();
    $administratorRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    $administratorCodes = DB::table('role_permissions')->where('role_id', $administratorRoleId)->whereIn('permission_code', $codes)->orderBy('permission_code')->pluck('permission_code')->all();
    $nonAdministratorM03 = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('r.code', '<>', 'Administrator')->where('rp.permission_code', 'like', 'M03.%')->exists();
    $verify(count($codes) >= 13 && $administratorRoleId > 0 && $administratorCodes === $codes && ! $nonAdministratorM03,
        'M03 permissions default-deny for non-administrator roles while Administrator receives all registered keys');

    $noAccess = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_roles')->insert([
        'user_id' => $noAccess->id,
        'role_id' => DB::table('roles')->where('code', 'ManagerReadOnly')->value('id'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $permissions = array_map(fn (string $name) => 'M03.'.$name, ['customer_view', 'customer_create', 'customer_update', 'customer_status', 'contact_manage', 'address_manage', 'document_view', 'document_manage', 'notes_view', 'notes_update', 'history_view', 'customer_bulk_update', 'customer_import', 'customer_export', 'customer_template']);
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $verify(! app(CustomerAccess::class)->allows($noAccess->id, 'M03.customer_view'), 'Customer access denies an authenticated actor without an explicit grant');
    $expect(HttpException::class, fn () => app(CustomerActions::class)->save($noAccess->id, ['code' => 'DENIED-'.$suffix, 'name' => 'Denied', 'entity_type' => 'organization', 'status' => 'active']), 'Customer creation is denied without customer permissions');

    $factoryCustomer = Customer::factory()->create(['code' => 'FACTORY-'.$suffix]);
    $factoryContact = CustomerContact::factory()->create(['customer_id' => $factoryCustomer->id]);
    $factoryAddress = CustomerAddress::factory()->create(['customer_id' => $factoryCustomer->id]);
    $factoryNote = CustomerNote::factory()->create(['customer_id' => $factoryCustomer->id]);
    $factoryDocument = CustomerDocument::factory()->create(['customer_id' => $factoryCustomer->id]);
    $pdfFixture = Storage::disk('local')->get($factoryDocument->path);
    preg_match('/startxref\n([0-9]+)\n%%EOF\n$/', $pdfFixture, $xrefMatch);
    $verify($factoryCustomer->exists && $factoryCustomer->entity_type === 'organization' && $factoryCustomer->version === 1, 'Customer factory persists a valid versioned profile');
    $verify((int) $factoryContact->customer_id === (int) $factoryCustomer->id && $factoryContact->active && ! $factoryContact->is_primary, 'Contact factory creates a valid customer relationship');
    $verify((int) $factoryAddress->customer_id === (int) $factoryCustomer->id && in_array($factoryAddress->purpose, ['billing', 'shipping', 'other'], true), 'Address factory creates a valid customer relationship');
    $verify((int) $factoryNote->customer_id === (int) $factoryCustomer->id && $factoryNote->author_id === null, 'Note factory creates synthetic un-attributed content without fake authorship');
    $verify((int) $factoryDocument->customer_id === (int) $factoryCustomer->id && str_starts_with($factoryDocument->path, 'customers/'.$factoryCustomer->id.'/') && Storage::disk('local')->exists($factoryDocument->path) && $factoryDocument->mime === 'application/pdf' && $factoryDocument->size === strlen($pdfFixture)
        && str_starts_with($pdfFixture, "%PDF-1.4\n") && str_contains($pdfFixture, '(Synthetic demo reference) Tj') && str_contains($pdfFixture, '/Count 1')
        && isset($xrefMatch[1]) && substr($pdfFixture, (int) $xrefMatch[1], 4) === 'xref', 'Document factory writes an exact-size private one-page synthetic PDF with a valid cross-reference');

    $actions = app(CustomerActions::class);
    $input = ['code' => 'CUS-'.$suffix, 'name' => 'Customer '.$suffix, 'entity_type' => 'organization', 'status' => 'active', 'city' => 'Cairo', 'email' => 'buyer.'.$suffix.'@example.test'];
    $customer = $actions->save($actor->id, $input);
    $verify($customer->exists && $customer->version === 1 && $customer->code === strtoupper($input['code']), 'Customer action persists a canonical profile and starts its version');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$input, 'code' => $customer->code]), 'Duplicate customer codes are rejected', 'code');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$input, 'code' => 'BAD-'.$suffix, 'entity_type' => 'company']), 'Unsupported customer entity types are rejected', 'entity_type');
    $updatedInput = [...$input, 'name' => 'Updated Customer '.$suffix];
    $customer = $actions->save($actor->id, $updatedInput, $customer->id, $customer->version);
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$input, 'name' => 'Stale'], $customer->id, 1), 'Stale customer edits are rejected', 'code');
    $verify($customer->fresh()->name === 'Updated Customer '.$suffix && $customer->version === 2, 'Customer update persists once with an incremented version');

    $customersApi = app(CustomersApi::class);
    $activeIdentity = $customersApi->getCustomer(new GetCustomerRequest($actor->id, $customer->id, true));
    $verify($activeIdentity->id === $customer->id && $activeIdentity->status === 'active', 'Customers API returns an authorized active source when active is required');
    $customer = $actions->changeStatus($actor->id, $customer->id, $customer->version, 'inactive');
    $inactiveIdentity = $customersApi->getCustomer(new GetCustomerRequest($actor->id, $customer->id, false));
    $verify($inactiveIdentity->status === 'inactive', 'Customers API returns an authorized inactive source when active is not required');
    $inactiveDenied = $expect(CustomerContractException::class, fn () => $customersApi->getCustomer(new GetCustomerRequest($actor->id, $customer->id, true)), 'Customers API denies an inactive source when active is required');
    $verify($inactiveDenied->getCode() === 422, 'Inactive customer source denial reports the documented 422 contract code');
    $verify(! collect($customersApi->searchCustomers(new \App\Modules\Parties\Application\Contracts\SearchCustomersRequest($actor->id, $customer->code)))->contains(fn ($row) => $row->id === $customer->id), 'Customers API active search excludes an inactive source');
    $customer = $actions->changeStatus($actor->id, $customer->id, $customer->version, 'active');

    $contact = $actions->saveContact($actor->id, $customer->id, ['name' => 'Primary Contact', 'email' => 'primary.'.$suffix.'@example.test', 'active' => true, 'is_primary' => true]);
    $secondContact = $actions->saveContact($actor->id, $customer->id, ['name' => 'Replacement Contact', 'active' => true, 'is_primary' => true]);
    $verify(! $contact->fresh()->is_primary && $secondContact->fresh()->is_primary && $customer->contacts()->where('is_primary', true)->count() === 1, 'Contact actions keep one primary contact for the customer');

    $billing = $actions->saveAddress($actor->id, $customer->id, ['label' => 'Billing', 'purpose' => 'billing', 'line' => '10 Test Road', 'country' => 'Egypt', 'city' => 'Cairo', 'is_primary' => true]);
    $replacementBilling = $actions->saveAddress($actor->id, $customer->id, ['label' => 'Main billing', 'purpose' => 'billing', 'line' => '12 Test Road', 'country' => 'Egypt', 'city' => 'Cairo', 'is_primary' => true]);
    $shipping = $actions->saveAddress($actor->id, $customer->id, ['label' => 'Shipping', 'purpose' => 'shipping', 'line' => '20 Test Road', 'country' => 'Egypt', 'city' => 'Cairo', 'is_primary' => true]);
    $verify(! $billing->fresh()->is_primary && $replacementBilling->fresh()->is_primary && $shipping->fresh()->is_primary && $customer->addresses()->where('purpose', 'billing')->where('is_primary', true)->count() === 1, 'Address actions keep one primary address per purpose');

    $otherCustomer = Customer::factory()->create(['code' => 'OTHER-'.$suffix]);
    $expect(ModelNotFoundException::class, fn () => $actions->saveContact($actor->id, $customer->id, ['name' => 'Cross-customer mutation', 'active' => true, 'is_primary' => false], $factoryContact->id), 'A contact from another customer cannot be edited');
    $expect(ModelNotFoundException::class, fn () => $actions->saveAddress($actor->id, $customer->id, ['label' => 'Cross-customer mutation', 'purpose' => 'other', 'line' => 'Test', 'is_primary' => false], $factoryAddress->id), 'An address from another customer cannot be edited');
    $verify($otherCustomer->exists, 'Customer child isolation leaves the other customer intact');

    $note = $actions->addNote($actor->id, $customer->id, 'Internal coordination note.');
    $secondNote = $actions->addNote($actor->id, $customer->id, 'Follow-up note.');
    $verify($note->author_id === $actor->id && $secondNote->author_id === $actor->id && $customer->notes()->count() === 2, 'Notes append with the actual author and preserve prior entries');

    $upload = UploadedFile::fake()->createWithContent('private customer reference.pdf', "%PDF-1.4\nSynthetic test fixture\n%%EOF\n");
    $document = $actions->upload($actor->id, $customer->id, $upload);
    $verify($document->exists && $document->mime === 'application/pdf' && $document->size > 0 && Storage::disk('local')->exists($document->path) && str_starts_with($document->path, 'customers/'.$customer->id.'/'), 'Customer document action stores an accepted file on the private disk');
    $expect(ValidationException::class, fn () => $actions->upload($actor->id, $customer->id, UploadedFile::fake()->create('unsafe.exe', 2)), 'Customer document action rejects unsupported files', 'file');

    $catalog = app(CustomerCatalogQuery::class);
    $verify($catalog->query($actor->id, ['search' => 'Updated Customer '.$suffix])->whereKey($customer->id)->exists(), 'Customer catalog query searches normalized persisted customer text');
    $beforePreview = $customer->fresh()->only(['name', 'version']);
    $preview = $actions->save($actor->id, [...$updatedInput, 'name' => 'Preview Only'], $customer->id, $customer->version, validateOnly: true);
    $verify($preview->name === 'Preview Only' && $customer->fresh()->only(['name', 'version']) === $beforePreview, 'Customer validate-only save returns a preview without database writes');

    $demoCodes = ['DEMO-CUS-CAIRO-001', 'DEMO-CUS-ALEX-001', 'DEMO-CUS-GIZA-001', 'DEMO-CUS-CAIRO-002'];
    $securityCounts = [];
    foreach (['access_grants', 'access_audits', 'user_roles', 'user_branch_scopes', 'audit_entries'] as $table) {
        $securityCounts[$table] = DB::table($table)->count();
    }
    app(CustomerDemoSeeder::class)->run();
    $demo = Customer::whereIn('code', $demoCodes)->orderBy('code')->get();
    $verify($demo->count() === 4, 'Customer demo seeder creates its identifiable synthetic customer set');
    $verify($demo->every(fn (Customer $row) => $row->contacts()->count() === 1 && $row->addresses()->where('is_primary', true)->count() === 2 && $row->notes()->count() === 1 && $row->documents()->count() === 1), 'Demo customers receive realistic contact, address, note and private-document relationships');
    $demo->first()->update(['name' => 'Owner edit preserved by rerun']);
    app(CustomerDemoSeeder::class)->run();
    $rerun = Customer::whereIn('code', $demoCodes)->with(['contacts', 'addresses', 'notes', 'documents'])->orderBy('code')->get();
    $verify($rerun->count() === 4 && $rerun->first()->name === 'Owner edit preserved by rerun' && $rerun->sum(fn (Customer $row) => $row->contacts()->count() + $row->addresses()->count() + $row->notes()->count() + $row->documents()->count()) === 20, 'Customer demo seeder reruns without duplicates or overwriting existing owner data');
    $verify(DB::table('access_grants')->count() === $securityCounts['access_grants'], 'Customer seeding adds no permission grants');
    foreach ($securityCounts as $table => $count) {
        $verify(DB::table($table)->count() === $count, "Customer seeding adds no {$table} records");
    }

    $definition = app(CustomerDataDefinition::class);
    $operationService = app(OperationService::class);
    $codec = app(WorkbookCodec::class);
    $columns = $definition->columns();
    $bulkSelected = Customer::factory()->create(['code' => 'BULK-SELECTED-'.$suffix, 'segment' => 'old-segment']);
    $bulkUnselected = Customer::factory()->create(['code' => 'BULK-UNSELECTED-'.$suffix, 'segment' => 'keep-segment']);
    $bulkRun = $operationService->previewBulk($actor->id, 'customers', [$bulkSelected->id], ['segment' => ['mode' => 'set', 'value' => 'updated-segment']]);
    $verify($bulkRun->entity === 'customers' && $bulkRun->kind === 'bulk' && $bulkRun->status === 'preview' && $bulkRun->summary['valid'] === 1, 'Selected-customer bulk preview persists a single valid operation receipt');
    $verify($bulkSelected->fresh()->segment === 'old-segment' && $bulkUnselected->fresh()->segment === 'keep-segment' && $bulkRun->items()->first()->after_values['segment'] === 'updated-segment', 'Bulk preview stores the selected change plan without mutating either customer');
    $operationService->confirm($actor->id, $bulkRun->id, dispatch: false);
    $operationService->process($bulkRun->id);
    $verify($bulkRun->fresh()->status === 'completed' && $bulkSelected->fresh()->segment === 'updated-segment' && $bulkUnselected->fresh()->segment === 'keep-segment', 'Bulk execution applies changes only to explicitly selected customers');

    $template = $operationService->template($actor->id, 'customers');
    $privateArtifacts[] = $template;
    $emptyTemplateRows = $codec->read($template, 'customers', $definition->version(), $columns);
    $templateWorkbook = (new Xlsx)->load($template);
    try {
        $verify($emptyTemplateRows === [] && $templateWorkbook->getSheetNames() === ['Data', 'Instructions', 'Lookups', '_erp_template'] && $templateWorkbook->getSheetByName('_erp_template')->getCell('B2')->getValue() === $definition->version(), 'Customer template round-trips with required sheets and current entity/version metadata');
    } finally {
        $templateWorkbook->disconnectWorksheets();
    }

    $makeWorkbook = static function (array $rows, ?string $version = null, ?array $formula = null) use ($template, $columns, $diskRoot, &$privateArtifacts, $suffix, $writeCustomerWorkbook): string {
        $path = $diskRoot.DIRECTORY_SEPARATOR.'customers-'.$suffix.'-'.bin2hex(random_bytes(3)).'.xlsx';
        $writeCustomerWorkbook($template, $path, $columns, $rows, $version, $formula);
        $privateArtifacts[] = $path;

        return $path;
    };
    $uploadWorkbook = static fn (string $path) => new UploadedFile($path, 'customers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', UPLOAD_ERR_OK, true);
    $importRow = static fn (string $code, string $name): array => ['code' => $code, 'name' => $name, 'entity_type' => 'organization', 'status' => 'active'];
    $wrongVersion = $makeWorkbook([$importRow('OLD-VERSION-'.$suffix, 'Old template')], 'M03-customers-old');
    $wrongVersionError = $expect(ValidationException::class, fn () => $operationService->previewImport($actor->id, 'customers', $uploadWorkbook($wrongVersion), 'create'), 'Customer import rejects an outdated template version', 'file');
    $verify(str_contains(implode(' ', $wrongVersionError->errors()['file'] ?? []), 'template version'), 'Outdated customer template error identifies its version mismatch');
    $formulaWorkbook = $makeWorkbook([$importRow('FORMULA-'.$suffix, 'Formula candidate')], null, ['field' => 'name', 'value' => '=1+1']);
    $formulaError = $expect(ValidationException::class, fn () => $operationService->previewImport($actor->id, 'customers', $uploadWorkbook($formulaWorkbook), 'create'), 'Customer import rejects formula cells before preview', 'file');
    $verify(str_contains(implode(' ', $formulaError->errors()['file'] ?? []), 'Formula cells'), 'Formula rejection identifies unsafe workbook content');

    $importCode = 'IMPORT-CUSTOMER-'.$suffix;
    $createWorkbook = $makeWorkbook([$importRow($importCode, 'Imported Customer '.$suffix)]);
    $importRun = $operationService->previewImport($actor->id, 'customers', $uploadWorkbook($createWorkbook), 'create');
    $verify($importRun->status === 'preview' && $importRun->template_version === $definition->version() && $importRun->summary['valid'] === 1 && ! Customer::where('code', $importCode)->exists(), 'Customer import preview persists a versioned plan without creating a customer');
    $operationService->confirm($actor->id, $importRun->id, dispatch: false);
    $operationService->process($importRun->id);
    $imported = Customer::where('code', $importCode)->firstOrFail();
    $verify($importRun->fresh()->status === 'completed' && $imported->name === 'Imported Customer '.$suffix, 'Confirmed customer import executes through normal customer actions');

    $updateWorkbook = $makeWorkbook([['code' => $importCode, 'name' => 'Updated by Import '.$suffix, 'entity_type' => '', 'status' => '', 'segment' => 'import-segment']]);
    $updateRun = $operationService->previewImport($actor->id, 'customers', $uploadWorkbook($updateWorkbook), 'update');
    $verify($updateRun->summary['valid'] === 1 && $imported->fresh()->name === 'Imported Customer '.$suffix && $updateRun->items()->first()->after_values['entity_type'] === 'organization', 'Customer update preview preserves blank cells and does not mutate the target');
    $operationService->confirm($actor->id, $updateRun->id, dispatch: false);
    $operationService->process($updateRun->id);
    $verify($imported->fresh()->name === 'Updated by Import '.$suffix && $imported->fresh()->segment === 'import-segment', 'Confirmed customer update applies explicit cells and preserves blank cells');

    $formulaCustomer = Customer::factory()->create(['code' => 'FORMULA-EXPORT-'.$suffix, 'name' => '=1+1']);
    $selectedExport = $operationService->export($actor->id, 'customers', 'selected', [], [$formulaCustomer->id, $imported->id]);
    $privateArtifacts[] = $selectedExport;
    $exportData = $readCustomerWorkbook($selectedExport);
    $verify(count($exportData['rows']) === 2 && $exportData['data_type'] === DataType::TYPE_STRING && in_array('=1+1', array_column($exportData['rows'], 1), true), 'Selected customer export opens as XLSX and formula-like customer text stays a literal string');

    $guard = Auth::guard();
    $previousActor = $guard->user();
    $guard->setUser($actor);
    try {
        Livewire::test(CatalogPage::class)->assertSee($customer->code);
        Livewire::test(CustomerFormPage::class)->assertSee('كود العميل');
        Livewire::test(CustomerFormPage::class)
            ->set('form.code', 'UI-CUSTOMER-'.$suffix)
            ->set('form.name', 'UI Created Customer '.$suffix)
            ->set('form.entity_type', 'organization')
            ->set('form.status', 'active')
            ->set('contact.name', 'UI Primary Contact')
            ->set('contact.active', true)
            ->set('contact.is_primary', true)
            ->set('billing.label', 'UI Billing Address')
            ->set('billing.line', '55 Synthetic UI Road')
            ->set('billing.country', 'Egypt')
            ->set('billing.city', 'Cairo')
            ->set('note', 'Synthetic UI form note.')
            ->call('save');
        $uiCustomer = Customer::where('code', 'UI-CUSTOMER-'.$suffix)->firstOrFail();
        $verify($uiCustomer->name === 'UI Created Customer '.$suffix && $uiCustomer->contacts()->where('is_primary', true)->count() === 1 && $uiCustomer->addresses()->where('is_primary', true)->count() === 2 && $uiCustomer->notes()->count() === 1, 'Customer create form saves profile and authorized primary contact, addresses, and note');

        Livewire::test(CustomerFormPage::class, ['customer' => $uiCustomer->id])
            ->set('form.name', 'UI Edited Customer '.$suffix)
            ->call('save');
        $verify($uiCustomer->fresh()->name === 'UI Edited Customer '.$suffix && $uiCustomer->fresh()->version === 2, 'Customer edit form saves through the versioned customer action');
        Livewire::test(CustomerFormPage::class)
            ->set('form.name', 'Missing code should fail')
            ->call('save')
            ->assertHasErrors('code');
        $verify(true, 'Customer create form displays validation errors for invalid required fields');

        $detailsPage = Livewire::test(CustomerDetailsPage::class, ['customer' => $uiCustomer->id])->assertSee('UI Edited Customer '.$suffix);
        foreach (['overview', 'contacts', 'financial', 'documents'] as $tab) {
            $detailsPage->set('tab', $tab)->assertSet('tab', $tab);
        }
        $verify(true, 'All four customer detail tabs render for an explicitly authorized actor');
        Livewire::test(DataOperationPage::class, ['entity' => 'customers'])->assertSee($definition->version());
        Livewire::test(DataOperationPage::class, ['entity' => 'customers', 'runId' => $importRun->id])->assertSee($importCode);
        $verify(true, 'Authenticated customer catalog, create/edit forms, details, and shared data-operation pages render persisted records');
    } finally {
        if ($previousActor !== null) {
            $guard->setUser($previousActor);
        } else {
            $guard->forgetUser();
        }
    }

    DB::rollBack();
    echo "M03 customer focused checks passed.\n";
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    throw $exception;
} finally {
    foreach ($privateArtifacts as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    Storage::disk('local')->deleteDirectory('');
}

<?php

use App\Models\User;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SaveQuotationSelfApproval;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Application\DraftAmounts;
use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\QuotationActions;
use App\Modules\Sales\Application\QuotationApprovals;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Application\QuotationExportDefinition;
use App\Modules\Sales\Application\QuotationLineImportDefinition;
use App\Modules\Sales\Application\QuotationReadiness;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationApproval;
use App\Modules\Sales\Domain\QuotationAttachment;
use App\Modules\Sales\Domain\QuotationLine;
use App\Modules\Sales\Domain\QuotationPolicy;
use App\Modules\Sales\Domain\QuotationTerm;
use App\Modules\Sales\Presentation\QuotationDocumentController;
use App\Modules\Sales\Presentation\QuotationFormPage;
use Database\Seeders\QuotationDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
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

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M04 quotation checks require the exact separate PostgreSQL test database.');
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
$decimal = static fn (string $value): string => bcadd($value, '0', DraftAmounts::SCALE);

$diskParent = storage_path('framework/testing/disks');
$diskRoot = $diskParent.DIRECTORY_SEPARATOR.'m04-quotation-check-'.strtoupper(bin2hex(random_bytes(5)));
if (! str_starts_with($diskRoot, $diskParent.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing to use a quotation test path outside the isolated testing folder.');
}
config(['filesystems.disks.local.root' => $diskRoot]);
Storage::forgetDisk('local');
File::ensureDirectoryExists($diskRoot);
DB::beginTransaction();

try {
    $codes = DB::table('permission_catalog')->where('code', 'like', 'M04.%')->pluck('code')->all();
    $expectedM04 = collect(array_keys(PermissionCatalog::DEFINITIONS))
        ->filter(fn ($code) => str_starts_with($code, 'M04.'))
        ->filter(fn ($code) => DB::table('permission_catalog')->where('code', $code)->exists())
        ->sort()->values()->all();
    $administratorRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    $administratorM04 = DB::table('role_permissions')->where('role_id', $administratorRoleId)
        ->where('permission_code', 'like', 'M04.%')->orderBy('permission_code')->pluck('permission_code')->all();
    $otherRoleM04 = DB::table('role_permissions')->where('role_id', '<>', $administratorRoleId)
        ->where('permission_code', 'like', 'M04.%')->exists();
    $verify(in_array('M04.quotation_create', $codes, true) && in_array('M04.quotation_document_view', $codes, true)
        && $administratorM04 === $expectedM04 && ! $otherRoleM04,
        'M04 permissions are registered and default only to Administrator');

    $withoutAccess = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $verify(! app(QuotationAccess::class)->allows($withoutAccess->id, 'view'), 'Quotation access denies an authenticated actor without an explicit M04 grant');
    $expect(HttpException::class, fn () => app(QuotationActions::class)->save($withoutAccess->id, []), 'Quotation creation is denied without quotation_create');

    $unit = Unit::factory()->create();
    $customer = Customer::factory()->create(['code' => 'DEMO-CUS-M04-CHECK', 'name' => 'Synthetic customer M04 check']);
    $product = Product::factory()->create([
        'sku' => 'DEMO-PROD-M04-CHECK',
        'base_unit_id' => $unit->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $searchUnit = Unit::factory()->create();
    $searchProduct = Product::factory()->create([
        'sku' => 'DEMO-PROD-M04-SEARCH',
        'base_unit_id' => $searchUnit->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $factoryQuote = Quotation::factory()->create([
        'customer_id' => $customer->id,
        'customer_snapshot' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'entityType' => $customer->entity_type, 'status' => $customer->status],
    ]);
    $factoryLine = QuotationLine::factory()->create([
        'quotation_id' => $factoryQuote->id,
        'product_id' => $product->id,
        'unit_id' => $unit->id,
        'product_snapshot' => ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'description' => $product->short_description, 'unit_id' => $unit->id, 'unit_code' => $unit->code, 'unit_name' => $unit->name, 'version' => $product->version],
        'description' => $product->name,
    ]);
    $factoryAttachment = QuotationAttachment::factory()->create(['quotation_id' => $factoryQuote->id]);
    $factoryPolicy = QuotationPolicy::factory()->create(['id' => 2]);
    $factoryTerm = QuotationTerm::factory()->create();
    $factoryApproval = QuotationApproval::factory()->create(['requested_by' => $actor->id]);
    $privatePdf = Storage::disk('local')->get($factoryAttachment->path);
    preg_match('/startxref\n([0-9]+)\n%%EOF\n$/', $privatePdf, $xrefMatch);
    $verify($factoryQuote->exists && $factoryQuote->status === 'draft' && $factoryQuote->number === null && $factoryQuote->currency === 'EGP'
        && $factoryQuote->created_by === null && $factoryQuote->updated_by === null
        && $factoryQuote->customer_snapshot['id'] === $factoryQuote->customer_id,
        'Quotation factory persists an unissued synthetic draft with an internally consistent owner-factory customer snapshot');
    $verify((int) $factoryLine->quotation_id === (int) $factoryQuote->id && $factoryLine->quantity === '2.00000000'
        && $factoryLine->suggested_price === null && $factoryLine->requested_price === null
        && $factoryLine->product_snapshot['id'] === $factoryLine->product_id
        && $factoryLine->product_snapshot['unit_id'] === $factoryLine->unit_id,
        'Quotation line factory persists exact decimals and internally consistent owner-factory product/unit identity without invented prices');
    $verify((int) $factoryAttachment->quotation_id === (int) $factoryQuote->id && $factoryAttachment->uploaded_by === null
        && str_starts_with($factoryAttachment->path, 'sales/quotations/internal/demo/'.$factoryQuote->id.'/')
        && Storage::disk('local')->exists($factoryAttachment->path) && $factoryAttachment->mime === 'application/pdf'
        && $factoryAttachment->size === strlen($privatePdf) && hash('sha256', $privatePdf) === $factoryAttachment->sha256
        && str_starts_with($privatePdf, "%PDF-1.4\n") && str_contains($privatePdf, '(Synthetic internal quotation reference) Tj')
        && isset($xrefMatch[1]) && substr($privatePdf, (int) $xrefMatch[1], 4) === 'xref',
        'Quotation attachment factory writes a valid private synthetic PDF with exact size and hash');
    $verify($factoryPolicy->exists && $factoryPolicy->version === 1 && $factoryPolicy->updated_by === null
        && $factoryPolicy->settings['validity_default'] === 30,
        'Quotation policy factory persists an explicitly synthetic, unattributed test policy');
    $verify($factoryTerm->exists && ! $factoryTerm->active && $factoryTerm->revision === 1
        && str_contains($factoryTerm->body, 'Not an approved'),
        'Quotation term factory defaults to inactive synthetic wording');
    $factoryApprovalQuote = Quotation::findOrFail($factoryApproval->quotation_id);
    $verify($factoryApproval->exists && $factoryApproval->status === 'pending' && $factoryApproval->requested_by === $actor->id
        && $factoryApproval->decided_by === null && $factoryApprovalQuote->status === 'pending_approval'
        && $factoryApprovalQuote->version === $factoryApproval->target_version
        && str_contains($factoryApproval->exceptions[0]['reason'], 'Synthetic test fixture'),
        'Quotation approval factory persists a coherent pending test-only request snapshot');

    Auth::guard()->setUser($withoutAccess);
    $expect(HttpException::class, fn () => app(QuotationDocumentController::class)($factoryQuote->id, $factoryAttachment->id), 'Internal quotation attachment download requires explicit document-view permission');

    $m04Actions = ['view', 'create', 'edit', 'cancel', 'duplicate', 'document_view', 'document_manage', 'submit', 'withdraw', 'approve', 'issue', 'deliver', 'accept', 'revise', 'export', 'import', 'template', 'convert', 'history', 'policy_manage'];
    $grants = ['M02.product_view', 'M03.customer_view', ...array_map(fn (string $action) => 'M04.quotation_'.$action, $m04Actions)];
    foreach (array_unique($grants) as $permission) {
        DB::table('access_grants')->insert([
            'user_id' => $actor->id,
            'permission_code' => $permission,
            'branch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $productSearchEditor = Livewire::actingAs($actor)->test(QuotationFormPage::class)
        ->set('productSearch', $searchProduct->sku)
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.unit_id', $unit->id)
        ->call('addLine')
        ->set('lines.1.product_id', $searchProduct->id)
        ->assertSet('lines.1.unit_id', $searchUnit->id)
        ->set('lines.1.requested_price', '31.25000000')
        ->set('lines.1.product_id', $product->id)
        ->assertSet('lines.1.unit_id', $unit->id)
        ->assertSet('lines.1.sku', $product->sku)
        ->assertSet('lines.1.description', $product->name)
        ->assertSet('lines.1.requested_price', '');
    $verify(true, 'Changing an existing line to a product outside the current search refreshes its unit and product snapshot fields and clears the prior requested price');

    $defaultFactoryLine = QuotationLine::factory()->create();
    $factoryQuantity = app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor->id, $defaultFactoryLine->product_id, $defaultFactoryLine->unit_id, $defaultFactoryLine->quantity));
    $verify($factoryQuantity->baseQuantity === $defaultFactoryLine->quantity && $factoryQuantity->baseUnitId === $defaultFactoryLine->unit_id,
        'Quotation line factory default unit is the owning product base unit and converts exactly through the published M02 contract');

    Auth::guard()->setUser($actor);
    $privateDownload = app(QuotationDocumentController::class)($factoryQuote->id, $factoryAttachment->id);
    $directives = array_map('trim', explode(',', (string) $privateDownload->headers->get('Cache-Control')));
    $verify($privateDownload->headers->get('X-Content-Type-Options') === 'nosniff' && in_array('private', $directives, true)
        && in_array('no-store', $directives, true), 'Authorized internal attachment response carries private no-store content-safety headers');

    $httpKernel = app(HttpKernel::class);
    $routeResponses = [];
    $quotePath = '/quotations/'.$factoryQuote->id;
    foreach ([
        '/quotations',
        '/quotations/create',
        '/quotations/policy',
        $quotePath,
        $quotePath.'/edit',
        $quotePath.'/approval',
        $quotePath.'/acceptance',
        $quotePath.'/convert',
        $quotePath.'/pdf',
        $quotePath.'/documents/'.$factoryAttachment->id,
    ] as $uri) {
        Auth::guard()->setUser($actor);
        $request = Request::create($uri, 'GET');
        $response = $httpKernel->handle($request);
        $routeResponses[$uri] = $response->getStatusCode();
        $httpKernel->terminate($request, $response);
    }
    $verify(count($routeResponses) === 10 && ! array_diff(array_values($routeResponses), [200]), 'Authorized quotation list, draft, commercial policy, detail, approval, acceptance, conversion, PDF preview, and private-document routes return successful HTTP responses: '.json_encode($routeResponses));

    $quotationNavUser = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $quotationNavUser->id, 'permission_code' => 'M04.quotation_view', 'branch_id' => null, 'created_at' => now()]);
    Auth::guard()->setUser($quotationNavUser);
    $navigationRequest = Request::create('/dashboard', 'GET');
    $navigationResponse = $httpKernel->handle($navigationRequest);
    $quotationNavigationHtml = $navigationResponse->getContent();
    $httpKernel->terminate($navigationRequest, $navigationResponse);
    $verify($navigationResponse->getStatusCode() === 200 && str_contains($quotationNavigationHtml, 'href="'.route('quotations.index').'"')
        && ! str_contains($quotationNavigationHtml, 'href="'.route('products.index').'"'),
        'M04 quotation-view permission alone shows Sales navigation on the dashboard without requiring M02 product access');

    $draftInput = [
        'customer_id' => $customer->id,
        'quotation_date' => now('Africa/Cairo')->toDateString(),
        'valid_until' => null,
        'discount_mode' => 'percent',
        'discount_value' => '0.00000000',
        'lines' => [[
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity' => '3.00000000',
            'requested_price' => '12.34567890',
            'description' => 'Synthetic priced draft line',
            'discount_mode' => 'percent',
            'discount_value' => '12.50000000',
            'override_reason' => 'Synthetic check override; no approval is represented.',
        ]],
    ];
    $actions = app(QuotationActions::class);
    $draft = $actions->save($actor->id, $draftInput);
    $verify($draft->exists && $draft->status === 'draft' && $draft->version === 1 && $draft->number === null
        && $draft->currency === 'EGP' && $draft->lines->count() === 1 && $draft->lines[0]->requested_price === '12.34567890'
        && $draft->lines[0]->suggested_price === null && $draft->calculation['net'] === $decimal('32.4074071125')
        && $draft->calculation['tax'] === null && $draft->calculation['total'] === null,
        'Manual draft save persists exact decimal inputs and does not invent suggested prices, tax, or final totals');

    $reopenedEditor = Livewire::actingAs($actor)->test(QuotationFormPage::class, ['quotation' => $draft->id]);
    $verify($reopenedEditor->get('recordId') === $draft->id && $reopenedEditor->get('version') === $draft->version
        && $reopenedEditor->get('lines.0.requested_price') === '12.34567890',
        'Reopening an existing draft restores its locked identity, version and exact line price into the editor');
    $reopenedEditor->set('form.notes', 'Saved from reopened Livewire editor')->call('save')
        ->assertRedirect(route('quotations.show', ['quotation' => $draft->id]));
    $reopenedSaved = $draft->fresh();
    $verify($reopenedSaved->version === 2 && $reopenedSaved->notes === 'Saved from reopened Livewire editor'
        && $reopenedSaved->lines->first()->requested_price === '12.34567890',
        'Save from a reopened draft persists the edit once and preserves its exact line values');
    $reopenedEditor->set('form.notes', 'Second edit from same Livewire instance')->call('save');
    $verify($reopenedEditor->get('recordId') === $draft->id && $reopenedEditor->get('version') === 3
        && $draft->fresh()->version === 3 && $draft->fresh()->notes === 'Second edit from same Livewire instance',
        'A second Save on the same reopened editor refreshes its optimistic version instead of issuing a stale edit');

    $creationEditor = Livewire::actingAs($actor)->test(QuotationFormPage::class)
        ->set('form.customer_id', $customer->id)
        ->set('form.valid_until', now('Africa/Cairo')->addDays(10)->toDateString())
        ->set('lines.0.product_id', $product->id)
        ->set('lines.0.unit_id', $unit->id)
        ->set('lines.0.quantity', '1.00000000')
        ->set('lines.0.requested_price', '7.50000000')
        ->set('form.notes', 'Same component repeated Save regression');
    $quoteCountBeforeRepeatSave = DB::table('sales_quotations')->count();
    $creationEditor->call('save');
    $createdThroughEditorId = $creationEditor->get('recordId');
    $creationEditor->set('form.notes', 'Same component second Save')->call('save');
    $verify($creationEditor->get('recordId') === $createdThroughEditorId && $creationEditor->get('version') === 2
        && DB::table('sales_quotations')->count() === $quoteCountBeforeRepeatSave + 1
        && Quotation::findOrFail($createdThroughEditorId)->notes === 'Same component second Save',
        'Repeated Save on a new Livewire draft updates the same record instead of creating a duplicate quotation');

    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$draftInput, 'notes' => 'stale edit'], $draft->id, 0), 'Stale quotation edits are rejected', 'quotation');
    $updated = $actions->save($actor->id, [...$draftInput, 'notes' => 'Explicitly saved second draft version'], $draft->id, $draft->fresh()->version);
    $verify($updated->version === 4 && $updated->fresh()->notes === 'Explicitly saved second draft version', 'Explicit quotation save increments the optimistic version exactly once');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$draftInput, 'notes' => 'Silent overwrite'], $draft->id, 1), 'A concurrent stale edit cannot overwrite the saved quotation', 'quotation');

    $calculation = app(DraftAmounts::class)->calculate([[
        'quantity' => '1.23456789',
        'requested_price' => '9.00000000',
        'suggested_price' => null,
        'discount_mode' => 'percent',
        'discount_value' => '12.50000000',
    ]], 'fixed', '1.00000000');
    $verify($calculation['gross'] === $decimal('11.11111101')
        && $calculation['line_discounts'] === $decimal('1.38888887625')
        && $calculation['quotation_discount'] === $decimal('1')
        && $calculation['net'] === $decimal('8.72222213375')
        && $calculation['tax'] === null && $calculation['total'] === null,
        'Quotation draft calculations use exact decimal strings and keep tax/final total unavailable');
    $unknownPrice = app(DraftAmounts::class)->calculate([[
        'quantity' => '2.00000000',
        'requested_price' => null,
        'suggested_price' => null,
        'discount_mode' => 'percent',
        'discount_value' => '0',
    ]]);
    $verify($unknownPrice['complete'] === false && $unknownPrice['gross'] === null && $unknownPrice['net'] === null
        && $unknownPrice['lines'][0] === ['gross' => null, 'discount' => null, 'net' => null],
        'Missing suggested and requested prices remain explicitly uncalculated');
    $expect(ValidationException::class, fn () => DraftAmounts::decimal('-1.00000000', 'quantity', true), 'Negative quantities are rejected', 'quantity');
    $expect(ValidationException::class, fn () => DraftAmounts::decimal('-0.01000000', 'price'), 'Negative prices are rejected', 'price');
    $expect(ValidationException::class, fn () => app(DraftAmounts::class)->calculate([[
        'quantity' => '1', 'requested_price' => '10', 'discount_mode' => 'percent', 'discount_value' => '100.00000001',
    ]]), 'Percentage discounts above 100 percent are rejected', 'lines.0.discount_value');
    $expect(ValidationException::class, fn () => app(DraftAmounts::class)->calculate([[
        'quantity' => '1', 'requested_price' => '10', 'discount_mode' => 'fixed', 'discount_value' => '10.00000001',
    ]]), 'Fixed discounts above the applicable amount are rejected', 'lines.0.discount_value');

    $import = app(QuotationLineImportDefinition::class);
    $expect(ValidationException::class, fn () => $import->resolve($actor->id, ['sku' => 'MISSING-M04-SKU', 'quantity' => '1', 'unit' => $unit->code], $draft->quotation_date->toDateString()), 'Quotation import rejects unknown catalog SKUs');
    $expect(ValidationException::class, fn () => $import->resolve($actor->id, ['sku' => $product->sku, 'quantity' => '-1', 'unit' => $unit->code], $draft->quotation_date->toDateString()), 'Quotation import rejects negative quantities', 'quantity');
    $expect(ValidationException::class, fn () => $import->resolve($actor->id, ['sku' => $product->sku, 'quantity' => '1', 'unit' => 'UNKNOWN-UOM'], $draft->quotation_date->toDateString()), 'Quotation import rejects unknown units', 'unit');

    $templatePath = $import->template($actor->id);
    $workbook = (new Xlsx)->load($templatePath);
    try {
        $dataSheet = $workbook->getSheetByName('Data');
        foreach (['A2' => $product->sku, 'B2' => '1.25000000', 'C2' => $unit->code] as $cell => $value) {
            $dataSheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_STRING);
        }
        $importPath = $diskRoot.DIRECTORY_SEPARATOR.'quotation-lines-import.xlsx';
        (new XlsxWriter($workbook))->save($importPath);
    } finally {
        $workbook->disconnectWorksheets();
        @unlink($templatePath);
    }
    $uploadedWorkbook = new UploadedFile($importPath, 'quotation-lines-import.xlsx', 'application/zip', null, true);
    $salesCountsBeforePreview = [DB::table('sales_quotations')->count(), DB::table('sales_quotation_lines')->count()];
    $preview = $import->preview($actor->id, $uploadedWorkbook, $draft->quotation_date->toDateString());
    $verify(count($preview) === 1 && $preview[0]['error'] === null && $preview[0]['line']['product_id'] === $product->id
        && $preview[0]['line']['quantity'] === '1.25000000' && $preview[0]['line']['requested_price'] === null
        && $preview[0]['line']['suggested_price'] === null,
        'Template import preview resolves only catalog identity and quantity, with no imported or invented price');
    $livewireUpload = UploadedFile::fake()->createWithContent('quotation-lines-import.xlsx', file_get_contents($importPath));
    $livewireImport = Livewire::actingAs($actor)->test(QuotationFormPage::class)
        ->set('importFile', $livewireUpload)->call('previewImport')->call('applyImport');
    $livewireImport->assertSet('lines.0.product_id', $product->id)->assertSet('lines.0.quantity', '1.25000000');
    $verify([DB::table('sales_quotations')->count(), DB::table('sales_quotation_lines')->count()] === $salesCountsBeforePreview,
        'Import preview and apply change only unsaved editor state until explicit Save Draft');

    $expect(HttpException::class, fn () => app(QuotationExportDefinition::class)->export($withoutAccess->id), 'Quotation Excel export is denied without current export permission');
    $exportPath = app(QuotationExportDefinition::class)->export($actor->id, [], $draft->id);
    $exportBook = (new Xlsx)->load($exportPath);
    try {
        $exportSheet = $exportBook->getActiveSheet();
        $exportRow = $exportSheet->rangeToArray('A2:V2', null, true, false)[0];
        $verify($exportRow[5] === $product->sku && $exportRow[7] === '3.00000000' && $exportRow[10] === '12.34567890'
            && $exportRow[17] === null && $exportRow[18] === null && $exportRow[20] === 'DRAFT - UNROUNDED - TAX UNAVAILABLE',
            'Authorized Excel export includes quotation prices and discounts while leaving tax and final total blank');
    } finally {
        $exportBook->disconnectWorksheets();
        @unlink($exportPath);
    }

    $actions->cancel($actor->id, $updated->id, $updated->version, 'Synthetic closure check');
    $closed = $updated->fresh();
    $verify($closed->status === 'cancelled' && $closed->version === 5 && ! $closed->editable(), 'Cancellation closes the draft and advances its version');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, [...$draftInput, 'notes' => 'Reopen'], $closed->id, $closed->version), 'A closed quotation cannot be reopened through draft save', 'quotation');

    $secondUnit = Unit::factory()->create();
    $secondProduct = Product::factory()->create([
        'base_unit_id' => $secondUnit->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $mutationInput = [...$draftInput, 'payment_terms' => 'Initial proposed payment', 'delivery_terms' => 'Initial proposed delivery'];
    $mutationQuote = $actions->save($actor->id, $mutationInput);
    $mutationInput['project_name'] = 'Action persistence mutation check';
    $mutationInput['discount_mode'] = 'fixed';
    $mutationInput['discount_value'] = '4.25000000';
    $mutationInput['payment_terms'] = 'Amended proposed payment';
    $mutationInput['delivery_terms'] = 'Amended proposed delivery';
    $mutationInput['lines'][0] = [
        ...$mutationInput['lines'][0],
        'product_id' => $secondProduct->id,
        'unit_id' => $secondUnit->id,
        'quantity' => '4.50000000',
        'requested_price' => '31.25000000',
        'discount_mode' => 'fixed',
        'discount_value' => '1.25000000',
    ];
    $mutationSaved = $actions->save($actor->id, $mutationInput, $mutationQuote->id, $mutationQuote->version);
    $mutationSaved->load('lines');
    $verify($mutationSaved->version === 2 && $mutationSaved->project_name === 'Action persistence mutation check'
        && $mutationSaved->discount_mode === 'fixed' && $mutationSaved->discount_value === '4.25000000'
        && $mutationSaved->payment_terms === 'Amended proposed payment' && $mutationSaved->delivery_terms === 'Amended proposed delivery'
        && $mutationSaved->lines->count() === 1 && (int) $mutationSaved->lines[0]->product_id === (int) $secondProduct->id
        && (int) $mutationSaved->lines[0]->unit_id === (int) $secondUnit->id
        && $mutationSaved->lines[0]->quantity === '4.50000000' && $mutationSaved->lines[0]->requested_price === '31.25000000'
        && $mutationSaved->lines[0]->discount_mode === 'fixed' && $mutationSaved->lines[0]->discount_value === '1.25000000',
        'Action update persists product and unit replacement, exact quantity and price, both discounts, project identity, and amended proposed terms');

    $copy = $actions->duplicate($actor->id, $closed->id);
    $verify($copy->status === 'draft' && $copy->number === null && $copy->revision === 1 && $copy->discount_value === '0.00000000'
        && $copy->lines->first()->requested_price === null && $copy->lines->first()->suggested_price === null,
        'Duplicate creates a clean draft with no copied price or lifecycle state');

    $expect(ValidationException::class, fn () => app(QuotationReadiness::class)->guardOperation($actor->id, $copy, 'issue', $copy->version), 'Official issue stays blocked while pricing, tax, policy, and company contracts are unavailable', 'readiness');
    $expect(ValidationException::class, fn () => app(QuotationReadiness::class)->guardOperation($actor->id, $copy, 'convert', $copy->version), 'Sales Order conversion stays blocked without written acceptance and the Sales Order contract', 'readiness');

    putenv('SALES_QUOTATION_DEMO_ACTOR_ID='.$actor->id);
    $_ENV['SALES_QUOTATION_DEMO_ACTOR_ID'] = (string) $actor->id;
    $beforeSeed = DB::table('sales_quotations')->count();
    app(QuotationDemoSeeder::class)->run();
    $afterFirstSeed = DB::table('sales_quotations')->count();
    app(QuotationDemoSeeder::class)->run();
    $afterSecondSeed = DB::table('sales_quotations')->count();
    $demoQuote = Quotation::with(['lines', 'attachments'])->where('rfq_reference', 'DEMO-RFQ-001')->first();
    $verify($afterFirstSeed === $beforeSeed + 1 && $afterSecondSeed === $afterFirstSeed
        && $demoQuote?->status === 'draft' && $demoQuote->created_by === null && $demoQuote->lines->first()->suggested_price === null
        && $demoQuote->lines->first()->requested_price === null && $demoQuote->calculation['total'] === null
        && $demoQuote->attachments->count() === 1 && Storage::disk('local')->exists($demoQuote->attachments->first()->path),
        'Explicit quotation demo seeder uses published M02/M03 lookups, creates draft-only private fixtures, and is repeat-safe');

    // This isolated pricing adapter exists only inside the disposable transaction below.
    app()->instance(QuotationPricing::class, new class implements QuotationPricing
    {
        public function suggested(int $actorId, int $productId, int $unitId, string $date): ?array
        {
            return ['amount' => '100.00000000', 'source' => 'isolated-test-fixture', 'reference' => 'TEST-PRICE-ONLY'];
        }
    });
    $policy = app(QuotationCommercialPolicy::class);
    $settings = ['validity_default' => 30, 'validity_min' => 30, 'validity_max' => 30,
        'line_percent' => '5', 'line_fixed' => '5', 'quotation_percent' => '5', 'quotation_fixed' => '5'];
    $policy->save($actor->id, $settings, null);
    $expect(ValidationException::class, fn () => $policy->save($actor->id, $settings, null), 'Commercial policy rejects stale singleton version', 'policy');
    $policy->term($actor->id, ['code' => 'CHECK-PAYMENT', 'kind' => 'payment', 'name' => 'Synthetic payment', 'body' => 'Synthetic payment terms']);
    $oldTerm = QuotationTerm::where('code', 'CHECK-PAYMENT')->firstOrFail();
    $policy->term($actor->id, ['code' => 'CHECK-PAYMENT', 'kind' => 'payment', 'name' => 'Synthetic payment v2', 'body' => 'Synthetic payment terms v2']);
    $newTerm = QuotationTerm::where('code', 'CHECK-PAYMENT')->where('active', true)->firstOrFail();
    $verify($oldTerm->revision === 1 && ! $oldTerm->fresh()->active && $newTerm->revision === 2,
        'Commercial term edits preserve prior revisions and activate only the newest wording');
    $policy->term($actor->id, ['code' => 'CHECK-DELIVERY', 'kind' => 'delivery', 'name' => 'Synthetic delivery', 'body' => 'Synthetic delivery terms']);
    $deliveryTerm = QuotationTerm::where('code', 'CHECK-DELIVERY')->firstOrFail();
    $approvalInput = $draftInput;
    $approvalInput['quotation_date'] = now('Africa/Cairo')->toDateString();
    $approvalInput['valid_until'] = now('Africa/Cairo')->addDays(35)->toDateString();
    $approvalInput['payment_term_id'] = $newTerm->id;
    $approvalInput['delivery_term_id'] = $deliveryTerm->id;
    $approvalInput['discount_value'] = '0';
    $approvalInput['exception_reason'] = 'Synthetic exception rationale';
    $approvalInput['lines'][0]['requested_price'] = '110.00000000';
    $approvalInput['lines'][0]['discount_value'] = '10.00000000';
    $approvalInput['lines'][0]['override_reason'] = 'Synthetic price override rationale';
    $approvalQuote = $actions->save($actor->id, $approvalInput);
    $approvalService = app(QuotationApprovals::class);
    $evaluation = $policy->evaluate($approvalQuote->load('lines'));
    $verify($approvalQuote->lines->first()->suggested_price === '100.00000000' && $evaluation['blockers'] === []
        && count($evaluation['exceptions']) === 3,
        'Approval evaluation uses only explicit fixture pricing and snapshots price, discount, and validity exceptions');
    $request = $approvalService->submit($actor->id, $approvalQuote->id, $approvalQuote->version);
    $approvalQuote->refresh();
    $verify($request->status === 'pending' && $request->target_version === $approvalQuote->version
        && $request->content_hash === $approvalService->hash($approvalQuote->load('lines'))
        && count($request->exceptions) === 3 && $approvalQuote->status === 'pending_approval',
        'Approval submission freezes the draft version, content hash, policy version, and every exception');
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert([
        ['user_id' => $reviewer->id, 'permission_code' => 'M04.quotation_view', 'branch_id' => null, 'created_at' => now()],
        ['user_id' => $reviewer->id, 'permission_code' => 'M04.quotation_approve', 'branch_id' => null, 'created_at' => now()],
    ]);
    $expect(ValidationException::class, fn () => $approvalService->decide($reviewer->id, $approvalQuote->id, $request->id, $approvalQuote->version, false, ''), 'Rejected exception approval requires a reason', 'reason');
    $approvalService->withdraw($actor->id, $approvalQuote->id, $approvalQuote->version);
    $verify($request->fresh()->status === 'withdrawn' && $approvalQuote->fresh()->status === 'draft',
        'Requester withdrawal closes the pending request and returns the quotation to draft');
    $expect(ValidationException::class, fn () => $approvalService->decide($reviewer->id, $approvalQuote->id, $request->id, $approvalQuote->version, true, ''), 'A withdrawn approval request cannot be decided');

    $securityAdmin = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert([
        ['user_id' => $securityAdmin->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now()],
        ['user_id' => $securityAdmin->id, 'permission_code' => 'M01.user_admin', 'branch_id' => null, 'created_at' => now()],
    ]);
    $securityNavUser = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $securityNavUser->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now()]);
    Auth::guard()->setUser($securityNavUser);
    $securityNavigationRequest = Request::create('/dashboard', 'GET');
    $securityNavigationResponse = $httpKernel->handle($securityNavigationRequest);
    $securityNavigationHtml = $securityNavigationResponse->getContent();
    $httpKernel->terminate($securityNavigationRequest, $securityNavigationResponse);
    $verify($securityNavigationResponse->getStatusCode() === 200
        && str_contains($securityNavigationHtml, 'href="'.route('organization.roles').'"')
        && str_contains($securityNavigationHtml, 'تهيئة صلاحيات المبيعات')
        && ! str_contains($securityNavigationHtml, 'href="'.route('quotations.index').'"'),
        'M01 security-admin without M02 or M04 sees the quotation-permission setup link but no quotation list link');
    $securityRole = DB::table('roles')->insertGetId(['code' => 'M04-CHECK-ROLE', 'name' => 'Synthetic quotation settings role', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $securityRoutes = [];
    foreach ([
        '/organization/users/'.$actor->id,
        '/organization/roles/'.$securityRole,
    ] as $uri) {
        Auth::guard()->setUser($securityAdmin);
        $requestHttp = Request::create($uri, 'GET');
        $responseHttp = $httpKernel->handle($requestHttp);
        $securityRoutes[$uri] = ['status' => $responseHttp->getStatusCode(), 'embedded' => str_contains($responseHttp->getContent(), 'wire:submit="save"')];
        $httpKernel->terminate($requestHttp, $responseHttp);
    }
    $verify(count($securityRoutes) === 2 && ! array_filter($securityRoutes, fn ($result) => $result['status'] !== 200 || ! $result['embedded']),
        'M01 security-admin user and role detail routes render the embedded self-approval settings component: '.json_encode($securityRoutes));
    $selfQuote = $actions->save($actor->id, $approvalInput);
    $selfRequest = $approvalService->submit($actor->id, $selfQuote->id, $selfQuote->version);
    $expect(HttpException::class, fn () => $approvalService->decide($actor->id, $selfQuote->id, $selfRequest->id, $selfRequest->target_version, true, ''), 'Self-approval is denied by default');
    $expect(HttpException::class, fn () => app(SaveQuotationSelfApproval::class)->execute($actor, 'user', $actor->id, true, null), 'Only security administrators may configure self-approval');
    app(SaveQuotationSelfApproval::class)->execute($securityAdmin, 'user', $actor->id, true, null);
    $verify(DB::table('access_audits')->where('actor_id', $securityAdmin->id)->where('action', 'quotation.self_approval_policy_changed')->exists(),
        'Self-approval policy changes create a security audit record');
    $approvalService->decide($actor->id, $selfQuote->id, $selfRequest->id, $selfRequest->target_version, true, '');
    $verify($selfRequest->fresh()->status === 'approved' && $selfQuote->fresh()->status === 'draft',
        'Explicit user self-approval enables the authorized requester decision');
    $approvedQuote = $selfQuote->fresh()->load('lines');
    $approvedEvaluation = $policy->evaluate($approvedQuote);
    $verify($approvalService->hasCurrentApproval($approvedQuote, $approvedEvaluation),
        'A recorded approval applies only to the current unchanged quotation and policy snapshot');
    $editedAfterApproval = $actions->save($actor->id, $approvalInput, $approvedQuote->id, $approvedQuote->version);
    $verify(! $approvalService->hasCurrentApproval($editedAfterApproval->load('lines'), $policy->evaluate($editedAfterApproval)),
        'Any later manual save invalidates an earlier approval, even when values are resubmitted unchanged');
    $expect(QueryException::class, fn () => DB::transaction(fn () => DB::table('sales_quotation_approvals')->where('id', $selfRequest->id)->update(['decision_reason' => 'tampered'])),
        'Resolved approval history is immutable at the PostgreSQL database boundary');
    app(SaveQuotationSelfApproval::class)->execute($securityAdmin, 'user', $actor->id, false, 1);
    $revokedQuote = $actions->save($actor->id, $approvalInput);
    $revokedRequest = $approvalService->submit($actor->id, $revokedQuote->id, $revokedQuote->version);
    $expect(HttpException::class, fn () => $approvalService->decide($actor->id, $revokedQuote->id, $revokedRequest->id, $revokedRequest->target_version, true, ''), 'Revoked self-approval permission immediately denies a new self-decision');
    $approvalService->withdraw($actor->id, $revokedQuote->id, $revokedRequest->target_version);

    $stalePolicyQuote = $actions->save($actor->id, $approvalInput);
    $stalePolicyRequest = $approvalService->submit($actor->id, $stalePolicyQuote->id, $stalePolicyQuote->version);
    $changedSettings = $settings;
    $changedSettings['line_percent'] = '20';
    $policy->save($actor->id, $changedSettings, 1);
    $expect(ValidationException::class, fn () => $approvalService->decide($reviewer->id, $stalePolicyQuote->id, $stalePolicyRequest->id, $stalePolicyRequest->target_version, true, ''), 'Policy changes invalidate pending approval snapshots', 'approval');
    $verify($stalePolicyRequest->fresh()->status === 'pending', 'Policy-stale approval remains unresolved until withdrawn and resubmitted');
} finally {
    Auth::forgetGuards();
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::forgetDisk('local');
    File::deleteDirectory($diskRoot);
    echo "Disposable M04 quotation transaction rolled back.\n";
}

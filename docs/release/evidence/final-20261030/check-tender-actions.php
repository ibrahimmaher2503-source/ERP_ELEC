<?php

use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Application\TenderAwards;
use App\Modules\Projects\Application\TenderBids;
use App\Modules\Projects\Application\TenderDocuments;
use App\Modules\Projects\Application\TenderAccess;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Sales\Application\TenderQuotationDraft;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Ramsey\Uuid\Uuid;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$expectedPort = (int) (getenv('TENDER_AUDIT_PORT') ?: 55481);
$racePreparationOnly = getenv('TENDER_AUDIT_PREPARE_RACE') === '1';
$raceFixturePath = getenv('TENDER_AUDIT_RACE_FIXTURE') ?: null;
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || (int) DB::connection()->getConfig('port') !== $expectedPort
    || $expectedPort !== 55481) {
    throw new RuntimeException('Tender action checks require the guarded disposable PostgreSQL clone on port 55481.');
}
if ($racePreparationOnly && (! is_string($raceFixturePath) || $raceFixturePath === '')) {
    throw new RuntimeException('Race preparation mode requires a unique fixture output path.');
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};
$expectStatus = static function (int $status, callable $operation, string $label) use ($verify): void {
    try {
        $operation();
    } catch (HttpExceptionInterface $e) {
        $verify($e->getStatusCode() === $status, $label.' (HTTP '.$e->getStatusCode().')');

        return;
    }

    $verify(false, $label.' (expected HTTP '.$status.')');
};
$stamp = strtoupper(bin2hex(random_bytes(5)));
$keys = [Uuid::uuid4()->toString(), Uuid::uuid4()->toString(), Uuid::uuid4()->toString()];
$oldDiskRoot = config('filesystems.disks.local.root');
$oldStoragePath = app()->storagePath();
$diskParent = storage_path('framework/testing/disks');
$diskRoot = $diskParent.DIRECTORY_SEPARATOR.'tender-actions-check-'.$stamp;
if (! str_starts_with($diskRoot, $diskParent.DIRECTORY_SEPARATOR) || (file_exists($diskRoot) && ! is_dir($diskRoot))) {
    throw new RuntimeException('Refusing to use a non-dedicated Tender private fixture directory.');
}
if (! is_dir($diskRoot) && ! mkdir($diskRoot, 0770, true) && ! is_dir($diskRoot)) {
    throw new RuntimeException('Unable to create the isolated Tender private disk.');
}
$isolatedStoragePath = $diskRoot.DIRECTORY_SEPARATOR.'storage';
if (! is_dir($isolatedStoragePath) && ! mkdir($isolatedStoragePath, 0770, true) && ! is_dir($isolatedStoragePath)) {
    throw new RuntimeException('Unable to create isolated framework storage for Tender document rendering.');
}
app()->useStoragePath($isolatedStoragePath);
config(['filesystems.disks.local.root' => $diskRoot.DIRECTORY_SEPARATOR.'private-disk']);
Storage::forgetDisk('local');
$trackedTables = [
    'users' => 'users', 'branches' => 'branches', 'roles' => 'roles', 'role_permissions' => 'role_permissions',
    'user_roles' => 'user_roles', 'access_grants' => 'access_grants', 'user_branch_scopes' => 'user_branch_scopes',
    'access_audits' => 'access_audits', 'products' => 'products', 'units' => 'units_of_measure',
    'product_unit_conversions' => 'product_unit_conversions', 'product_sale_prices' => 'product_sale_prices',
    'tenders' => 'project_tenders', 'technical_revisions' => 'project_tender_technical',
    'financial_revisions' => 'project_tender_financial', 'approvals' => 'project_tender_approvals',
    'submissions' => 'project_tender_submissions', 'results' => 'project_tender_results',
    'tender_documents' => 'project_tender_documents', 'bid_documents' => 'project_tender_bid_documents',
    'handoff_plans' => 'project_tender_handoff_plans', 'handoffs' => 'project_tender_handoffs',
    'guarantees' => 'project_tender_guarantees', 'guarantee_events' => 'project_tender_guarantee_events',
    'events' => 'project_tender_events', 'workshops' => 'workshops', 'workshop_activities' => 'workshop_activities',
    'projects' => 'projects', 'project_source_links' => 'project_source_links',
    'quotations' => 'sales_quotations', 'quotation_approvals' => 'sales_quotation_approvals',
    'journals' => 'accounting_journals', 'journal_lines' => 'accounting_journal_lines',
    'official_documents' => 'system_official_documents', 'audits' => 'audit_entries',
];
$trackedCounts = static function () use ($trackedTables): array {
    $counts = [];
    foreach ($trackedTables as $key => $table) {
        $counts[$key] = DB::table($table)->count();
    }

    return $counts;
};
$databaseBefore = $trackedCounts();
$knownDefects = [];
DB::beginTransaction();

try {
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }
    $branch = Branch::factory()->create([
        'company_id' => 1,
        'code' => 'TENDER-CHECK-'.$stamp,
        'name' => 'فرع تحقق مناقصات تجريبي',
    ]);
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $checker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $financialHidden = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $otherBranch = Branch::factory()->create(['company_id' => 1, 'code' => 'TENDER-OUT-'.$stamp, 'name' => 'فرع آخر خارج النطاق']);
    $adminRole = DB::table('roles')->where('code', 'Administrator')->first();
    $verify($adminRole !== null, 'Administrator role exists in the isolated fixture schema');
    DB::table('user_roles')->insert([
        ['user_id' => $actor->id, 'role_id' => $adminRole->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $checker->id, 'role_id' => $adminRole->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('user_branch_scopes')->insert([
        ['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $checker->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $outsider->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $financialHidden->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $readerRole = DB::table('roles')->insertGetId(['code' => 'TENDER-READER-'.$stamp, 'name' => 'Tender document reader without prices', 'protected' => false, 'version' => 1]);
    DB::table('user_roles')->insert(['user_id' => $financialHidden->id, 'role_id' => $readerRole, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('role_permissions')->insert([
        ['role_id' => $readerRole, 'permission_code' => 'M07.tender_view', 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $readerRole, 'permission_code' => 'M07.tender_document_view', 'created_at' => now(), 'updated_at' => now()],
    ]);
    config(['release.tenders_enabled' => true]);

    $customer = DB::table('customers')->where('status', 'active')->orderBy('id')->first();
    $product = DB::table('products')->where('active', true)->orderBy('id')->first();
    $verify($customer !== null && $product !== null, 'Existing active customer and catalog product are available for the isolated handoff fixture');
    $finalAt = now('UTC')->addDays(40)->toIso8601String();
    $input = [
        'external_reference' => 'TENDER-CHECK-'.$stamp,
        'title' => 'توريد معدات كهربائية للتحقق',
        'description' => 'سجل صناعي مؤقت لاختبار إجراءات المناقصات.',
        'issuer' => 'الجهة التجريبية للتحقق',
        'customer_id' => $customer->id,
        'category' => 'supply',
        'branch_id' => $branch->id,
        'responsible_id' => $actor->id,
        'published_on' => now('Africa/Cairo')->toDateString(),
        'requirements' => 'مستندات ومتطلبات تجريبية للتحقق من الحفظ.',
        'deadlines' => ['final_at' => $finalAt, 'technical_at' => null, 'financial_at' => null, 'clarification_at' => null, 'site_visit_at' => null, 'opening_at' => null],
    ];
    $actions = app(TenderActions::class);
    $id = $actions->save((int) $actor->id, null, 0, $keys[0], $input);
    $saved = DB::table('project_tenders')->find($id);
    $verify($saved && $saved->status === 'identified' && (int) $saved->version === 1
        && (int) $saved->customer_id === (int) $customer->id && $saved->technical_id === null && $saved->financial_id === null && $saved->submitted_id === null,
        'Tender creation saves an identified draft with an existing customer and no bid, submission, or award');
    $expectStatus(403, fn () => app(TenderAccess::class)->authorize((int) $actor->id, (int) $otherBranch->id, 'view'),
        'Tender viewing is denied for a different active branch outside the actor scope');
    $verify($actions->save((int) $actor->id, null, 0, $keys[0], $input) === $id
        && DB::table('project_tenders')->where('creation_key', $keys[0])->count() === 1,
        'Exact creation-key replay returns the original tender without a duplicate row');

    $changed = $input;
    $changed['title'] .= ' نسخة مختلفة';
    $expectStatus(409, fn () => $actions->save((int) $actor->id, null, 0, $keys[0], $changed),
        'Same creation key rejects a changed tender payload');

    $duplicateIdentity = $input;
    $duplicateIdentity['title'] = 'هوية مرجع مكررة';
    $duplicateIdentity['external_reference'] = '  tender-check-'.$stamp.'  ';
    $duplicateIdentity['issuer'] = 'الجهة التجريبية للتحقق';
    $expectStatus(409, fn () => $actions->save((int) $actor->id, null, 0, $keys[1], $duplicateIdentity),
        'Normalized issuer and external reference reject a second tender identity');

    $badDeadlines = $input;
    $badDeadlines['external_reference'] .= '-BAD-DATE';
    $badDeadlines['deadlines']['final_at'] = now('UTC')->addDays(2)->toIso8601String();
    $badDeadlines['deadlines']['technical_at'] = now('UTC')->addDays(3)->toIso8601String();
    $expectStatus(422, fn () => $actions->save((int) $actor->id, null, 0, $keys[2], $badDeadlines),
        'Tender date validation rejects a technical deadline after the final submission deadline');

    $actions->transition((int) $actor->id, $id, 1, 'under_review', 'مراجعة أهلية المشاركة');
    $verify(DB::table('project_tenders')->where('id', $id)->where('status', 'under_review')->where('version', 2)->exists(),
        'Tender state transition increments its optimistic concurrency version');
    $expectStatus(409, fn () => $actions->transition((int) $actor->id, $id, 1, 'cancelled', 'محاولة باستخدام نسخة قديمة'),
        'Stale tender version cannot overwrite a later state transition');

    $verify(DB::table('project_tenders')->count() === $databaseBefore['tenders'] + 1
        && DB::table('project_tender_events')->where('tender_id', $id)->count() === 2,
        'Only the accepted tender creation and review transition produce lifecycle rows');

    $scopeWorkshop = app(\App\Modules\WorkshopRepairs\Application\WorkshopActions::class)->saveWorkshop((int) $actor->id, null, 0, [
        'code' => 'TENDER-SCOPE-'.$stamp, 'name' => 'Tender scope regression workshop', 'specialty' => 'Synthetic isolated scope test',
        'branch_id' => (int) $branch->id, 'supervisor_id' => (int) $actor->id, 'warehouse_id' => null, 'capacity' => 1,
        'schedule' => [['day' => 1, 'start' => '08:00', 'end' => '09:00']], 'active' => true,
    ]);
    $directGrantViewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach (['M07.tender_view', 'M09.view'] as $permissionCode) {
        DB::table('access_grants')->insert([
            'user_id' => $directGrantViewer->id, 'permission_code' => $permissionCode, 'branch_id' => $branch->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $tenderAccess = app(TenderAccess::class);
    $workshopAccess = app(\App\Modules\WorkshopRepairs\Application\WorkshopAccess::class);
    $expectStatus(403, fn () => DB::transaction(fn () => app(TenderActions::class)->work(
        (int) $directGrantViewer->id, $id, (int) DB::table('project_tenders')->find($id)->version, 'view',
    )), 'A direct branch permission grant cannot bypass a missing user branch scope on a tender action');
    $verify($tenderAccess->branches((int) $directGrantViewer->id, 'view') === []
        && $tenderAccess->query((int) $directGrantViewer->id, 'view')->count() === 0
        && $workshopAccess->branches((int) $directGrantViewer->id, 'view') === []
        && $workshopAccess->query((int) $directGrantViewer->id, 'workshops')->count() === 0,
        'Tender and Workshop branch selectors and queries intersect direct grants with actual active branch scopes');
    $expectStatus(403, fn () => $workshopAccess->authorize((int) $directGrantViewer->id, (int) $branch->id, 'view'),
        'A direct M09 branch view grant cannot bypass missing user branch scope on a workshop action');
    app(\App\Modules\Organization\Application\SetUserBranchScope::class)->execute($actor, (int) $directGrantViewer->id, (int) $branch->id, true);
    $viewableTender = app(TenderActions::class)->work(
        (int) $directGrantViewer->id, $id, (int) DB::table('project_tenders')->find($id)->version, 'view',
    );
    $verify(array_column($tenderAccess->branches((int) $directGrantViewer->id, 'view'), 'id') === [(int) $branch->id]
        && $tenderAccess->query((int) $directGrantViewer->id, 'view')->where('id', $id)->exists()
        && (int) $viewableTender->id === (int) $id
        && array_column($workshopAccess->branches((int) $directGrantViewer->id, 'view'), 'id') === [(int) $branch->id]
        && $workshopAccess->query((int) $directGrantViewer->id, 'workshops')->where('id', $scopeWorkshop)->exists()
        && $workshopAccess->allows((int) $directGrantViewer->id, (int) $branch->id, 'view'),
        'Granting real branch scope restores both modules’ direct-granted selectors, queries, and action visibility');

    $actions->transition((int) $actor->id, $id, 2, 'preparing_technical', 'بدء إعداد العرض الفني');
    $upload = static function (int $uploader, int $version, string $classification, string $title) use ($id): int {
        return app(TenderDocuments::class)->upload(
            $uploader,
            $id,
            $version,
            Uuid::uuid4()->toString(),
            $title,
            $classification,
            UploadedFile::fake()->createWithContent('tender-evidence.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer <<>>\n%%EOF\n"),
        );
    };
    $technicalDoc = $upload((int) $actor->id, 3, 'technical', 'مواصفات فنية تجريبية');
    $verify(DB::table('project_tender_documents')->where('id', $technicalDoc)->where('tender_id', $id)->where('classification', 'technical')->exists()
        && Storage::disk('local')->exists(DB::table('project_tender_documents')->find($technicalDoc)->path),
        'Tender technical evidence is uploaded to isolated private storage and bound to its source');
    $technical = app(TenderBids::class)->technical((int) $actor->id, $id, 4, [
        'requirements' => 'مطابقة المواصفات المعلنة للوحة التوزيع.',
        'documents' => [$technicalDoc],
        'lines' => [[
            'description' => 'لوحة توزيع رئيسية', 'quantity' => '2.00000000', 'unit' => 'وحدة',
            'specification' => 'قاطع رئيسي مناسب للأحمال المحددة.', 'compliance' => 'compliant',
            'deviation' => '', 'alternative' => '', 'product_id' => null, 'catalog_reference' => 'BOQ-EXT-001',
        ]],
    ]);
    $technicalRow = DB::table('project_tender_technical')->find($technical);
    $verify($technicalRow && $technicalRow->sha256 === TenderActions::hash(json_decode($technicalRow->snapshot, true, 512, JSON_THROW_ON_ERROR))
        && $technicalRow->prepared_by === $actor->id && json_decode($technicalRow->snapshot, true)['lines'][0]['product'] === null,
        'Technical BOQ is an immutable hashed external line without an invented catalog product');
    $tenderUi = \Livewire\Livewire::actingAs($actor)
        ->test(\App\Modules\Projects\Presentation\TenderPage::class, ['tender' => $id])
        ->set('tab', 'technical');
    $technicalHtml = $tenderUi->html();
    $verify(str_contains($technicalHtml, $technicalRow->sha256)
        && str_contains($technicalHtml, 'wire:model="technical.requirements"')
        && str_contains($technicalHtml, 'wire:model="technical.lines.0.description"'),
        'Tender technical tab renders the current immutable revision and its editable technical form');

    $factoryTender = \App\Modules\Projects\Domain\Tender::factory()->create([
        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $actor->id, 'created_by' => $actor->id,
    ]);
    $factoryTechnical = \App\Modules\Projects\Domain\TenderTechnical::factory()->for($factoryTender)->create();
    $verify((int) $factoryTechnical->tender_id === (int) $factoryTender->id
        && (int) $factoryTechnical->prepared_by === (int) $actor->id
        && $factoryTechnical->sha256 === TenderActions::hash($factoryTechnical->snapshot)
        && DB::table('project_tender_financial')->where('tender_id', $factoryTender->id)->doesntExist()
        && DB::table('project_tender_approvals')->where('tender_id', $factoryTender->id)->doesntExist()
        && DB::table('project_tender_submissions')->where('tender_id', $factoryTender->id)->doesntExist(),
        'Tender technical factory persists a real tender relationship and hashed study without inventing approval, bid pricing, or submission');

    $unitMasters = app(\App\Modules\Products\Application\ProductMasters::class);
    $unitBase = $unitMasters->save((int) $actor->id, 'unit', [
        'code' => 'TENDER-PCS-'.$stamp, 'name' => 'Test piece '.$stamp, 'active' => true, 'dimension' => 'count',
    ]);
    $unitAlternative = $unitMasters->save((int) $actor->id, 'unit', [
        'code' => 'TENDER-BOX-'.$stamp, 'name' => 'Test box '.$stamp, 'active' => true, 'dimension' => 'count',
    ]);
    $unitMappedProduct = app(\App\Modules\Products\Application\ProductActions::class)->save((int) $actor->id, [
        'sku' => 'TENDER-MAPPED-'.$stamp, 'name' => 'Tender mapped test part '.$stamp,
        'name_en' => 'Tender mapped test part '.$stamp, 'kind' => 'bulk', 'tracking' => 'quantity',
        'base_unit_id' => (int) $unitBase->id, 'purchase_unit_id' => (int) $unitAlternative->id,
        'sales_unit_id' => (int) $unitAlternative->id, 'active' => true,
    ], null, null, [['unit_id' => (int) $unitAlternative->id, 'factor' => '10.00000000']]);
    $unitConversion = app(\App\Modules\Products\Application\Contracts\ProductsApi::class)->convertQuantity(
        new \App\Modules\Products\Application\Contracts\ConvertQuantityRequest((int) $actor->id, (int) $unitMappedProduct->id, (int) $unitAlternative->id, '2.00000000'),
    );
    $verify($unitConversion->baseUnitId === (int) $unitBase->id && $unitConversion->baseQuantity === '20.00000000'
        && DB::table('product_sale_prices')->where('product_id', $unitMappedProduct->id)->doesntExist(),
        'Real M02 actions persist a synthetic 1-box-to-10-pieces conversion without creating any sale-price approval');

    $unitInput = $input;
    $unitInput['external_reference'] .= '-UNIT-MAPPING';
    $unitInput['title'] .= ' M02 unit mapping check';
    $unitInput['responsible_id'] = $actor->id;
    $unitTender = $actions->save((int) $actor->id, null, 0, (string) Uuid::uuid4(), $unitInput);
    $actions->transition((int) $actor->id, $unitTender, 1, 'under_review', 'Unit mapping fixture review');
    $actions->transition((int) $actor->id, $unitTender, 2, 'preparing_technical', 'Unit mapping fixture technical');
    $unitDoc = app(TenderDocuments::class)->upload(
        (int) $actor->id, $unitTender, 3, (string) Uuid::uuid4(), 'Mapped unit fixture evidence', 'technical',
        UploadedFile::fake()->createWithContent('mapped-unit-evidence.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer <<>>\n%%EOF\n"),
    );
    $unitVersion = 4;
    $mappedLine = [
        'description' => 'Mapped catalog fixture '.$unitMappedProduct->sku, 'quantity' => '3.00000000', 'unit' => $unitAlternative->name,
        'specification' => 'Mapped catalog fixture with an explicit M02 quantity unit.', 'compliance' => 'compliant',
        'deviation' => '', 'alternative' => '', 'product_id' => (int) $unitMappedProduct->id, 'catalog_reference' => $unitMappedProduct->sku,
    ];
    $unitTechnicalPayload = [
        'requirements' => 'Mapped product unit conversion fixture.', 'documents' => [$unitDoc], 'lines' => [$mappedLine],
    ];
    $unitRevisionCount = DB::table('project_tender_technical')->where('tender_id', $unitTender)->count();
    $expectStatus(422, fn () => app(TenderBids::class)->technical((int) $actor->id, $unitTender, $unitVersion, $unitTechnicalPayload),
        'Catalog-mapped tender BOQ rejects an omitted M02 unit instead of assuming the base unit');
    $invalidUnitPayload = $unitTechnicalPayload;
    $invalidUnitPayload['lines'][0]['unit_id'] = PHP_INT_MAX;
    $expectStatus(422, fn () => app(TenderBids::class)->technical((int) $actor->id, $unitTender, $unitVersion, $invalidUnitPayload),
        'Catalog-mapped tender BOQ rejects a unit that is not linked to the selected product');
    $verify(DB::table('project_tender_technical')->where('tender_id', $unitTender)->count() === $unitRevisionCount,
        'Rejected mapped-unit proposals leave no partial technical revision');
    $unitTechnicalPayload['lines'][0]['unit_id'] = (int) $unitAlternative->id;
    $unitTechnical = app(TenderBids::class)->technical((int) $actor->id, $unitTender, $unitVersion, $unitTechnicalPayload);
    $unitTechnicalRow = DB::table('project_tender_technical')->find($unitTechnical);
    $unitTechnicalSnapshot = json_decode($unitTechnicalRow->snapshot, true, 512, JSON_THROW_ON_ERROR);
    $verify($unitTechnicalSnapshot['lines'][0]['unit_mapping'] === [
        'unit_id' => (int) $unitAlternative->id, 'base_unit_id' => (int) $unitBase->id, 'base_quantity' => '30.00000000',
    ], 'Mapped tender BOQ freezes the actual M02 nonbase conversion and converted quantity');
    app(TenderBids::class)->approve((int) $checker->id, $unitTender, 5, 'technical', 'Independent unit mapping review');
    $unitFinancialInput = [
        'date' => '2027-01-02', 'mode' => 'exclusive', 'terms' => 'Unit-mapped estimate terms.',
        'reference' => 'Unit-mapped estimate reference', 'labor' => '0.00000000', 'transport' => '0.00000000',
        'other' => '0.00000000', 'contingency' => '0.00000000', 'discount' => '0.00',
        'lines' => [[
            'unit_cost' => '5.00000000', 'selling_price' => '12.00000000',
            'cost_reference' => 'Synthetic nonbase-unit cost basis', 'price_reference' => 'No approved price; explicit sourced exception',
        ]],
    ];
    $unitFinancialId = app(TenderBids::class)->financial((int) $actor->id, $unitTender, 6, $unitFinancialInput);
    $unitFinancialRow = DB::table('project_tender_financial')->find($unitFinancialId);
    $unitFinancialSnapshot = json_decode($unitFinancialRow->snapshot, true, 512, JSON_THROW_ON_ERROR);
    $verify((int) $unitFinancialSnapshot['lines'][0]['unit_id'] === (int) $unitAlternative->id
        && $unitFinancialSnapshot['lines'][0]['unit_mapping'] === $unitTechnicalSnapshot['lines'][0]['unit_mapping']
        && $unitFinancialSnapshot['lines'][0]['approved_price'] === null
        && $unitFinancialSnapshot['lines'][0]['commercial_exception'] === true,
        'Financial pricing retains the selected nonbase M02 unit and frozen conversion, with no fabricated approved price');

    $technicalSnapshotBeforeTamper = $technicalRow->snapshot;
    $outerLevel = DB::transactionLevel();
    $technicalMutationRejected = false;
    try {
        DB::transaction(fn () => DB::table('project_tender_technical')->where('id', $technical)->update([
            'snapshot' => DB::raw("snapshot || '{\"tamper\":true}'::jsonb"),
        ]));
    } catch (QueryException) {
        $technicalMutationRejected = true;
    }
    $verify($technicalMutationRejected && DB::transactionLevel() === $outerLevel
        && DB::table('project_tender_technical')->find($technical)->snapshot === $technicalSnapshotBeforeTamper,
        'Database trigger rejects direct technical revision mutation inside a savepoint while preserving the outer transaction');
    $expectStatus(403, fn () => app(TenderBids::class)->approve((int) $actor->id, $id, 5, 'technical', 'اعتماد فني من المُعد'),
        'Tender technical maker cannot approve their own revision');
    $expectStatus(403, fn () => app(TenderBids::class)->approve((int) $outsider->id, $id, 5, 'technical', 'محاولة اعتماد من مستخدم غير مفوض'),
        'A scoped account without tender authority cannot approve the technical revision');
    app(TenderBids::class)->approve((int) $checker->id, $id, 5, 'technical', 'مراجعة فنية مستقلة');

    $financialInput = [
        'date' => '2027-01-02', 'mode' => 'exclusive', 'terms' => 'العرض صالح وفق الشروط المعلنة.',
        'reference' => 'مصدر تسعير تجريبي موثق', 'labor' => '4.00000000', 'transport' => '1.00000000',
        'other' => '0.00000000', 'contingency' => '0.00000000', 'discount' => '0.00',
        'lines' => [[
            'unit_cost' => '10.00000000', 'selling_price' => '20.00000000',
            'cost_reference' => 'سجل تكلفة تجريبي واضح', 'price_reference' => 'مرجع سعر تجريبي واضح',
        ]],
    ];
    $journalCountBefore = DB::table('accounting_journals')->count();
    $financial = app(TenderBids::class)->financial((int) $actor->id, $id, 6, $financialInput);
    $financialRow = DB::table('project_tender_financial')->find($financial);
    $financialSnapshot = json_decode($financialRow->snapshot, true, 512, JSON_THROW_ON_ERROR);
    $verify($financialRow && $financialRow->technical_id === $technical && $financialRow->prepared_by === $actor->id
        && $financialRow->cost === '25.00000000' && $financialSnapshot['tax']['total'] === $financialRow->total
        && $financialSnapshot['lines'][0]['product_id'] === null && DB::table('accounting_journals')->count() === $journalCountBefore,
        'Financial worksheet uses real dated M11 tax on the unmapped BOQ, exact decimal costs, and creates no journal');
    $tenderUi
        ->set('tab', 'financial')
        ->call('prepareFinancial');
    $financialHtml = $tenderUi->html();
    $verify(str_contains($financialHtml, $financialRow->sha256)
        && str_contains($financialHtml, $financialRow->total)
        && str_contains($financialHtml, 'wire:model="financial.lines.0.unit_cost"')
        && str_contains($financialHtml, 'wire:model="financial.lines.0.selling_price"'),
        'Tender financial tab renders the current immutable revision and its editable financial form');
    $financialSnapshotBeforeTamper = $financialRow->snapshot;
    $outerLevel = DB::transactionLevel();
    $financialMutationRejected = false;
    try {
        DB::transaction(fn () => DB::table('project_tender_financial')->where('id', $financial)->update([
            'snapshot' => DB::raw("snapshot || '{\"tamper\":true}'::jsonb"),
        ]));
    } catch (QueryException) {
        $financialMutationRejected = true;
    }
    $verify($financialMutationRejected && DB::transactionLevel() === $outerLevel
        && DB::table('project_tender_financial')->find($financial)->snapshot === $financialSnapshotBeforeTamper,
        'Database trigger rejects direct financial revision mutation inside a savepoint while preserving the outer transaction');
    $expectStatus(403, fn () => app(TenderBids::class)->approve((int) $actor->id, $id, 7, 'financial', 'اعتماد مالي من المُعد'),
        'Tender financial maker cannot approve their own revision');
    app(TenderBids::class)->approve((int) $checker->id, $id, 7, 'financial', 'مراجعة مالية مستقلة');

    $bids = app(TenderBids::class);
    $issue = $bids->issue((int) $actor->id, $id, 8);
    $issuedDocCount = DB::table('project_tender_bid_documents')->where('financial_id', $financial)->count();
    $verify($issue['id'] !== null && $issuedDocCount === 1
        && DB::table('project_tenders')->where('id', $id)->where('status', 'internal_approval')->whereNull('submitted_id')->exists(),
        'Issuing the approved bid creates a private official document but does not claim that the tender was submitted');
    $receipt = $upload((int) $actor->id, 9, 'submission', 'إيصال تقديم تجريبي');
    $expectStatus(403, fn () => app(TenderDocuments::class)->download((int) $outsider->id, $receipt),
        'Unprivileged scoped user cannot download private tender attachments');
    $expectStatus(403, fn () => app(TenderDocuments::class)->download((int) $financialHidden->id, $receipt),
        'Document reader without financial-view authority cannot download a private submission receipt');
    $expectStatus(403, fn () => app(TenderDocuments::class)->bid((int) $outsider->id, $id, $financial),
        'Unprivileged scoped user cannot download the private issued tender PDF');
    $expectStatus(403, fn () => app(TenderDocuments::class)->bid((int) $financialHidden->id, $id, $financial),
        'Document reader without financial-view authority cannot download the private issued tender PDF');
    $submissionPayload = ['method' => 'portal', 'reference' => 'بوابة تقديم تجريبية', 'submitted_at' => now('UTC')->toIso8601String(), 'evidence_id' => $receipt];
    $previousTestNow = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse($finalAt)->addSecond());
    $expiredKey = Uuid::uuid4()->toString();
    $expectStatus(409, fn () => $bids->submit((int) $actor->id, $id, 10, $expiredKey, $submissionPayload),
        'Tender submission is denied after its actual final submission deadline');
    Carbon::setTestNow($previousTestNow);
    $verify(DB::table('project_tender_submissions')->where('creation_key', $expiredKey)->doesntExist(),
        'Expired submission leaves no submission record or state transition');
    $submissionKey = Uuid::uuid4()->toString();
    $submission = $bids->submit((int) $actor->id, $id, 10, $submissionKey, $submissionPayload);
    $verify(DB::table('project_tenders')->where('id', $id)->where('status', 'submitted')->where('submitted_id', $submission)->exists()
        && DB::table('project_tender_submissions')->where('id', $submission)->where('document_id', $issue['id'])->exists(),
        'Submission requires the issued bid PDF and a separately classified real submission receipt');
    $changedSubmission = array_replace($submissionPayload, ['reference' => 'مرجع تقديم مختلف']);
    $expectStatus(409, fn () => $bids->submit((int) $actor->id, $id, 11, $submissionKey, $changedSubmission),
        'Tender submission creation key cannot be replayed with a different payload');

    if ($racePreparationOnly) {
        file_put_contents($raceFixturePath, json_encode([
            'tender_id' => (int) $id, 'actor_id' => (int) $actor->id, 'checker_id' => (int) $checker->id,
            'branch_id' => (int) $branch->id, 'customer_id' => (int) $customer->id, 'product_id' => (int) $product->id,
            'unit_id' => (int) $product->base_unit_id, 'submission_id' => (int) $submission,
            'submission_key' => $submissionKey, 'submission_payload' => $submissionPayload,
            'version' => (int) DB::table('project_tenders')->find($id)->version,
            'private_disk' => config('filesystems.disks.local.root'),
            'storage_root' => app()->storagePath(), 'stamp' => $stamp,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    if (! $racePreparationOnly) {
    $awardEvidence = $upload((int) $actor->id, 11, 'award', 'قرار نتيجة تجريبي');
    $result = app(TenderAwards::class)->result((int) $actor->id, $id, 12, [
        'outcome' => 'awarded', 'decided_on' => now('Africa/Cairo')->toDateString(),
        'reference' => 'قرار ترسية تجريبي', 'evidence_id' => $awardEvidence,
        'award_amount' => (string) $financialRow->total,
    ]);
    $verify(DB::table('project_tenders')->where('id', $id)->where('status', 'awarded')->exists()
        && DB::table('project_tender_results')->where('id', $result)->where('submission_id', $submission)->exists(),
        'Award outcome is supported by separate evidence and links to the actual submitted bid');
    try {
        app(TenderAwards::class)->plan((int) $actor->id, $id, 12, [
            'result_id' => $result, 'reference' => 'مرفوض خارج نطاق المرحلة', 'destinations' => ['contract' => ['code' => 'M10']],
        ]);
        $verify(false, 'Tender handoff rejects M10 contract conversion while its owner capability is unavailable');
    } catch (HttpExceptionInterface $e) {
        $verify($e->getStatusCode() === 422, 'Tender handoff rejects M10 contract conversion while its owner capability is unavailable (HTTP '.$e->getStatusCode().')');
    } catch (Throwable $e) {
        $knownDefects[] = 'M10 handoff rejection raised '.get_class($e).': '.$e->getMessage();
        echo 'FAIL M10 handoff rejection must return HTTP 422, got '.get_class($e).': '.$e->getMessage()."\n";
    }

    $guaranteeEvidence = $upload((int) $actor->id, 13, 'guarantee', 'مستند ضمان تجريبي');
    $guarantee = app(TenderAwards::class)->guarantee((int) $actor->id, $id, 14, [
        'reference' => 'BG-'.$stamp, 'kind' => 'performance', 'guarantee_type' => 'performance',
        'bank' => 'بنك تجريبي', 'amount' => '50.00', 'issued_on' => now('Africa/Cairo')->toDateString(),
        'expires_on' => now('Africa/Cairo')->addDays(30)->toDateString(), 'evidence_id' => $guaranteeEvidence,
    ]);
    $verify(DB::table('project_tender_guarantees')->where('id', $guarantee)->exists()
        && DB::table('project_tender_guarantee_events')->where('guarantee_id', $guarantee)->doesntExist()
        && DB::table('accounting_journals')->count() === $journalCountBefore,
        'Tender guarantee is an operational record with private evidence and no fabricated payment or journal');

    $quoteMapping = [
        'date' => '2027-01-02', 'valid_until' => '2027-01-31', 'reference' => 'تعيين مسودة عرض تجريبي',
        'branch_id' => $branch->id, 'customer_id' => $customer->id,
        'lines' => [['product_id' => $product->id, 'unit_id' => $product->base_unit_id]],
    ];
    $handoffInput = [
        'result_id' => $result, 'reference' => 'خطة تحويل ترسية تجريبية',
        'destinations' => [
            'project' => ['code' => 'TN-P-'.$stamp, 'name' => 'مشروع فائز تجريبي', 'manager_id' => $actor->id,
                'start_date' => now('Africa/Cairo')->addDays(1)->toDateString(), 'end_date' => now('Africa/Cairo')->addDays(45)->toDateString(),
                'description' => 'مشروع ناتج عن مسودة ترسية تجريبية.', 'members' => []],
            'quotation' => $quoteMapping,
        ],
    ];
    $handoffBeforeApproval = DB::table('project_tender_handoffs')->where('tender_id', $id)->count();
    $awards = app(TenderAwards::class);
    $badBranchPlan = $awards->plan((int) $actor->id, $id, 15, [
        'result_id' => $result, 'reference' => 'خطة بفرع متعارض',
        'destinations' => ['project' => array_replace($handoffInput['destinations']['project'], ['code' => 'TN-BAD-BR-'.$stamp, 'branch_id' => $branch->id + 1])],
    ]);
    $awards->approve((int) $checker->id, $id, 16, $badBranchPlan, 'مراجعة مستقلة لخطة الفرع غير المطابق');
    $expectStatus(422, fn () => $awards->convert((int) $actor->id, $id, 17, $badBranchPlan),
        'Project handoff rejects a branch that differs from the awarded tender branch');
    $verify(DB::table('project_tender_handoffs')->where('tender_id', $id)->doesntExist()
        && DB::table('projects')->where('code', 'TN-BAD-BR-'.$stamp)->doesntExist(),
        'Rejected branch mapping creates no project and no handoff record');

    $badCustomerPlan = $awards->plan((int) $actor->id, $id, 17, [
        'result_id' => $result, 'reference' => 'خطة بعميل متعارض',
        'destinations' => ['project' => array_replace($handoffInput['destinations']['project'], ['code' => 'TN-BAD-CU-'.$stamp, 'customer_id' => $customer->id + 1])],
    ]);
    $awards->approve((int) $checker->id, $id, 18, $badCustomerPlan, 'مراجعة مستقلة لخطة العميل غير المطابق');
    $expectStatus(422, fn () => $awards->convert((int) $actor->id, $id, 19, $badCustomerPlan),
        'Project handoff rejects a customer that differs from the awarded tender customer');
    $verify(DB::table('project_tender_handoffs')->where('tender_id', $id)->doesntExist()
        && DB::table('projects')->where('code', 'TN-BAD-CU-'.$stamp)->doesntExist(),
        'Rejected customer mapping creates no project and no handoff record');

    $plan = $awards->plan((int) $actor->id, $id, 19, $handoffInput);
    $expectStatus(403, fn () => $awards->approve((int) $actor->id, $id, 20, $plan, 'اعتماد من مُعد الخطة'),
        'Tender handoff plan proposer cannot approve their own conversion');
    $awards->approve((int) $checker->id, $id, 20, $plan, 'مراجعة مستقلة لخطة التحويل');
    $targets = $awards->convert((int) $actor->id, $id, 21, $plan);
    $handoffs = DB::table('project_tender_handoffs')->where('tender_id', $id)->where('plan_id', $plan)->get()->keyBy('destination');
    $quotationId = (int) $targets['quotation'];
    $quotationLink = DB::table('sales_tender_sources')->where('tender_id', $id)->where('quotation_id', $quotationId)->first();
    $verify(isset($targets['project'], $targets['quotation']) && $handoffs->count() === 2
        && DB::table('projects')->where('id', $targets['project'])->where('branch_id', $branch->id)->where('customer_id', $customer->id)->exists()
        && $quotationLink && (int) $quotationLink->branch_id === (int) $branch->id && (int) $quotationLink->customer_id === (int) $customer->id
        && DB::table('sales_quotations')->where('id', $quotationId)->where('status', 'draft')->exists()
        && DB::table('sales_quotation_approvals')->where('quotation_id', $quotationId)->doesntExist()
        && DB::table('project_tender_handoffs')->where('tender_id', $id)->count() > $handoffBeforeApproval,
        'Approved handoff creates a branch/customer-bound project and an unapproved ordinary M04 quotation draft');
    $sourceApi = app(ProjectsApi::class);
    $verify(($sourceApi->tenderAward((int) $actor->id, $id, $result, $quoteMapping)['plan_id'] ?? null) === $plan,
        'Tender to M04 source contract accepts only the exact quotation mapping in the independently approved handoff plan');
    $changedQuoteMapping = $quoteMapping;
    $changedQuoteMapping['customer_id'] = $customer->id + 1;
    $expectStatus(409, fn () => $sourceApi->tenderAward((int) $actor->id, $id, $result, $changedQuoteMapping),
        'M04 source contract rejects a changed customer mapping outside the approved handoff plan');
    $changedQuoteMapping = $quoteMapping;
    $changedQuoteMapping['branch_id'] = $branch->id + 1;
    $expectStatus(409, fn () => $sourceApi->tenderAward((int) $actor->id, $id, $result, $changedQuoteMapping),
        'M04 source contract rejects a changed branch mapping outside the approved handoff plan');
    $expectStatus(409, fn () => app(TenderQuotationDraft::class)->authorize((int) $actor->id, $quotationId, $customer->id + 1),
        'M04 quotation authorization keeps the awarded customer immutable');
    $expectStatus(409, fn () => app(TenderQuotationDraft::class)->authorize((int) $actor->id, $quotationId, null, $branch->id + 1),
        'M04 quotation authorization keeps the awarded branch immutable');

    $financialBeforeRollback = DB::table('project_tender_financial')->where('tender_id', $id)->count();
    $changedEstimate = $financialInput;
    $changedEstimate['lines'][0]['selling_price'] = '22.00000000';
    $expectStatus(409, fn () => $bids->financial((int) $actor->id, $id, 22, $changedEstimate),
        'A completed tender cannot be repriced after submission and handoff');
    $verify(DB::table('project_tender_financial')->where('tender_id', $id)->count() === $financialBeforeRollback,
        'Tender estimate rejection leaves the immutable approved financial history unchanged');
    }
} finally {
    if ($racePreparationOnly && is_string($raceFixturePath) && is_file($raceFixturePath)) {
        DB::commit();
        echo "COMMITTED SYNTHETIC SUBMISSION RACE FIXTURE {$raceFixturePath}\n";
    } else {
        DB::rollBack();
        $afterRollback = $trackedCounts();
        $verify($afterRollback === $databaseBefore, 'Tender/M02/security fixtures and business records fully roll back on the disposable database');
        $fixtureFiles = Storage::disk('local')->allFiles();
        Storage::disk('local')->delete($fixtureFiles);
        Storage::forgetDisk('local');
        app()->useStoragePath($oldStoragePath);
        config(['filesystems.disks.local.root' => $oldDiskRoot]);
        Storage::forgetDisk('local');
        $resolvedParent = realpath($diskParent);
        $resolvedRoot = realpath($diskRoot);
        if ($resolvedParent !== false && $resolvedRoot !== false && str_starts_with($resolvedRoot, $resolvedParent.DIRECTORY_SEPARATOR)) {
            File::deleteDirectory($resolvedRoot);
        }
        if ($knownDefects !== []) {
            throw new RuntimeException('Tender action defects: '.implode('; ', $knownDefects));
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Presentation\AccountingPage;
use App\Modules\Accounting\Presentation\FinancialReportsPage;
use App\Modules\Attendance\Application\AttendanceActions;
use App\Modules\Attendance\Domain\AttendanceShift;
use App\Modules\Attendance\Presentation\AttendancePage;
use App\Modules\FieldMaintenance\Domain\MaintenanceRequest;
use App\Modules\FieldMaintenance\Presentation\MaintenancePage;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Inventory\Presentation\InventoryDocumentPage;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\UserAccess;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Organization\Presentation\BranchesPage;
use App\Modules\Organization\Presentation\CompanyPage;
use App\Modules\Organization\Presentation\EmployeesPage;
use App\Modules\Organization\Presentation\UserAccessSection;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Application\ProductMediaActions;
use App\Modules\Products\Domain\Product;
use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Application\TenderDocuments;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectPhase;
use App\Modules\Projects\Domain\ProjectTask;
use App\Modules\Projects\Presentation\ProjectPage;
use App\Modules\Projects\Presentation\ProjectsPage;
use App\Modules\Projects\Presentation\TenderPage;
use App\Modules\Purchasing\Presentation\OrderPage;
use App\Modules\Purchasing\Presentation\PurchasingDashboard;
use App\Modules\Purchasing\Presentation\RequisitionPage;
use App\Modules\Purchasing\Presentation\RfqPage;
use App\Modules\Sales\Presentation\QuotationsPage;
use App\Modules\Sales\Presentation\SalesOrdersPage;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\ServiceContracts\Domain\ServiceContract;
use App\Modules\ServiceContracts\Presentation\ServiceContractPage;
use App\Modules\ServiceContracts\Presentation\ServiceContractReportPage;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\WorkshopRepairs\Application\WorkshopAccess;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopEstimates;
use App\Modules\WorkshopRepairs\Application\WorkshopFinance;
use App\Modules\WorkshopRepairs\Application\WorkshopService;
use App\Modules\WorkshopRepairs\Domain\Workshop;
use App\Modules\WorkshopRepairs\Domain\WorkshopIntake;
use App\Modules\WorkshopRepairs\Domain\WorkshopJob;
use App\Modules\WorkshopRepairs\Presentation\IntakePage;
use App\Modules\WorkshopRepairs\Presentation\JobPage;
use App\Modules\WorkshopRepairs\Presentation\WorkshopsPage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\OptimizationRegressionDatabaseGuard;
use Tests\TestCase;

class OptimizationRegressionTest extends TestCase
{
    #[DataProvider('reportFilterComponents')]
    public function test_invalid_report_filters_show_field_errors_and_recover_without_losing_input(string $component, array $parameters): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $branch = Branch::factory()->create();
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['M04.quotation_view', 'M04.sales_order_view', 'M05.dashboard_view', 'M11.report_view', 'M11.journal_view'] as $permission) {
            $this->grant($actor, $permission);
            $this->grant($actor, $permission, (int) $branch->id);
        }
        Livewire::actingAs($actor)->test($component, $parameters)
            ->assertStatus(200)
            ->set('from', 'not-a-date')
            ->assertHasErrors(['from'])
            ->assertViewHas('filtersValid', false)
            ->assertSet('from', 'not-a-date')
            ->set('from', '2026-01-01')
            ->assertHasNoErrors()
            ->assertViewHas('filtersValid', true)
            ->set('to', '2025-12-31')
            ->assertHasErrors(['to'])
            ->set('to', '')
            ->assertHasNoErrors();
    }

    public static function reportFilterComponents(): array
    {
        return [
            'quotations' => [QuotationsPage::class, []],
            'orders' => [SalesOrdersPage::class, []],
            'purchasing' => [PurchasingDashboard::class, []],
            'accounting' => [FinancialReportsPage::class, ['screen' => 'ledger']],
        ];
    }

    public function test_contract_report_preserves_invalid_date_and_recovers_after_filter_reset(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $branch = Branch::factory()->create();
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M10.reports_view', (int) $branch->id);
        $contract = ServiceContract::factory()->create(['branch_id' => $branch->id]);
        Livewire::actingAs($actor)->test(ServiceContractReportPage::class, ['contract' => $contract->id])
            ->assertStatus(200)
            ->set('to', '')
            ->assertHasErrors(['to'])
            ->assertSet('to', '')
            ->set('to', now('Africa/Cairo')->toDateString())
            ->assertHasNoErrors()
            ->assertSee('الاستحقاقات المتبقية الحالية');
    }

    private array $databaseTarget;

    public function createApplication()
    {
        $root = dirname(__DIR__, 2);
        $environment = [];
        foreach (['APP_ENV', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'E2E_DB_PORT', 'E2E_CLUSTER_TOKEN', 'E2E_CLUSTER_MARKER', 'GITHUB_ACTIONS'] as $key) {
            $value = getenv($key);
            $environment[$key] = $value === false ? '' : (string) $value;
        }
        $this->databaseTarget = OptimizationRegressionDatabaseGuard::target($root, $environment);
        $port = $this->databaseTarget['port'];

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $this->databaseTarget['connection_host'],
            'DB_PORT' => (string) $port,
            'DB_DATABASE' => $this->databaseTarget['database'],
            'DB_USERNAME' => $this->databaseTarget['username'],
            'DB_PASSWORD' => $port === 5432 ? (string) getenv('DB_PASSWORD') : '',
            'DB_URL' => '',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        require_once $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.testing');
        $storage = $port === 55462
            ? $root.'/.tmp/ui-acceptance/optimization-storage'
            : ($port === 49685 ? $root.'/.tmp/master-remediation-20261101/optimization-storage'
                : ($port === 5432 ? $root.'/.tmp/ci-postgres/optimization-storage' : $root.'/.tmp/optimization-regression/storage'));
        foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            if (! is_dir($storage.'/'.$directory) && ! mkdir($storage.'/'.$directory, 0700, true) && ! is_dir($storage.'/'.$directory)) {
                throw new \RuntimeException('Cannot create isolated optimization test storage.');
            }
        }
        $app->useStoragePath($storage);
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.driver', 'pgsql');
        $app['config']->set('database.connections.pgsql.host', $this->databaseTarget['connection_host']);
        $app['config']->set('database.connections.pgsql.port', $port);
        $app['config']->set('database.connections.pgsql.database', $this->databaseTarget['database']);
        $app['config']->set('database.connections.pgsql.username', $this->databaseTarget['username']);
        $app['config']->set('database.connections.pgsql.password', $port === 5432 ? (string) getenv('DB_PASSWORD') : '');
        $app['config']->set('database.connections.pgsql.url', null);
        $actual = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_user, (select pg_get_userbyid(datdba) from pg_database where datname = current_database()) as database_owner, current_setting('server_version_num')::int as server_version_num, current_setting('data_directory') as data_directory");
        $receiptRow = $this->databaseTarget['nonce'] !== null
            ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts where database = current_database()') : null;
        OptimizationRegressionDatabaseGuard::verify($this->databaseTarget, $actual, $receiptRow);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $actual = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port, current_user, (select pg_get_userbyid(datdba) from pg_database where datname = current_database()) as database_owner, current_setting('server_version_num')::int as server_version_num, current_setting('data_directory') as data_directory");
        $receiptRow = $this->databaseTarget['nonce'] !== null
            ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts where database = current_database()') : null;
        OptimizationRegressionDatabaseGuard::verify($this->databaseTarget, $actual, $receiptRow);
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_projects_dashboard_renders_with_authorized_scoped_project_access(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $branch = Branch::factory()->create();
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M07.project_view', $branch->id);

        $this->actingAs($actor)->get(route('projects.dashboard', ['search' => 'demo-search', 'status' => 'active']))
            ->assertRedirect(route('projects.index', ['search' => 'demo-search', 'status' => 'active']));
        $this->get(route('projects.index', ['search' => 'demo-search', 'status' => 'active']))->assertOk();
    }

    public function test_projects_index_filters_scoped_rows_by_search_and_status_without_exposing_other_branches(): void
    {
        $tag = strtoupper(Str::random(8));
        $branch = Branch::factory()->create(['code' => 'DEMO-EG-PROJECT-'.$tag]);
        $otherBranch = Branch::factory()->create(['code' => 'DEMO-EG-PROJECT-OTHER-'.$tag]);
        $customer = Customer::factory()->create(['code' => 'DEMO-EG-PROJECT-'.$tag]);
        User::factory()->disabledDemo()->create();
        $projectData = [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'code' => 'DEMO-EG-PRJ-'.$tag,
            'name' => 'مشروع لوحة إنارة '.$tag,
            'status' => 'active',
        ];
        $visibleProject = Project::factory()->create($projectData);
        $wrongStatus = Project::factory()->create(array_merge($projectData, ['code' => 'DEMO-EG-PRJ-DRAFT-'.$tag, 'status' => 'draft']));
        $hiddenProject = Project::factory()->create(array_merge($projectData, ['branch_id' => $otherBranch->id, 'code' => 'DEMO-EG-PRJ-OTHER-'.$tag]));
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M07.project_view', (int) $branch->id);

        Livewire::actingAs($actor)->test(ProjectsPage::class)
            ->assertSee($visibleProject->code)
            ->assertSee($wrongStatus->code)
            ->assertDontSee($hiddenProject->code)
            ->set('search', $tag)
            ->set('status', 'active')
            ->assertSee($visibleProject->code)
            ->assertDontSee($wrongStatus->code)
            ->assertDontSee($hiddenProject->code);
    }

    public function test_tender_documents_hide_financial_classifications_without_financial_scope(): void
    {
        Storage::fake('local');
        $branch = Branch::factory()->create();
        $manager = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $viewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        foreach ([$manager, $viewer] as $user) {
            DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['M07.tender_view', 'M07.tender_manage', 'M07.tender_document_view', 'M07.tender_financial_view'] as $permission) {
            $this->grant($manager, $permission, $branch->id);
        }
        foreach (['M07.tender_view', 'M07.tender_document_view'] as $permission) {
            $this->grant($viewer, $permission, $branch->id);
        }

        $today = now('Africa/Cairo')->toDateString();
        $tag = strtoupper(Str::random(8));
        $tender = app(TenderActions::class)->save($manager->id, null, 0, (string) Str::uuid(), [
            'external_reference' => 'DEMO-EG-OPT-'.$tag,
            'title' => 'مناقصة اختبار تصنيف المستندات',
            'description' => '',
            'issuer' => 'جهة اختبار محلية',
            'customer_id' => null,
            'category' => 'supply',
            'branch_id' => $branch->id,
            'responsible_id' => $manager->id,
            'published_on' => $today,
            'requirements' => '',
            'deadlines' => ['site_visit_at' => null, 'clarification_at' => null, 'technical_at' => null,
                'financial_at' => null, 'final_at' => now('Africa/Cairo')->addDays(7)->toIso8601String(), 'opening_at' => null],
        ]);
        $documents = app(TenderDocuments::class);
        $version = 1;
        $technical = $documents->upload($manager->id, $tender, $version++, (string) Str::uuid(), 'مواصفات فنية للاختبار', 'technical', UploadedFile::fake()->createWithContent('technical.pdf', "%PDF-1.4\n%%EOF"));
        $financial = $documents->upload($manager->id, $tender, $version, (string) Str::uuid(), 'عرض مالي سري للاختبار', 'financial', UploadedFile::fake()->createWithContent('financial.pdf', "%PDF-1.4\n%%EOF"));

        $this->actingAs($viewer)->get(route('tenders.show', ['tender' => $tender]).'?tab=documents')
            ->assertOk()->assertSee('مواصفات فنية للاختبار')->assertDontSee('عرض مالي سري للاختبار');
        $this->get(route('tenders.show', ['tender' => $tender]).'?tab=financial')->assertForbidden();
        $this->get(route('tenders.document', ['document' => $financial]))->assertForbidden();
        $this->get(route('tenders.document', ['document' => $technical]))->assertOk();
    }

    public function test_batched_project_progress_matches_existing_decimal_formula_with_one_read(): void
    {
        $tag = strtoupper(Str::random(8));
        Branch::factory()->create(['code' => 'DEMO-EG-OPT-'.$tag]);
        Customer::factory()->create(['code' => 'DEMO-EG-OPT-'.$tag]);
        User::factory()->disabledDemo()->create();
        $projects = collect();
        for ($index = 0; $index < 3; $index++) {
            $project = Project::factory()->create();
            $phase = ProjectPhase::factory()->create(['project_id' => $project->id, 'cancelled' => false]);
            $cancelledPhase = ProjectPhase::factory()->create(['project_id' => $project->id, 'cancelled' => true]);
            ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phase->id, 'status' => 'pending']);
            ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phase->id, 'status' => 'completed',
                'completed_by' => $project->manager_id, 'completed_at' => now()]);
            ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $cancelledPhase->id, 'status' => 'completed',
                'completed_by' => $project->manager_id, 'completed_at' => now()]);
            $projects->push($project);
        }

        $access = app(ProjectAccess::class);
        $expected = $projects->mapWithKeys(fn (Project $project) => [$project->id => $access->progress($project)])->all();
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains(strtolower($query->sql), 'project_phases')) {
                $queries++;
            }
        });

        $actual = $access->progressMany($projects);

        $this->assertLessThanOrEqual(1, $queries, 'Batch project progress must use at most one phase/task aggregation query.');
        foreach ($expected as $projectId => $progress) {
            $this->assertSame($progress['percent'], $actual[$projectId]['percent']);
            $this->assertSame($progress['eligible'], $actual[$projectId]['eligible']);
            $this->assertSame(collect($progress['phases'])->map(fn ($phase) => [
                'id' => (int) $phase->id,
                'total' => (int) $phase->total,
                'complete' => (int) $phase->complete,
                'progress' => (string) $phase->progress,
            ])->all(), collect($actual[$projectId]['phases'])->map(fn ($phase) => [
                'id' => (int) $phase->id,
                'total' => (int) $phase->total,
                'complete' => (int) $phase->complete,
                'progress' => (string) $phase->progress,
            ])->all());
        }
    }

    public function test_product_image_and_pdf_downloads_emit_safe_dispositions_and_require_authority(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $product = Product::factory()->create(['created_by' => $actor->id, 'updated_by' => $actor->id]);
        $this->grant($actor, 'M02.product_view');
        $this->grant($actor, 'M02.media_manage');

        $image = app(ProductMediaActions::class)->upload(
            $actor->id,
            $product->id,
            UploadedFile::fake()->image('../فاتورة %2F \\ ختم.png')
        );
        $pdf = app(ProductMediaActions::class)->upload(
            $actor->id,
            $product->id,
            UploadedFile::fake()->createWithContent('../فاتورة %2F \\ مستند.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF")
        );

        $this->actingAs($actor);
        foreach ([[$image, 'inline'], [$pdf, 'attachment']] as [$media, $disposition]) {
            $response = $this->get(route('products.media', ['media' => $media->id]));
            $response->assertOk();
            $header = (string) $response->headers->get('Content-Disposition');
            $this->assertStringStartsWith($disposition.';', strtolower($header));
            $this->assertStringNotContainsString("\r", $header);
            $this->assertStringNotContainsString("\n", $header);
            $this->assertStringNotContainsString('../', $header);
            $this->assertStringNotContainsString('\\', $header);
            $this->assertStringContainsString(rawurlencode($media->name), $header);
        }

        // Legacy media records may predate upload-name normalization. The
        // response layer must still build a safe Content-Disposition header.
        $legacyName = '../فاتورة %2F \\ مستند.pdf';
        $pdf->forceFill(['name' => $legacyName])->save();
        $legacyDownload = $this->get(route('products.media.download', ['media' => $pdf->id]));
        $legacyDownload->assertOk();
        $legacyHeader = (string) $legacyDownload->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', strtolower($legacyHeader));
        $this->assertStringNotContainsString('../', $legacyHeader);
        $this->assertStringNotContainsString('\\', $legacyHeader);
        $safeLegacyName = str_replace(['/', '\\'], '_', $legacyName);
        $this->assertSame($legacyName, $pdf->fresh()->name, 'Response filename safety must not rewrite the stored media record.');
        $this->assertStringContainsString(rawurlencode($safeLegacyName), $legacyHeader);

        $image->forceFill(['name' => '../فاتورة %2F \\ صورة.png'])->save();
        $legacyInline = $this->get(route('products.media', ['media' => $image->id]));
        $legacyInline->assertOk();
        $this->assertStringStartsWith('inline;', strtolower((string) $legacyInline->headers->get('Content-Disposition')));

        $unauthorized = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->actingAs($unauthorized)->get(route('products.media.download', ['media' => $pdf->id]))->assertForbidden();
    }

    public function test_permission_checks_preserve_default_deny_admin_scope_and_same_process_updates(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $branch = Branch::factory()->create();
        $access = app(PermissionAccess::class);
        $this->assertFalse($access->allows($actor, 'M02.price_view'));
        $this->assertFalse($access->allows($actor, 'M99.not_registered'));

        $this->grant($actor, 'M02.price_view');
        $this->assertTrue($access->allows($actor, 'M02.price_view'));
        DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M02.price_view')->delete();
        $this->assertFalse($access->allows($actor, 'M02.price_view'), 'A revoked direct grant must stop authorizing immediately in the same process.');

        $adminRole = DB::table('roles')->where('code', 'Administrator')->first();
        $this->assertNotNull($adminRole);
        DB::table('user_roles')->insert(['user_id' => $actor->id, 'role_id' => $adminRole->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $notPersistedYet = collect(PermissionCatalog::DEFINITIONS)
            ->keys()->first(fn (string $code) => $code !== 'M01.security_admin'
                && DB::table('role_permissions')->where('role_id', $adminRole->id)->where('permission_code', $code)->exists());
        $this->assertNotNull($notPersistedYet);
        // Exercise Administrator's default-all behavior against a registered key
        // before its post-migration synchronization row is materialized.
        DB::table('role_permissions')->where('role_id', $adminRole->id)->where('permission_code', $notPersistedYet)->delete();
        $this->assertTrue($access->allows($actor, $notPersistedYet, $branch->id), 'Administrator membership must include every registered permission even before its persisted matrix row exists.');
        $this->assertFalse($access->allows($actor, 'M99.not_registered', $branch->id));

        $ordinary = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $roleId = DB::table('roles')->insertGetId(['code' => 'OPT-'.strtoupper(bin2hex(random_bytes(4))), 'name' => 'Optimization fixture',
            'protected' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_code' => 'M07.project_view', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_roles')->insert(['user_id' => $ordinary->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($access->allows($ordinary, 'M07.project_view', $branch->id), 'Role permissions must remain branch-scoped.');
        DB::table('user_branch_scopes')->insert(['user_id' => $ordinary->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($access->allows($ordinary, 'M07.project_view', $branch->id));
        DB::table('user_branch_scopes')->where('user_id', $ordinary->id)->where('branch_id', $branch->id)->delete();
        $this->assertFalse($access->allows($ordinary, 'M07.project_view', $branch->id), 'Branch-scope revocation must take effect immediately.');
        DB::table('user_branch_scopes')->insert(['user_id' => $ordinary->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::beginTransaction();
        DB::table('user_roles')->where('user_id', $ordinary->id)->where('role_id', $roleId)->delete();
        $this->assertFalse($access->allows($ordinary, 'M07.project_view', $branch->id));
        DB::rollBack();
        $this->assertTrue($access->allows($ordinary, 'M07.project_view', $branch->id), 'Rolled-back role revocation must restore authority.');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_code', 'M07.project_view')->delete();
        $this->assertFalse($access->allows($ordinary, 'M07.project_view', $branch->id), 'Shared role-matrix changes must take effect immediately.');

        $warehouseActor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        $this->grant($warehouseActor, 'M06.movement_view', $branch->id);
        DB::table('user_branch_scopes')->insert(['user_id' => $warehouseActor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($access->allows($warehouseActor, 'M06.movement_view', $branch->id, $warehouse->id), 'A branch grant must not substitute for a warehouse scope.');
        DB::table('user_warehouse_scopes')->insert(['user_id' => $warehouseActor->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($access->allows($warehouseActor, 'M06.movement_view', $branch->id, $warehouse->id));
        DB::table('user_warehouse_scopes')->where('user_id', $warehouseActor->id)->where('warehouse_id', $warehouse->id)->delete();
        $this->assertFalse($access->allows($warehouseActor, 'M06.movement_view', $branch->id, $warehouse->id), 'Warehouse-scope revocation must take effect immediately.');

        $actor->forceFill(['active' => false])->save();
        $this->assertFalse($access->allows($actor, $notPersistedYet, $branch->id), 'Inactive account status must invalidate effective authority immediately.');
    }

    public function test_permission_snapshot_keeps_many_checks_within_a_small_query_budget(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($actor, 'M02.price_view');
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $access = app(PermissionAccess::class);
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($access->allows($actor, 'M02.price_view'));
        }

        $this->assertLessThanOrEqual(8, $queries, 'Thirty permission checks should use a bounded request-scoped snapshot rather than repeating per-check SQL.');
    }

    public function test_request_snapshot_is_actor_specific_and_raw_cte_mutations_invalidate_it(): void
    {
        $branch = Branch::factory()->create();
        $first = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $second = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($first, 'M07.project_view', $branch->id);
        $this->grant($second, 'M07.project_view', $branch->id);
        DB::table('user_branch_scopes')->insert([
            ['user_id' => $first->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $second->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $access = app(PermissionAccess::class);
        $this->assertTrue($access->allows($first, 'M07.project_view', $branch->id));
        $this->assertTrue($access->allows($second, 'M07.project_view', $branch->id));
        DB::select('WITH revoked AS (DELETE FROM access_grants WHERE user_id = ? AND permission_code = ? AND branch_id = ? RETURNING id) SELECT count(*) FROM revoked', [$second->id, 'M07.project_view', $branch->id]);
        $this->assertTrue($access->allows($first, 'M07.project_view', $branch->id), 'Snapshot for another actor must remain correct after per-actor grant mutation.');
        $this->assertFalse($access->allows($second, 'M07.project_view', $branch->id), 'Raw SQL mutation via writable CTE must invalidate authorization snapshots.');
    }

    public function test_permission_snapshot_tracks_user_branch_move_and_scope_updates(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($actor, 'M07.project_view', $branchA->id);
        DB::table('user_branch_scopes')->insert([
            ['user_id' => $actor->id, 'branch_id' => $branchA->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $actor->id, 'branch_id' => $branchB->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $access = app(PermissionAccess::class);
        $this->assertTrue($access->allows($actor, 'M07.project_view', $branchA->id));
        DB::table('user_branch_scopes')->where('user_id', $actor->id)->where('branch_id', $branchA->id)->delete();
        DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M07.project_view')->where('branch_id', $branchA->id)->update(['branch_id' => $branchB->id]);
        $this->assertFalse($access->allows($actor, 'M07.project_view', $branchA->id));
        $this->assertTrue($access->allows($actor, 'M07.project_view', $branchB->id));
    }

    public function test_organization_contract_refreshes_active_and_verified_actor_state_immediately(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($actor, 'M07.project_view', $branch->id);
        $api = app(OrganizationApi::class);
        $request = new AuthorizeRequest($actor->id, 'M07.project_view', $branch->id);

        $this->assertContains($branch->id, $api->authorize($request)->branchIds);
        $actor->forceFill(['email_verified_at' => null])->save();
        try {
            $api->authorize($request);
            $this->fail('A newly unverified actor must be denied by the Organization contract immediately.');
        } catch (ContractException $exception) {
            $this->assertSame(403, $exception->getCode());
        }

        $actor->forceFill(['email_verified_at' => now()])->save();
        $this->assertContains($branch->id, $api->authorize($request)->branchIds);
        $actor->forceFill(['active' => false])->save();
        try {
            $api->authorize($request);
            $this->fail('A newly inactive actor must be denied by the Organization contract immediately.');
        } catch (ContractException $exception) {
            $this->assertSame(403, $exception->getCode());
        }
    }

    public function test_inventory_scope_projection_downgrades_inactive_branch_and_tracks_warehouse_revocation(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        $adminRoleId = DB::table('roles')->where('code', 'Administrator')->value('id');
        DB::table('user_roles')->insert(['user_id' => $actor->id, 'role_id' => $adminRoleId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);

        $api = app(OrganizationApi::class);
        $scopes = $api->inventoryScopes($actor->id);
        $this->assertCount(1, $scopes);
        $this->assertContains('M06.issue_post', $scopes[0]['permissions']);
        DB::table('branches')->where('id', $branch->id)->update(['active' => false]);
        $scopes = $api->inventoryScopes($actor->id);
        $this->assertContains('M06.movement_view', $scopes[0]['permissions']);
        $this->assertContains('M06.export', $scopes[0]['permissions']);
        $this->assertNotContains('M06.issue_post', $scopes[0]['permissions']);

        DB::beginTransaction();
        DB::table('user_warehouse_scopes')->where('user_id', $actor->id)->where('warehouse_id', $warehouse->id)->delete();
        $this->assertSame([], $api->inventoryScopes($actor->id), 'Warehouse scope revocation must invalidate the request snapshot.');
        DB::rollBack();
        $this->assertCount(1, $api->inventoryScopes($actor->id), 'Rollback must restore the warehouse scope in the same request.');
    }

    public function test_populated_maintenance_rows_render_server_side_mobile_labels(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M08.view', $branch->id);
        Customer::factory()->create();
        User::factory()->disabledDemo()->create();
        MaintenanceRequest::factory()->create(['branch_id' => $branch->id, 'created_by' => $actor->id, 'responsible_id' => $actor->id]);
        Livewire::actingAs($actor)->test(MaintenancePage::class, ['screen' => 'requests'])
            ->assertDontSee(':data-label=', false)
            ->assertSee('data-label="المسؤول"', false);
    }

    public function test_maintenance_list_does_not_load_other_tab_collections_or_dashboard_stats(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($actor, 'M08.view', $branch->id);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $sql = strtolower($query->sql);
            foreach (['maintenance_assignments', 'maintenance_visits', 'maintenance_checklist_templates'] as $table) {
                if (str_contains($sql, 'from "'.$table.'"') || str_contains($sql, 'join "'.$table.'"')) {
                    $queries[] = $table;
                }
            }
            if (str_contains($sql, 'from "maintenance_work_orders"') && str_contains($sql, 'group by "status"')) {
                $queries[] = 'dashboard_status_stats';
            }
        });

        Livewire::actingAs($actor)->test(MaintenancePage::class, ['screen' => 'requests'])->assertSee('الصيانة');

        $this->assertSame([], $queries, 'The requests screen should not fetch dashboard statistics or other-tab assignment/visit/template datasets.');
    }

    public function test_workshop_settings_counts_jobs_with_one_grouped_query_for_all_workshops(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        User::factory()->disabledDemo()->create();
        $workshops = collect();
        for ($index = 0; $index < 4; $index++) {
            $workshops->push(Workshop::factory()->create(['branch_id' => $branch->id]));
        }
        $this->grant($actor, 'M09.view', $branch->id);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $perWorkshopCountQueries = [];
        DB::listen(function ($query) use (&$perWorkshopCountQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'workshop_jobs') && str_contains($sql, 'count(') && str_contains($sql, 'where "workshop_id" = ?')) {
                $perWorkshopCountQueries[] = $query->sql;
            }
        });

        $page = Livewire::actingAs($actor)->test(WorkshopsPage::class, ['screen' => 'settings']);
        foreach ($workshops as $workshop) {
            $page->assertSee($workshop->code);
        }

        $this->assertSame([], $perWorkshopCountQueries, 'Open job counts must not issue one count query per workshop. Queries: '.implode(' | ', $perWorkshopCountQueries));
    }

    public function test_workshop_dashboard_shows_setup_state_for_scoped_actor_with_only_inactive_workshops(): void
    {
        $branch = Branch::factory()->create(['code' => 'DEMO-EG-M09-EMPTY-'.strtoupper(Str::random(8))]);
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        User::factory()->disabledDemo()->create();
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M09.view', (int) $branch->id);
        $this->grant($actor, 'M09.manage', (int) $branch->id);
        $inactive = Workshop::factory()->create(['branch_id' => $branch->id, 'active' => false,
            'code' => 'DEMO-EG-M09-OFF-'.strtoupper(Str::random(8)), 'name' => 'ورشة اختبار غير نشطة']);

        $page = Livewire::actingAs($actor)->test(WorkshopsPage::class, ['screen' => 'dashboard'])
            ->assertSee('ابدأ بتجهيز ورشتك')
            ->assertSee('تجهيز الورشة')
            ->assertSee($inactive->name)
            ->assertSee('غير نشطة')
            ->assertDontSee('متوسط ساعات الإنجاز')
            ->assertDontSeeHtml('workshop-stage-counts')
            ->assertDontSeeHtml('role="table"')
            ->assertViewHas('workshops', fn ($workshops) => $workshops->contains(fn ($workshop) => (int) $workshop->id === (int) $inactive->id && ! $workshop->active));
    }

    public function test_workshop_job_tabs_render_with_missing_serial_and_costs_stay_denied_to_limited_actor(): void
    {
        [$actor, $job] = $this->receivedWorkshopJob();
        $this->grant($actor, 'M09.cost_view', (int) $job->branch_id);
        $tabHeadings = [
            'details' => 'الفحص والتشخيص',
            'estimate' => 'لم ينشأ عرض إصلاح',
            'parts' => 'قطع الغيار المطلوبة',
            'costs' => 'إجمالي التكلفة المدعومة',
            'work' => 'تنفيذ الإصلاح',
            'testing' => 'خطة الاختبارات ونتائجها',
            'delivery' => 'تسليم المعدة وإقفال العهدة',
            'billing' => 'فاتورة الإصلاح والتحصيل',
            'history' => 'التاريخ المحفوظ',
        ];

        foreach ($tabHeadings as $tab => $heading) {
            Livewire::actingAs($actor)->test(JobPage::class, ['job' => $job->id])
                ->set('tab', $tab)
                ->assertStatus(200)
                ->assertSee($heading);
        }

        $limited = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $job->branch_id]);
        DB::table('user_branch_scopes')->insert(['user_id' => $limited->id, 'branch_id' => $job->branch_id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($limited, 'M09.view', (int) $job->branch_id);
        Livewire::actingAs($limited)->test(JobPage::class, ['job' => $job->id])
            ->set('tab', 'details')
            ->assertStatus(200)
            ->assertSee('رقم مسلسل غير مسجل')
            ->assertDontSee('التكلفة الفعلية');
        Livewire::actingAs($limited)->test(JobPage::class, ['job' => $job->id])->set('tab', 'costs')->assertForbidden();
    }

    public function test_workshop_technician_choices_require_active_workshop_membership_and_execute_scope(): void
    {
        [$manager, $job, $workshop] = $this->receivedWorkshopJob();
        $this->grant($manager, 'M09.assign', (int) $job->branch_id);
        $member = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $job->branch_id, 'name' => 'فني عضو الورشة']);
        $nonMember = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $job->branch_id, 'name' => 'فني خارج الورشة']);
        foreach ([$member, $nonMember] as $technician) {
            DB::table('user_branch_scopes')->insert(['user_id' => $technician->id, 'branch_id' => $job->branch_id, 'created_at' => now(), 'updated_at' => now()]);
            $this->grant($technician, 'M09.execute', (int) $job->branch_id);
        }
        DB::table('workshop_members')->insert([
            'workshop_id' => $workshop->id, 'technician_id' => $member->id, 'active' => true,
            'created_by' => $manager->id, 'created_at' => now(),
        ]);

        Livewire::actingAs($manager)->test(JobPage::class, ['job' => $job->id, 'tab' => 'details'])
            ->assertSee('فني عضو الورشة')
            ->assertDontSee('فني خارج الورشة');
    }

    public function test_workshop_contract_options_requires_explicit_coverage_authority(): void
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);

        try {
            app(ServiceContractsApi::class)->workshopOptions($actor->id, $customer->id, $branch->id);
            $this->fail('Contract options must not be exposed without M10.coverage_use.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->grant($actor, 'M10.coverage_use', (int) $branch->id);
        $this->assertSame([], app(ServiceContractsApi::class)->workshopOptions($actor->id, $customer->id, $branch->id));
    }

    public function test_workshop_company_equipment_options_require_an_isolation_authorized_warehouse(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);

        try {
            app(InventoryApi::class)->repairEquipmentOptions($actor->id, $branch->id);
            $this->fail('Workshop company-equipment options must require existing isolation warehouse authority.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_workshop_billing_handoff_accepts_same_branch_ar_read_without_granting_m09_mutations(): void
    {
        [, $job] = $this->receivedWorkshopJob();
        $branchId = (int) $job->branch_id;
        $otherBranch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $branchId]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M09.view', $branchId);
        $access = app(WorkshopAccess::class);

        $this->assertFalse($access->billingHandoff($actor->id, $branchId), 'M09.view alone must not expose billing handoff.');
        try {
            app(WorkshopService::class)->authorizeReconciliation($actor->id, $job->id, $branchId);
            $this->fail('M09.view alone must not authorize workshop billing reconciliation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->grant($actor, 'M12.ar_view', $branchId);
        PermissionAccess::forgetRequestSnapshot();
        $this->assertTrue($access->billingHandoff($actor->id, $branchId));
        $this->assertNull(app(WorkshopService::class)->authorizeReconciliation($actor->id, $job->id, $branchId));
        $this->assertFalse($access->billingHandoff($actor->id, (int) $otherBranch->id), 'Both permissions must share the authorized branch.');
        $this->assertFalse($access->allows($actor->id, $branchId, 'job_manage'), 'Read-only AR authority must not imply workshop mutation.');
        $this->assertFalse($access->allows($actor->id, $branchId, 'billing_request'), 'Read-only AR authority must not imply invoice handoff mutation.');

        DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M12.ar_view')->where('branch_id', $branchId)->delete();
        PermissionAccess::forgetRequestSnapshot();
        $this->assertFalse($access->billingHandoff($actor->id, $branchId), 'Revoked AR scope must take effect on the next authorization read.');
        try {
            app(WorkshopService::class)->authorizeReconciliation($actor->id, $job->id, $branchId);
            $this->fail('Revoked AR scope must stop authorizing workshop billing reconciliation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_workshop_intake_renders_contract_selection_and_forwards_contract_creation_inputs(): void
    {
        [$actor, $intake, $workshop] = $this->receivedWorkshopIntake();
        $serviceDate = now('Africa/Cairo')->toDateString();
        $api = $this->mock(ServiceContractsApi::class);
        $api->shouldReceive('workshopOptions')->zeroOrMoreTimes()->with($actor->id, (int) $intake->customer_id, (int) $intake->branch_id)->andReturn([
            ['id' => 90401, 'title' => 'عقد صيانة اختبار', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addYear()->toDateString()],
        ]);
        $actions = \Mockery::mock(new WorkshopActions);
        $actions->shouldReceive('createJob')->once()->with(
            $actor->id,
            (int) $intake->id,
            (int) $intake->version,
            \Mockery::type('string'),
            (int) $workshop->id,
            'high',
            'contract',
            90401,
            $serviceDate
        )->andReturn(90601);
        $this->instance(WorkshopActions::class, $actions);

        $page = Livewire::actingAs($actor)->test(IntakePage::class, ['intake' => $intake->id])
            ->set('billingMode', 'contract')
            ->assertSee('العقد المغطي')
            ->assertSee('تاريخ تنفيذ الخدمة');
        $page->set('workshopId', (string) $workshop->id)
            ->set('priority', 'high')
            ->set('contractId', '90401')
            ->set('serviceDate', $serviceDate)
            ->call('createJob')
            ->assertRedirect(route('workshops.jobs.show', ['job' => 90601]));
    }

    public function test_workshop_invoice_collection_allocates_only_to_its_linked_open_item(): void
    {
        [$actor, $job] = $this->receivedWorkshopJob();
        $collectionDate = now('Africa/Cairo')->toDateString();
        $finance = \Mockery::mock(new WorkshopFinance);
        $finance->shouldReceive('invoice')->once()->with($actor->id, $job->id)->andReturn([
            'id' => 95101, 'open_item_id' => 95102, 'balance_available' => true,
        ]);
        $this->instance(WorkshopFinance::class, $finance);
        $treasury = $this->mock(TreasuryApi::class);
        $treasury->shouldReceive('executeCustomerCollection')->once()
            ->with(
                $actor->id,
                \Mockery::on(fn (array $data) => $data['branch_id'] === $job->branch_id
                    && $data['customer_id'] === $job->customer_id
                    && $data['account_id'] === '95103'
                    && $data['date'] === $collectionDate
                    && $data['amount'] === '125.00'
                    && $data['reference'] === 'تحصيل إصلاح تجريبي'
                    && $data['allow_advance'] === false
                    && Str::isUuid($data['idempotency_key'])),
                [['open_item_id' => 95102, 'amount' => '125.00']]
            )->andReturn(['movement_id' => 95104]);

        Livewire::actingAs($actor)->test(JobPage::class, ['job' => $job->id])
            ->set('tab', 'billing')
            ->set('collection.account_id', '95103')
            ->set('collection.date', $collectionDate)
            ->set('collection.amount', '125.00')
            ->set('collection.reference', 'تحصيل إصلاح تجريبي')
            ->call('collectInvoice')
            ->assertSet('collectionMovementId', 95104)
            ->assertSet('collection.amount', '');
    }

    public function test_workshop_offer_recomputes_net_from_decimal_prices_instead_of_trusting_billable_input(): void
    {
        [$actor, $job] = $this->receivedWorkshopJob();
        $this->grant($actor, 'M09.cost_view', (int) $job->branch_id);
        $this->grant($actor, 'M09.estimate_manage', (int) $job->branch_id);
        $estimates = \Mockery::mock(new WorkshopEstimates);
        $estimates->shouldReceive('propose')->once()->with(
            $actor->id,
            $job->id,
            $job->version,
            \Mockery::on(fn (array $input) => $input['parts_selling'] === '1.10'
                && $input['labor_selling'] === '2.20'
                && $input['other_selling'] === '0.30'
                && $input['discount_amount'] === '0.60'
                && $input['billable_amount'] === '3.00')
        )->andReturn(95201);
        $this->instance(WorkshopEstimates::class, $estimates);

        Livewire::actingAs($actor)->test(JobPage::class, ['job' => $job->id])
            ->set('tab', 'estimate')
            ->set('estimate.parts_selling', '1.10')
            ->set('estimate.labor_selling', '2.20')
            ->set('estimate.other_selling', '0.30')
            ->set('estimate.discount_amount', '0.60')
            ->set('estimate.billable_amount', '999999.99')
            ->call('proposeEstimate')
            ->assertSet('estimate.billable_amount', '3.00')
            ->assertSet('estimateId', '95201');
    }

    public function test_a_new_http_request_observes_revoked_branch_authority(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $branch->id]);
        $target = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $branch->id]);
        $this->grant($actor, 'M01.user_view', $branch->id);

        $this->actingAs($actor)->get(route('organization.users.show', ['user' => $target->id]))->assertOk();
        DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M01.user_view')->where('branch_id', $branch->id)->delete();
        $this->get(route('organization.users.show', ['user' => $target->id]))->assertForbidden();
    }

    public function test_access_history_only_render_skips_effective_permission_matrix_queries(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $branch->id]);
        $target = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $branch->id]);
        $this->grant($actor, 'M01.security_admin');
        $this->grant($actor, 'M01.user_view', $branch->id);
        $permissionQueries = [];
        DB::listen(function ($query) use (&$permissionQueries): void {
            if (str_contains(strtolower($query->sql), 'role_permissions')) {
                $permissionQueries[] = $query->sql;
            }
        });

        Livewire::actingAs($actor)->test(UserAccessSection::class, [
            'user' => $target->id,
            'historyOnly' => true,
        ])->assertSee('سجل');

        $this->assertSame([], $permissionQueries, 'History-only rendering must not compute the target effective permission matrix.');
    }

    public function test_user_access_checks_do_not_probe_each_target_role_and_grant_for_admin(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $targets = collect(range(1, 10))->map(fn () => User::factory()->create([
            'active' => true, 'email_verified_at' => now(), 'branch_id' => $branch->id,
        ]));
        $this->grant($actor, 'M01.security_admin');
        $this->grant($actor, 'M01.user_admin');

        $access = app(UserAccess::class);
        $this->assertTrue($access->can($actor, 'M01.user_update', $targets->first()));
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries++;
        });
        foreach ($targets->skip(1) as $target) {
            $this->assertTrue($access->can($actor, 'M01.user_update', $target));
        }

        $this->assertLessThanOrEqual(1, $queries, 'Ten effective admin checks should reuse the actor snapshot and avoid per-target security-designation queries.');
    }

    public function test_branch_creator_without_company_view_keeps_create_action_without_company_link(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($actor, 'M01.branch_create');

        Livewire::actingAs($actor)->test(BranchesPage::class)
            ->assertSee('إضافة فرع')
            ->assertDontSee('بيانات الشركة');
    }

    public function test_account_and_journal_edit_keep_selected_id_separate_from_journal_projection(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $branch = Branch::factory()->create();
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M11.account_view');
        $this->grant($actor, 'M11.journal_view', (int) $branch->id);

        $accountId = (int) DB::table('accounting_accounts')->insertGetId([
            'code' => 'UI-PROJECTION-'.$actor->id,
            'name' => 'حساب اختبار عرض التحرير',
            'kind' => 'asset',
            'postable' => true,
            'active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($actor)->test(AccountingPage::class, ['screen' => 'accounts'])
            ->call('edit', $accountId)
            ->assertSet('selected', $accountId)
            ->assertSet('form.code', 'UI-PROJECTION-'.$actor->id)
            ->assertSee('تعديل الحساب')
            ->call('newRecord')
            ->assertSet('selected', null)
            ->assertSee('إضافة سجل');

        $journalId = (int) DB::table('accounting_journals')->insertGetId([
            'creation_key' => (string) Str::uuid(),
            'branch_id' => $branch->id,
            'journal_date' => now('Africa/Cairo')->toDateString(),
            'description' => 'قيد مسودة لاختبار عرض التحديد',
            'status' => 'draft',
            'created_by' => $actor->id,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($actor)->test(AccountingPage::class, ['screen' => 'journals'])
            ->call('edit', $journalId)
            ->assertSet('selected', $journalId)
            ->assertSet('form.description', 'قيد مسودة لاختبار عرض التحديد')
            ->assertSee('تفاصيل القيد #'.$journalId)
            ->assertSee('الحالة: مسودة');
    }

    public function test_unsaved_purchase_forms_keep_selected_products_and_supplier_after_search_changes(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M05.requisition_create', (int) $branch->id);
        $this->grant($actor, 'M05.order_create', (int) $branch->id);
        $this->grant($actor, 'M02.product_view');
        $this->grant($actor, 'M03.supplier_view');
        $product = Product::factory()->create(['created_by' => $actor->id, 'updated_by' => $actor->id]);
        $supplier = Supplier::factory()->create();
        $line = ['product_id' => (string) $product->id, 'quantity' => '1', 'unit_price' => '2', 'discount_amount' => '0'];

        Livewire::actingAs($actor)->test(RequisitionPage::class)
            ->set('form.branch_id', (string) $branch->id)
            ->set('form.lines', [$line])
            ->set('productSearch', 'NO-MATCH-'.$product->id)
            ->assertSee($product->name);

        Livewire::actingAs($actor)->test(OrderPage::class)
            ->set('form.branch_id', (string) $branch->id)
            ->set('form.supplier_id', (string) $supplier->id)
            ->set('form.lines', [$line])
            ->set('supplierSearch', 'NO-MATCH-'.$supplier->id)
            ->set('search', 'NO-MATCH-'.$product->id)
            ->assertSee($supplier->name)
            ->assertSee($product->name);
    }

    public function test_requisition_product_search_over_200_characters_renders_empty_results_safely(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M05.requisition_create', (int) $branch->id);
        $this->grant($actor, 'M02.product_view');

        Livewire::actingAs($actor)->test(RequisitionPage::class)
            ->set('productSearch', str_repeat('Ω', 400))
            ->assertStatus(200)
            ->assertHasNoErrors()
            ->assertViewHas('products', fn ($products) => $products === []);
    }

    public function test_workshop_product_search_over_200_characters_renders_empty_results_safely(): void
    {
        [$actor, $job] = $this->receivedWorkshopJob();
        $this->grant($actor, 'M09.parts_request', (int) $job->branch_id);
        $this->grant($actor, 'M02.product_view');

        Livewire::actingAs($actor)->test(JobPage::class, ['job' => $job->id])
            ->set('tab', 'parts')
            ->set('productSearch', str_repeat('Ω', 400))
            ->assertStatus(200)
            ->assertHasNoErrors()
            ->assertViewHas('products', fn ($products) => $products === []);
    }

    public function test_unsaved_inventory_document_keeps_selected_product_after_search_changes(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouse->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M06.receipt_create', (int) $branch->id);
        $product = Product::factory()->create(['created_by' => $actor->id, 'updated_by' => $actor->id]);

        Livewire::actingAs($actor)->test(InventoryDocumentPage::class, ['kind' => 'opening'])
            ->set('form.warehouse_id', (string) $warehouse->id)
            ->set('form.lines', [['product_id' => (string) $product->id, 'quantity' => '1']])
            ->set('productSearch', 'NO-MATCH-'.$product->id)
            ->assertSee($product->name);
    }

    public function test_editing_and_saving_an_existing_attendance_shift_preserves_its_hhmm_times(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M15.view', (int) $branch->id);
        $this->grant($actor, 'M15.shift_manage', (int) $branch->id);
        $this->grant($actor, 'M01.employee_view', (int) $branch->id);
        $shift = AttendanceShift::factory()->create(['branch_id' => $branch->id, 'start_time' => '08:30', 'end_time' => '16:30']);

        Livewire::actingAs($actor)->test(AttendancePage::class)
            ->call('editShift', (int) $shift->id)
            ->assertSet('shift.start_time', '08:30')
            ->assertSet('shift.end_time', '16:30')
            ->call('saveShift')
            ->assertHasNoErrors()
            ->assertSet('shiftId', null);

        $this->assertDatabaseHas('attendance_shifts', [
            'id' => $shift->id,
            'start_time' => '08:30:00',
            'end_time' => '16:30:00',
            'version' => 2,
        ]);
        $this->assertDatabaseMissing('attendance_schedules', ['shift_id' => $shift->id]);
    }

    public function test_employee_search_resets_to_first_page_after_filtering(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M01.employee_view', (int) $branch->id);
        $this->grant($actor, 'M01.employee_manage', (int) $branch->id);
        $employees = collect(range(1, 26))->map(fn ($number) => Employee::factory()->create([
            'branch_id' => $branch->id,
            'code' => sprintf('DEMO-EG-PAGE-%04d', $number),
        ]));
        $target = $employees->first();

        Livewire::actingAs($actor)->test(EmployeesPage::class)
            ->call('gotoPage', 2)
            ->assertSee('DEMO-EG-PAGE-0026')
            ->set('search', $target->code)
            ->assertSee($target->code);
    }

    public function test_company_form_clears_mapped_validation_error_after_successful_correction(): void
    {
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $this->grant($actor, 'M01.company_view');
        $this->grant($actor, 'M01.company_update');
        $validCode = 'DEMO-EG-COMPANY-RECOVERY-'.$actor->id;

        Livewire::actingAs($actor)->test(CompanyPage::class)
            ->call('edit')
            ->set('form.code', str_repeat('X', 51))
            ->call('save')
            ->assertHasErrors('form.code')
            ->assertSet('editing', true)
            ->set('form.code', $validCode)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false);

        $this->assertDatabaseHas('companies', ['id' => 1, 'code' => $validCode]);
    }

    public function test_new_project_keeps_selected_customer_after_customer_search_changes(): void
    {
        [$actor, $branch, $customer] = $this->customerPickerFixture(['M07.project_create', 'M01.employee_view']);

        Livewire::actingAs($actor)->test(ProjectPage::class)
            ->set('form.branch_id', (string) $branch->id)
            ->set('form.customer_id', (string) $customer->id)
            ->set('customerSearch', 'NO-MATCH-'.$customer->id)
            ->assertSee($customer->name);
    }

    public function test_new_tender_keeps_selected_customer_after_customer_search_changes(): void
    {
        [$actor, $branch, $customer] = $this->customerPickerFixture(['M07.tender_manage', 'M01.employee_view']);

        Livewire::actingAs($actor)->test(TenderPage::class)
            ->set('form.branch_id', (string) $branch->id)
            ->set('form.customer_id', (string) $customer->id)
            ->set('customerSearch', 'NO-MATCH-'.$customer->id)
            ->assertSee($customer->name);
    }

    public function test_new_service_contract_keeps_selected_customer_after_customer_search_changes(): void
    {
        [$actor, $branch, $customer] = $this->customerPickerFixture(['M10.contract_create', 'M01.employee_view']);
        $this->grant($actor, 'M02.product_view');

        $this->assertSame('active', $customer->status);

        Livewire::actingAs($actor)->test(ServiceContractPage::class)
            ->assertStatus(200)
            ->set('form.branch_id', (string) $branch->id)
            ->assertStatus(200)
            ->set('form.customer_id', (string) $customer->id)
            ->assertStatus(200)
            ->assertSee($customer->name)
            ->set('customerSearch', 'NO-MATCH-'.$customer->id)
            ->assertStatus(200)
            ->assertSee($customer->name);
    }

    public function test_new_rfq_keeps_selected_supplier_visible_after_supplier_search_changes(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M05.rfq_create', (int) $branch->id);
        $this->grant($actor, 'M03.supplier_view');
        $supplier = Supplier::factory()->create();

        Livewire::actingAs($actor)->test(RfqPage::class)
            ->set('supplierSearch', $supplier->code)
            ->assertStatus(200)
            ->assertSee($supplier->name)
            ->set('form.supplier_ids', [(string) $supplier->id])
            ->set('supplierSearch', 'NO-MATCH-'.$supplier->id)
            ->assertStatus(200)
            ->assertSee($supplier->name);
    }

    public function test_workshop_offer_keeps_selected_service_product_visible_after_search_changes(): void
    {
        [$actor, $job, $workshop] = $this->receivedWorkshopJob();
        foreach (['M09.assign', 'M09.execute', 'M09.cost_view', 'M09.estimate_manage'] as $permission) {
            $this->grant($actor, $permission, (int) $job->branch_id);
        }
        $this->grant($actor, 'M02.product_view');

        app(WorkshopActions::class)->member($actor->id, (int) $workshop->id, (int) $workshop->version, (int) $actor->id, true);
        app(WorkshopActions::class)->assign($actor->id, (int) $job->id, (int) $job->version, (int) $workshop->id, (int) $actor->id, 'إسناد إصلاح لاختبار اختيار الخدمة');
        $job->refresh();
        app(WorkshopActions::class)->diagnose($actor->id, (int) $job->id, (int) $job->version, [
            'required_tests' => [['name' => 'اختبار العزل', 'required' => true]],
            'fault_classification' => 'اختبار',
            'diagnosis' => 'تشخيص إصلاح تجريبي للتحقق من اختيار الخدمة.',
            'planned_work' => 'تنفيذ فحص وإصلاح تجريبي موثق.',
            'estimated_minutes' => null,
            'estimated_completion' => null,
        ]);

        $product = Product::factory()->create(['kind' => 'service', 'created_by' => $actor->id, 'updated_by' => $actor->id]);

        Livewire::actingAs($actor)->test(JobPage::class, ['job' => $job->id])
            ->set('tab', 'estimate')
            ->set('productSearch', $product->sku)
            ->assertStatus(200)
            ->assertSee($product->name)
            ->set('estimate.product_id', (string) $product->id)
            ->set('productSearch', 'NO-MATCH-'.$product->id)
            ->assertStatus(200)
            ->assertSee($product->name);
    }

    public function test_invalid_attendance_month_shows_inline_error_and_hides_timesheet_rows(): void
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['M15.view', 'M15.schedule_manage', 'M01.employee_view'] as $permission) {
            $this->grant($actor, $permission, (int) $branch->id);
        }
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $shift = AttendanceShift::factory()->create(['branch_id' => $branch->id]);
        app(AttendanceActions::class)->schedule(
            (int) $actor->id,
            (int) $employee->id,
            (int) $shift->id,
            '2026-01-15',
            (string) Str::uuid(),
        );

        Livewire::actingAs($actor)->test(AttendancePage::class)
            ->set('branch', (string) $branch->id)
            ->set('employee', (string) $employee->id)
            ->set('month', '2026-01')
            ->assertSee('2026-01-15')
            ->set('month', '2026-13')
            ->assertHasErrors('month')
            ->assertSee('data-erp-validation')
            ->assertSee('الشهر')
            ->assertDontSee('2026-01-15');
    }

    /**
     * @param  list<string>  $branchPermissions
     * @return array{0: User, 1: Branch, 2: Customer}
     */
    private function customerPickerFixture(array $branchPermissions): array
    {
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grant($actor, 'M03.customer_view');
        foreach ($branchPermissions as $permission) {
            $this->grant($actor, $permission, (int) $branch->id);
        }

        return [$actor, $branch, Customer::factory()->create(['status' => 'active'])];
    }

    /**
     * Create only an intake accepted through the actual custody action. The
     * surrounding feature-test transaction rolls the fixture back.
     *
     * @return array{0: User, 1: WorkshopIntake, 2: Workshop}
     */
    private function receivedWorkshopIntake(): array
    {
        $branch = Branch::factory()->create();
        $customer = Customer::factory()->create();
        User::factory()->disabledDemo()->create();
        $branchId = (int) $branch->id;
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now(), 'branch_id' => $branchId]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['M09.view', 'M09.custody', 'M09.intake_manage', 'M09.job_manage'] as $permission) {
            $this->grant($actor, $permission, $branchId);
        }
        $this->grant($actor, 'M03.customer_view');
        $workshop = Workshop::factory()->create(['branch_id' => $branchId]);
        $intakeId = app(WorkshopActions::class)->saveIntake($actor->id, null, 0, (string) Str::uuid(), [
            'branch_id' => $branchId,
            'customer_id' => (int) $customer->id,
            'company_serial_id' => null,
            'company_movement_id' => null,
            'site_id' => null,
            'contact_id' => null,
            'product_id' => null,
            'project_id' => null,
            'maintenance_work_order_id' => null,
            'maintenance_visit_id' => null,
            'equipment_description' => 'معدة اختبار استقبال للورشة',
            'model' => 'TEST-BREAKER',
            'serial_number' => null,
            'accessories' => 'ملحقات اختبار',
            'reported_fault' => 'بلاغ اختبار محفوظ للاستلام.',
            'arrival_condition' => 'حالة اختبار عند الوصول.',
            'customer_owned' => true,
            'location' => 'استقبال الورشة',
        ]);
        $intake = WorkshopIntake::query()->findOrFail($intakeId);
        app(WorkshopActions::class)->receive(
            $actor->id,
            (int) $intake->id,
            (int) $intake->version,
            (string) Str::uuid(),
            now()->subMinute()->toIso8601String(),
            'إقرار اختبار استلام فعلي'
        );
        $intake->refresh();

        return [$actor, $intake, $workshop];
    }

    /**
     * @return array{0: User, 1: WorkshopJob, 2: Workshop}
     */
    private function receivedWorkshopJob(): array
    {
        [$actor, $intake, $workshop] = $this->receivedWorkshopIntake();
        $job = WorkshopJob::factory()->create(['workshop_id' => $workshop->id, 'billing_mode' => 'billable']);

        return [$actor, $job, $workshop];
    }

    private function grant(User $actor, string $permission, ?int $branchId = null): void
    {
        DB::table('access_grants')->insert([
            'user_id' => $actor->id,
            'permission_code' => $permission,
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

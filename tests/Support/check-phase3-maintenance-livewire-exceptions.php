<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceDocuments;
use App\Modules\FieldMaintenance\Presentation\MaintenanceRequestPage;
use App\Modules\FieldMaintenance\Presentation\MaintenanceWorkOrderPage;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=pgsql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=55443');
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
putenv('DB_URL=');

$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = '55443';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => 55443,
    'database.connections.pgsql.database' => 'erp_elec_new_test',
    'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => '',
    'database.connections.pgsql.url' => null,
]);
DB::purge('pgsql');

$connection = DB::selectOne('select current_database() as database, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql'
    || $connection->database !== 'erp_elec_new_test'
    || ! in_array($connection->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $connection->port !== 55443) {
    throw new RuntimeException('Abort before writes: expected isolated loopback PostgreSQL erp_elec_new_test on port 55443.');
}

DB::beginTransaction();
$documentId = null;
$upload = null;
try {
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    if (! DB::table('companies')->where('id', 1)->exists()) {
        Company::factory()->create();
    }

    $branch = Branch::factory()->create([
        'company_id' => 1,
        'code' => 'M08-LIVEWIRE-'.strtoupper(Str::random(8)),
    ]);
    $customer = Customer::factory()->create(['status' => 'active']);

    DB::table('user_branch_scopes')->insert([
        'user_id' => $actor->id,
        'branch_id' => $branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([
        'M01.branch_view',
        'M03.customer_view',
        'M08.standalone_create',
        'M08.workorder_manage',
        'M08.view',
        'M08.document_manage',
        'M08.document_view',
    ] as $permission) {
        DB::table('access_grants')->insert([
            'user_id' => $actor->id,
            'permission_code' => $permission,
            'branch_id' => $permission === 'M01.branch_view' || $permission === 'M03.customer_view' ? null : $branch->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $workOrder = app(MaintenanceActions::class)->createWorkOrder(
        (int) $actor->id,
        null,
        0,
        (string) Str::uuid(),
        [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'responsible_id' => $actor->id,
            'description' => 'Synthetic Livewire stale-action regression fixture',
            'priority' => 'normal',
            'billable' => false,
        ],
    );

    $component = Livewire::actingAs($actor)
        ->test(MaintenanceWorkOrderPage::class, ['workOrder' => $workOrder]);
    $component->call('changeState', 'complete')->assertHasErrors('operation');
    $component->call('selectTab', 'labor')
        ->assertSet('tab', 'labor')
        ->assertSee('لا توجد ساعات عمل فعلية.');

    $pdfBytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    $upload = UploadedFile::fake()->createWithContent('synthetic-maintenance-report.pdf', $pdfBytes);
    Livewire::actingAs($actor)
        ->test(MaintenanceWorkOrderPage::class, ['workOrder' => $workOrder])
        ->set('attachment', $upload)
        ->call('saveAttachment')
        ->assertHasNoErrors();

    if (! method_exists(MaintenanceRequestPage::class, 'saveAttachment')) {
        throw new RuntimeException('Maintenance request attachment action is not named saveAttachment.');
    }
    $document = DB::table('maintenance_documents')->where('work_order_id', $workOrder)->first();
    if (! $document) {
        throw new RuntimeException('The maintenance owner upload action did not persist its document record.');
    }
    $documentId = (int) $document->id;
    $download = app(MaintenanceDocuments::class)->download((int) $actor->id, $documentId);
    $cacheControl = (string) $download->headers->get('Cache-Control');
    $privateDirective = $download->headers->getCacheControlDirective('private');
    $publicDirective = $download->headers->getCacheControlDirective('public');
    $downloadBytes = $download->getFile()->getContent();
    if ($privateDirective !== true || $publicDirective === true
        || ! str_contains($cacheControl, 'no-store')
        || ! hash_equals($document->sha256, hash('sha256', $downloadBytes))
        || $downloadBytes !== $pdfBytes) {
        throw new RuntimeException('Private maintenance download headers or verified source bytes did not match.');
    }

    $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    try {
        app(MaintenanceDocuments::class)->download((int) $outsider->id, $documentId);
        throw new RuntimeException('A user without branch access unexpectedly downloaded private maintenance evidence.');
    } catch (HttpExceptionInterface $exception) {
        if ($exception->getStatusCode() !== 403) {
            throw $exception;
        }
    }

    fwrite(STDOUT, "PASS stale transition renders its operation error and labor tab empty state.\n");
    fwrite(STDOUT, "PASS Livewire saveAttachment stores the private PDF and download stays private/no-store with intact hash-verified bytes.\n");
    fwrite(STDOUT, "PASS user without branch authority is denied private maintenance document download.\n");
} finally {
    if (isset($workOrder)) {
        foreach (DB::table('maintenance_documents')->where('work_order_id', $workOrder)->get(['path']) as $saved) {
            Storage::disk('local')->delete($saved->path);
        }
    }
    if (is_object($upload) && is_resource($upload->tempFile)) {
        fclose($upload->tempFile);
    }
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

fwrite(STDOUT, "RESULT all_pass=true database={$connection->database} port={$connection->port} fixture_rolled_back=true\n");

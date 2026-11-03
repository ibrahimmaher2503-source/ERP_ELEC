<?php

// Isolated Phase 0 recovery preparation and post-restore verification.
// DB connection and PHASE0_STORAGE_ROOT are supplied by the operator.
use App\Models\User;
use App\Modules\Organization\Presentation\CompanyLogoController;
use App\Modules\Purchasing\Presentation\OrderPdfController;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$expectedPort = getenv('DB_PORT');
if (getenv('DB_HOST') !== '127.0.0.1' || ! in_array($expectedPort, ['55432', '55433'], true)) {
    throw new RuntimeException('ABORT: recovery checker requires one of the isolated local ports 55432/55433.');
}
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$storageRoot = getenv('PHASE0_STORAGE_ROOT');
if (! is_string($storageRoot) || $storageRoot === '') {
    throw new RuntimeException('PHASE0_STORAGE_ROOT is required.');
}
$root = dirname(__DIR__, 2);
foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    $path = rtrim($storageRoot, '\\/').DIRECTORY_SEPARATOR.$dir;
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        throw new RuntimeException('Cannot create isolated storage path.');
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storageRoot);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$actualConnection = DB::selectOne('select current_database() as db, host(inet_server_addr()) as address, inet_server_port() as port');
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test'
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== $expectedPort
    || config('database.connections.pgsql.database') !== 'erp_elec_new_test'
    || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || $actualConnection->db !== 'erp_elec_new_test'
    || $actualConnection->address !== '127.0.0.1'
    || (string) $actualConnection->port !== $expectedPort) {
    throw new RuntimeException('ABORT: unexpected database connection.');
}

$mode = $argv[1] ?? '';
$evidencePath = $root.'/docs/qa/evidence/phase0-recovery-source.json';
$need = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$shaFile = static function (string $relative) use ($need): string {
    $path = Storage::disk('local')->path($relative);
    $need(is_file($path), 'Missing private storage file: '.$relative);

    return hash_file('sha256', $path);
};

if ($mode === 'prepare') {
    $logoBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lGQAAAAASUVORK5CYII=', true);
    $need(is_string($logoBytes), 'PNG fixture decode failed.');
    Storage::disk('local')->put('company-logos/phase0-recovery-logo.png', $logoBytes);
    Storage::disk('local')->put('recovery/private-source-fixture.txt', "PHASE0 PRIVATE FIXTURE\nSynthetic recovery fixture only.\n");
    DB::table('companies')->where('id', 1)->update(['logo_path' => 'company-logos/phase0-recovery-logo.png']);
    $need(DB::table('access_grants')->where('user_id', 1)->where('permission_code', 'M01.company_view')->whereNull('branch_id')->exists(), 'Authorized logo reader missing expected grant.');
    $need(DB::table('access_grants')->where('user_id', 1)->where('permission_code', 'M05.order_view')->where('branch_id', 1)->exists(), 'Authorized PO reader missing expected grant.');
    $need(DB::table('access_grants')->where('user_id', 3)->where('permission_code', 'M05.order_view')->where('branch_id', 1)->doesntExist(), 'Denied actor unexpectedly has PO access.');
    $files = [
        'company-logos/phase0-recovery-logo.png' => $shaFile('company-logos/phase0-recovery-logo.png'),
        'recovery/private-source-fixture.txt' => $shaFile('recovery/private-source-fixture.txt'),
    ];
    $documents = DB::table('system_official_documents')->whereIn('document_type', ['purchase_order', 'quotation', 'sales_order', 'sales_delivery', 'journal'])->orderBy('document_type')->orderBy('official_number')->get();
    $pdfs = [];
    foreach ($documents as $doc) {
        $bytes = base64_decode($doc->pdf_base64, true);
        $need(is_string($bytes) && str_starts_with($bytes, '%PDF-'), 'Malformed persisted PDF '.$doc->official_number);
        $hash = hash('sha256', $bytes);
        $need(hash_equals($doc->sha256, $hash), 'Source PDF stored hash mismatch: '.$doc->official_number);
        $pdfs[] = ['id' => $doc->id, 'type' => $doc->document_type, 'number' => $doc->official_number, 'sha256' => $hash, 'bytes' => strlen($bytes)];
    }
    $counts = [
        'migrations' => DB::table('migrations')->count(),
        'users' => DB::table('users')->count(),
        'branches' => DB::table('branches')->count(),
        'products' => DB::table('products')->count(),
        'purchase_order_issues' => DB::table('purchasing_order_issues')->count(),
        'quotation_issues' => DB::table('sales_quotation_issues')->count(),
        'sales_order_issues' => DB::table('sales_order_issues')->count(),
        'confirmed_deliveries' => DB::table('sales_delivery_instructions')->where('status', 'confirmed')->count(),
        'posted_journals' => DB::table('accounting_journals')->where('status', 'posted')->count(),
        'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
    ];
    $need($counts['migrations'] >= 60 && $counts['purchase_order_issues'] > 0 && $counts['quotation_issues'] > 0
        && $counts['sales_order_issues'] > 0 && $counts['confirmed_deliveries'] > 0 && $counts['posted_journals'] > 0 && $counts['fifo_layers'] > 0,
        'Source relationship counts are incomplete.');
    $manifest = ['database' => DB::selectOne('select current_database() as db, current_setting(\'server_version_num\') as version')->db,
        'server_version_num' => DB::selectOne('select current_setting(\'server_version_num\') as version')->version,
        'counts' => $counts, 'files' => $files, 'official_pdfs' => $pdfs,
        'relationships' => ['po_issue_to_order' => DB::table('purchasing_order_issues as i')->join('purchasing_orders as o', 'o.id', '=', 'i.order_id')->count(),
            'quotation_issue_to_quotation' => DB::table('sales_quotation_issues as i')->join('sales_quotations as q', 'q.id', '=', 'i.quotation_id')->count(),
            'sales_issue_to_order' => DB::table('sales_order_issues as i')->join('sales_orders as o', 'o.id', '=', 'i.order_id')->count(),
            'delivery_docs_to_orders' => DB::table('sales_delivery_instructions as d')->join('sales_orders as o', 'o.id', '=', 'd.order_id')->where('d.status', 'confirmed')->count()],
    ];
    file_put_contents($evidencePath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

if ($mode !== 'verify') {
    throw new RuntimeException('Usage: check-phase0-recovery.php prepare|verify');
}
$source = json_decode(file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR);
$need($source['database'] === 'erp_elec_new_test', 'Source evidence database name mismatch.');
$actualCounts = [
    'migrations' => DB::table('migrations')->count(), 'users' => DB::table('users')->count(),
    'branches' => DB::table('branches')->count(), 'products' => DB::table('products')->count(),
    'purchase_order_issues' => DB::table('purchasing_order_issues')->count(), 'quotation_issues' => DB::table('sales_quotation_issues')->count(),
    'sales_order_issues' => DB::table('sales_order_issues')->count(), 'confirmed_deliveries' => DB::table('sales_delivery_instructions')->where('status', 'confirmed')->count(),
    'posted_journals' => DB::table('accounting_journals')->where('status', 'posted')->count(), 'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
];
$sourceCounts = $source['counts'];
$sourceMigrationCount = $sourceCounts['migrations'];
$restoredMigrationCount = $actualCounts['migrations'];
unset($sourceCounts['migrations'], $actualCounts['migrations']);
$need($actualCounts === $sourceCounts && $restoredMigrationCount >= $sourceMigrationCount,
    'Restored persisted counts differ from source manifest or migrations regressed.');
$fileHashes = [];
foreach ($source['files'] as $path => $expected) {
    $fileHashes[$path] = $shaFile($path);
    $need(hash_equals($expected, $fileHashes[$path]), 'Restored file hash mismatch: '.$path);
}
$pdfHashes = [];
foreach ($source['official_pdfs'] as $expected) {
    $doc = DB::table('system_official_documents')->find($expected['id']);
    $need($doc && $doc->official_number === $expected['number'] && $doc->document_type === $expected['type'], 'Restored official document identity missing.');
    $retrieved = app(OfficialDocuments::class)->retrieve((string) $expected['id']);
    $bytes = $retrieved['bytes'] ?? null;
    $need(is_string($bytes) && str_starts_with($bytes, '%PDF-'), 'Restored PDF bytes invalid: '.$expected['number']);
    $hash = hash('sha256', $bytes);
    $need(hash_equals($expected['sha256'], $hash) && hash_equals($doc->sha256, $hash) && hash_equals($retrieved['sha256'], $hash), 'Restored retrieved PDF SHA-256 mismatch: '.$expected['number']);
    $pdfHashes[] = ['number' => $expected['number'], 'sha256' => $hash, 'bytes' => strlen($bytes)];
}
$joinCounts = ['po_issue_to_order' => DB::table('purchasing_order_issues as i')->join('purchasing_orders as o', 'o.id', '=', 'i.order_id')->count(),
    'quotation_issue_to_quotation' => DB::table('sales_quotation_issues as i')->join('sales_quotations as q', 'q.id', '=', 'i.quotation_id')->count(),
    'sales_issue_to_order' => DB::table('sales_order_issues as i')->join('sales_orders as o', 'o.id', '=', 'i.order_id')->count(),
    'delivery_docs_to_orders' => DB::table('sales_delivery_instructions as d')->join('sales_orders as o', 'o.id', '=', 'd.order_id')->where('d.status', 'confirmed')->count()];
$need($joinCounts === $source['relationships'], 'Restored source relationships differ from manifest.');

// Invoke the actual source controllers with an authenticated restored actor.
$controller = static function (string $kind, int $userId): int {
    Auth::setUser(User::query()->findOrFail($userId));
    try {
        if ($kind === 'logo') {
            return app(CompanyLogoController::class)()->getStatusCode();
        }

        return app(OrderPdfController::class)(1)->getStatusCode();
    } catch (HttpExceptionInterface $error) {
        return $error->getStatusCode();
    } finally {
        Auth::logout();
    }
};
$routes = [
    'authorized_logo_controller' => $controller('logo', 1),
    'authorized_po_pdf_controller' => $controller('pdf', 1),
    'denied_logo_controller' => $controller('logo', 3),
    'denied_po_pdf_controller' => $controller('pdf', 3),
];
$need($routes['authorized_logo_controller'] === 200 && $routes['authorized_po_pdf_controller'] === 200, 'Authorized restored source controller did not return 200: '.json_encode($routes));
$need($routes['denied_logo_controller'] === 403 && $routes['denied_po_pdf_controller'] === 403, 'Denied restored source controller did not return 403: '.json_encode($routes));
$result = ['database' => 'erp_elec_new_test', 'source_migration_count' => $sourceMigrationCount, 'restored_migration_count' => $restoredMigrationCount,
    'post_restore_migrations_applied' => $restoredMigrationCount - $sourceMigrationCount, 'counts_match_source' => true,
    'relationships_match_source' => $joinCounts, 'restored_file_sha256' => $fileHashes, 'retrieved_pdf_sha256' => $pdfHashes,
    'http_status' => $routes, 'all_verified' => true];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

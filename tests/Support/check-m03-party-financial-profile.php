<?php

// Read-only profile reconciliation against the committed synthetic M12/M13 action fixture.
use App\Models\User;
use App\Modules\Parties\Presentation\PartyFinancialSection;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/m03-party-financial-profile-check-storage';
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated M03 readback storage.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
$port = (int) config('database.connections.pgsql.port');
$markerPath = getenv('E2E_CLUSTER_MARKER') ?: '';
$marker = is_file($markerPath) ? json_decode(file_get_contents($markerPath), true) : null;
if (DB::connection()->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql' || $actual->db !== 'erp_elec_new_test'
    || ! str_ends_with($actual->db, '_test') || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || $port !== (int) $actual->port || $port !== 55460 || ! is_array($marker)
    || $marker['kind'] !== 'codex-e2e-postgres' || $marker['repo'] !== $root || (int) $marker['port'] !== $port
    || ! hash_equals((string) ($marker['token'] ?? ''), (string) getenv('E2E_CLUSTER_TOKEN'))) {
    throw new RuntimeException('ABORT before read: the marker-owned isolated PostgreSQL 55460 database is required.');
}
$path = $root.'/docs/qa/evidence/phase1-financial-journeys.json';
$evidence = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
$ids = $evidence['party_financial_profiles'] ?? [];
if (($evidence['all_pass'] ?? false) !== true || ($evidence['database'] ?? null) !== $actual->db
    || ($evidence['postgres_port'] ?? null) !== $port || ! isset($ids['actor_id'], $ids['customer_id'], $ids['supplier_id'], $ids['branch_id'])) {
    throw new RuntimeException('Missing committed real-action M03/M12/M13 fixture evidence.');
}
$actor = User::query()->whereKey((int) $ids['actor_id'])->where('active', true)->whereNotNull('email_verified_at')->first();
if (! $actor) {
    throw new RuntimeException('Committed financial profile actor is missing or inactive.');
}
$api = app(ReceivablesPayablesApi::class);
$supplier = $api->partyFinancialProfile((int) $actor->id, 'supplier', (int) $ids['supplier_id'], ['branch' => (int) $ids['branch_id']]);
$customer = $api->partyFinancialProfile((int) $actor->id, 'customer', (int) $ids['customer_id'], ['branch' => (int) $ids['branch_id']]);
$customerExport = $api->partyStatementExport((int) $actor->id, 'customer', (int) $ids['customer_id'], ['branch' => (int) $ids['branch_id']]);
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
foreach (['supplier' => [$supplier, $ids['supplier']], 'customer' => [$customer, $ids['customer']]] as $type => [$profile, $expected]) {
    $assert($profile['authorized'] === true, ucfirst($type).' profile did not authorize its scoped read.');
    $invoices = DB::table('rp_'.$type.'_invoices')->where($type.'_id', (int) $ids[$type])->where('branch_id', (int) $ids['branch_id']);
    $openItems = DB::table('rp_open_items')->where('party_type', $type)->where('party_id', (int) $ids[$type])->where('branch_id', (int) $ids['branch_id']);
    $totals = (clone $openItems)->selectRaw('coalesce(sum(settled_amount),0)::numeric(20,2) as settled, coalesce(sum(remaining_amount),0)::numeric(20,2) as outstanding')->first();
    $credits = (string) DB::table('rp_party_credits')->where('party_type', $type)->where('party_id', (int) $ids[$type])->where('branch_id', (int) $ids['branch_id'])->sum('remaining_amount');
    $assert($profile['invoices']['total'] === (clone $invoices)->count(), ucfirst($type).' invoice page total duplicated or omitted persisted invoice rows.');
    $assert($profile['summary']['posted_invoice_count'] === (clone $invoices)->whereNotNull('posted_at')->count(), ucfirst($type).' posted invoice count differs from M12 sources.');
    $assert($profile['summary']['allocated_settlements'] === $totals->settled, ucfirst($type).' allocated settlements differ from M12 open items.');
    $assert($profile['summary']['outstanding'] === $totals->outstanding, ucfirst($type).' outstanding total differs from M12 open items.');
    $assert($profile['summary']['unapplied_credits'] === bcadd($credits, '0', 2), ucfirst($type).' party credits differ from M12 credit balances.');
    $assert($profile['statement']['closing'] === bcsub($totals->outstanding, bcadd($credits, '0', 2), 2), ucfirst($type).' full-range statement does not reconcile to operational open items net of party credits.');
    $assert($profile['summary']['unmapped_opening_payables'] === '0.00', ucfirst($type).' unexpected legacy opening payable exists in the clean isolated fixture.');
}
$supplierOverdue = DB::table('rp_open_items')->where('party_type', 'supplier')->where('party_id', (int) $ids['supplier_id'])
    ->where('branch_id', (int) $ids['branch_id'])->where('due_date', '<', now('Africa/Cairo')->toDateString())->sum('remaining_amount');
$customerAdvance = DB::table('rp_customer_advances')->where('customer_id', (int) $ids['customer_id'])->where('branch_id', (int) $ids['branch_id'])->sum('original_amount');
$assert($supplier['summary']['overdue'] === bcadd((string) $supplierOverdue, '0', 2), 'Supplier overdue total differs from aged M12 open items.');
$assert($customer['summary']['customer_advances'] === bcadd((string) $customerAdvance, '0', 2), 'Customer advance was omitted or double-counted.');
$exportStatement = $customerExport['statement'];
$assert($customerExport['authorized'] === true && count($exportStatement['rows']['items']) === $exportStatement['rows']['total'],
    'Full statement export omitted events beyond the current-page read projection.');
$exportBalance = $exportStatement['opening'];
foreach ($exportStatement['rows']['items'] as $row) {
    $exportBalance = bcadd($exportBalance, bcsub((string) $row->increase, (string) $row->decrease, 2), 2);
}
$assert($exportBalance === $exportStatement['closing'] && $exportStatement['closing'] === $customer['summary']['net_outstanding'],
    'Full statement export rows do not reconcile to the period closing and operational customer balance.');
$assert(class_exists(ZipArchive::class), 'PHP ZipArchive is required to inspect the generated statement workbook.');
auth()->login($actor);
$section = new PartyFinancialSection;
$section->entity = 'customers';
$section->partyId = (int) $ids['customer_id'];
$section->branch = (string) $ids['branch_id'];
$response = $section->exportStatement();
$assert($response instanceof BinaryFileResponse, 'Party statement export did not return a file response.');
$cache = strtolower((string) $response->headers->get('Cache-Control'));
$assert(str_contains($cache, 'private') && str_contains($cache, 'no-store')
    && str_contains(strtolower((string) $response->headers->get('Content-Disposition')), 'attachment'),
    'Party statement download headers are not private, no-store attachment headers: '.json_encode(['cache' => $cache,
        'disposition' => $response->headers->get('Content-Disposition')]));
$zip = new ZipArchive;
$assert($zip->open($response->getFile()->getPathname()) === true, 'Party statement workbook is not a valid XLSX zip.');
$sheet = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
$strings = simplexml_load_string((string) $zip->getFromName('xl/sharedStrings.xml'));
$assert($sheet !== false && $strings !== false, 'Party statement workbook omitted worksheet/shared strings.');
$assert(count($sheet->sheetData->row) === $exportStatement['rows']['total'] + 3,
    'Full-period workbook omitted statement rows, opening balance, closing balance, or header.');
$shared = [];
foreach ($strings->si as $item) {
    $shared[] = (string) $item->t;
}
$lastRow = $sheet->sheetData->row[count($sheet->sheetData->row) - 1];
$closingCell = null;
foreach ($lastRow->c as $cell) {
    if ((string) $cell['r'] === 'E'.(string) $lastRow['r'] && (string) $cell['t'] === 's') {
        $closingCell = $shared[(int) $cell->v] ?? null;
    }
}
$zip->close();
$assert($closingCell === $exportStatement['closing'], 'Workbook final row does not carry the full-period closing balance.');
$assert(DB::table('payable_opening_items')->where('branch_id', (int) $ids['branch_id'])->where('supplier_id', (int) $ids['supplier_id'])->count() === 0,
    'Unexpected synthetic legacy payable opening was found in the acceptance fixture.');
$result = ['status' => 'passed', 'database' => $actual->db, 'port' => $port,
    'supplier' => ['invoice_count' => $supplier['invoices']['total'], 'outstanding' => $supplier['summary']['outstanding'],
        'overdue' => $supplier['summary']['overdue'], 'statement_closing' => $supplier['statement']['closing']],
    'customer' => ['invoice_count' => $customer['invoices']['total'], 'outstanding' => $customer['summary']['outstanding'],
        'advance' => $customer['summary']['customer_advances'], 'statement_closing' => $customer['statement']['closing']],
    'customer_export' => ['statement_rows' => $exportStatement['rows']['total'], 'workbook_rows' => count($sheet->sheetData->row),
        'reconciled_closing' => $exportStatement['closing'], 'cache_control' => $response->headers->get('Cache-Control')],
    'legacy_opening_warning_amount' => ['supplier' => $supplier['summary']['unmapped_opening_payables'], 'customer' => $customer['summary']['unmapped_opening_payables']]];
$resultPath = $root.'/docs/qa/evidence/m03-financial-profile/readback.json';
if (! is_dir(dirname($resultPath))) {
    mkdir(dirname($resultPath), 0770, true);
}
file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

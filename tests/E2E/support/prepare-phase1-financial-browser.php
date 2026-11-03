<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase1-financial-browser-storage';
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create private Phase 1 browser storage.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if ($db->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test'
    || config('database.connections.pgsql.database') !== 'erp_elec_new_test' || config('database.connections.pgsql.host') !== '127.0.0.1'
    || (string) config('database.connections.pgsql.port') !== '5443' || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || $actual->db !== 'erp_elec_new_test' || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || (string) $actual->port !== '5443') {
    throw new RuntimeException('ABORT: browser fixture requires isolated erp_elec_new_test PostgreSQL at 127.0.0.1:5443.');
}
$evidencePath = $root.'/docs/qa/evidence/phase1-financial-concurrency.json';
if (! is_file($evidencePath)) {
    throw new RuntimeException('Missing committed Phase 1 action fixture evidence.');
}
$concurrency = json_decode(file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR);
$fixture = $concurrency['committed_synthetic_fixture_ids'] ?? [];
$actorId = (int) ($fixture['actors'][0] ?? 0);
$branchId = (int) ($fixture['branch_ids'][0] ?? 0);
$actor = User::query()->whereKey($actorId)->where('active', true)->whereNotNull('email_verified_at')->first();
$branch = DB::table('branches')->where('id', $branchId)->first();
if (! $actor || ! $branch || ! DB::table('user_branch_scopes')->where('user_id', $actorId)->where('branch_id', $branchId)->exists()) {
    throw new RuntimeException('Committed financial browser actor/branch fixture is missing or out of scope.');
}
foreach (['M12.supplier_invoice_view', 'M12.customer_invoice_view', 'M11.journal_view'] as $permission) {
    DB::table('access_grants')->insertOrIgnore(['user_id' => $actorId, 'permission_code' => $permission, 'branch_id' => $branchId,
        'created_at' => now(), 'updated_at' => now()]);
}
DB::table('access_grants')->insertOrIgnore(['user_id' => $actorId, 'permission_code' => 'M13.account_view', 'branch_id' => null,
    'created_at' => now(), 'updated_at' => now()]);
$supplierInvoices = DB::table('rp_supplier_invoices')->where('branch_id', $branchId)->whereIn('status', ['posted', 'partially_paid', 'paid', 'credited'])->orderByDesc('id')->limit(8)->get();
$customerInvoice = DB::table('rp_customer_invoices')->where('branch_id', $branchId)->whereIn('status', ['posted', 'partially_collected', 'collected', 'credited'])->orderByDesc('id')->first();
$credit = DB::table('rp_credit_notes')->where('branch_id', $branchId)->where('status', 'posted')->orderByDesc('id')->first();
$movement = DB::table('treasury_movements')->where('branch_id', $branchId)->where('kind', 'transfer_out')
    ->whereNotNull('official_document_id')->orderByDesc('id')->first();
$journal = DB::table('accounting_journals')->where('branch_id', $branchId)->where('status', 'posted')->orderByDesc('id')->first();
if ($supplierInvoices->isEmpty() || ! $customerInvoice || ! $credit || ! $movement || ! $journal) {
    throw new RuntimeException('Persisted M11/M12/M13 browser evidence records are incomplete: '.json_encode(['supplier_count' => $supplierInvoices->count(),
        'customer_invoice' => $customerInvoice?->id, 'credit' => $credit?->id, 'movement' => $movement?->id, 'journal' => $journal?->id,
        'branch' => $branchId], JSON_UNESCAPED_UNICODE));
}
$supplier = $supplierInvoices->first();
$supplierPdf = DB::table('system_official_documents')->where('id', $supplier->official_document_id)->first();
$customerPdf = DB::table('system_official_documents')->where('id', $customerInvoice->official_document_id)->first();
$movementPdf = DB::table('system_official_documents')->where('id', $movement->official_document_id)->first();
if (! $supplierPdf || ! $customerPdf || ! $movementPdf) {
    throw new RuntimeException('Official invoice evidence records are missing.');
}
$denied = User::factory()->create(['name' => 'Phase 1 PDF Authorization Denied Actor', 'active' => true, 'email_verified_at' => now()]);
$denyId = (int) $denied->id;
$out = ['database' => $actual->db, 'host' => '127.0.0.1', 'port' => (int) $actual->port,
    'actor' => ['id' => $actorId, 'email' => $actor->email],
    'denied_actor' => ['id' => $denyId, 'email' => $denied->email],
    'branch' => ['id' => $branchId, 'name' => $branch->name],
    'supplier_invoice' => ['id' => (int) $supplier->id, 'supplier_invoice_number' => $supplier->supplier_invoice_number,
        'official_number' => $supplier->official_number, 'total_amount' => $supplier->total_amount, 'pdf_sha256' => $supplierPdf->sha256],
    'customer_invoice' => ['id' => (int) $customerInvoice->id, 'official_number' => $customerInvoice->official_number,
        'total_amount' => $customerInvoice->total_amount, 'pdf_sha256' => $customerPdf->sha256],
    'credit_note' => ['id' => (int) $credit->id, 'official_number' => $credit->official_number, 'reason' => $credit->reason, 'amount' => $credit->amount],
    'treasury_movement' => ['id' => (int) $movement->id, 'kind' => $movement->kind, 'amount' => $movement->amount],
    'treasury_pdf_sha256' => $movementPdf->sha256,
    'journal_id' => (int) $journal->id,
    'ap_rows' => DB::table('rp_open_items')->where('branch_id', $branchId)->where('party_type', 'supplier')->orderByDesc('id')->limit(8)->get(['source_number', 'original_amount', 'settled_amount', 'credited_amount', 'remaining_amount'])->map(fn ($r) => (array) $r)->all(),
    'ar_rows' => DB::table('rp_open_items')->where('branch_id', $branchId)->where('party_type', 'customer')->orderByDesc('id')->limit(8)->get(['source_number', 'original_amount', 'settled_amount', 'credited_amount', 'remaining_amount'])->map(fn ($r) => (array) $r)->all()];
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

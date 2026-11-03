<?php

declare(strict_types=1);

// Read-only fingerprint of M08 owner history and linked financial ledgers on a
// disposable source or restored PostgreSQL cluster.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$port = $argv[1] ?? '';
if (! in_array($port, ['55441', '55444'], true)) {
    throw new RuntimeException('Use only the disposable source 55441 or restored 55444 PostgreSQL cluster.');
}

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => $port, 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres',
    'DB_PASSWORD' => '', 'DB_URL' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => (int) $port,
    'database.connections.pgsql.database' => 'erp_elec_new_test',
    'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => '',
    'database.connections.pgsql.url' => null,
]);
DB::purge('pgsql');

$actual = DB::selectOne('select current_database() as database, host(inet_server_addr()) as host, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || $actual->database !== 'erp_elec_new_test'
    || $actual->host !== '127.0.0.1' || (string) $actual->port !== $port
    || (string) config('database.connections.pgsql.port') !== $port) {
    throw new RuntimeException('Readback fingerprint refused a database outside its exact loopback test target.');
}

$tables = [
    'maintenance_activities', 'maintenance_assignments', 'maintenance_checklist_results',
    'maintenance_checklist_templates', 'maintenance_commercial_approvals', 'maintenance_completions',
    'maintenance_cost_proposals', 'maintenance_documents', 'maintenance_expense_sources',
    'maintenance_labor_entries', 'maintenance_labor_rates', 'maintenance_labor_recognitions',
    'maintenance_part_issues', 'maintenance_part_requirements', 'maintenance_part_reservations',
    'maintenance_part_returns', 'maintenance_part_usage', 'maintenance_requests', 'maintenance_visits',
    'maintenance_work_orders', 'inventory_balances', 'inventory_documents', 'inventory_document_lines',
    'inventory_fifo_allocations', 'inventory_fifo_layers', 'inventory_movement_journals',
    'inventory_movements', 'inventory_reservation_events', 'inventory_reservations',
    'accounting_activation', 'accounting_journal_lines', 'accounting_journals',
    'accounting_periods', 'accounting_project_dimensions', 'rp_customer_invoice_lines',
    'rp_customer_invoices', 'rp_open_items', 'rp_settlement_allocations', 'rp_supplier_invoice_lines',
    'rp_supplier_invoice_matches', 'rp_supplier_invoices', 'projects', 'project_activities',
    'project_source_links', 'system_official_documents',
];

DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION READ ONLY');
    $available = DB::table('information_schema.tables')->where('table_schema', 'public')->pluck('table_name')->all();
    $missing = array_values(array_diff($tables, $available));
    if ($missing !== []) {
        throw new RuntimeException('Missing owner tables: '.implode(', ', $missing));
    }

    $fingerprints = [];
    foreach ($tables as $table) {
        $rows = DB::table($table)->get()->map(static function (object $row): array {
            $values = (array) $row;
            foreach ($values as $key => $value) {
                if (is_resource($value)) {
                    $values[$key] = base64_encode(stream_get_contents($value));
                }
            }

            return $values;
        })->all();
        usort($rows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($right, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ));
        $serialized = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $fingerprints[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', $serialized)];
    }

    $documents = DB::table('system_official_documents')->orderBy('document_type')->orderBy('official_number')
        ->get(['document_type', 'source_id', 'official_number', 'sha256', 'pdf_base64']);
    $pdfs = [];
    foreach ($documents as $document) {
        $bytes = base64_decode((string) $document->pdf_base64, true);
        if ($bytes === false || hash('sha256', $bytes) !== $document->sha256) {
            throw new RuntimeException('Official private PDF bytes do not match the stored SHA-256 for '.$document->official_number.'.');
        }
        $pdfs[] = [
            'document_type' => $document->document_type,
            'source_id' => $document->source_id,
            'official_number' => $document->official_number,
            'sha256' => $document->sha256,
            'bytes' => strlen($bytes),
        ];
    }

    $reconciliation = DB::selectOne(<<<'SQL'
        SELECT
            (SELECT count(*) FROM maintenance_part_issues) AS maintenance_issues,
            (SELECT COALESCE(sum(amount), 0)::text FROM maintenance_part_issues) AS maintenance_issue_cost,
            (SELECT count(*) FROM maintenance_part_returns) AS maintenance_returns,
            (SELECT COALESCE(sum(amount), 0)::text FROM maintenance_part_returns) AS maintenance_return_value,
            (SELECT count(*) FROM maintenance_labor_entries) AS labor_entries,
            (SELECT COALESCE(sum(cost), 0)::text FROM maintenance_labor_entries) AS labor_cost,
            (SELECT count(*) FROM inventory_movements) AS inventory_movements,
            (SELECT count(*) FROM inventory_fifo_layers) AS fifo_layers,
            (SELECT COALESCE(sum(original_value), 0)::text FROM inventory_fifo_layers) AS fifo_original_value,
            (SELECT COALESCE(sum(remaining_value), 0)::text FROM inventory_fifo_layers) AS fifo_remaining_value,
            (SELECT count(*) FROM accounting_journals WHERE status = 'posted') AS posted_journals,
            (SELECT count(*) FROM accounting_journal_lines) AS journal_lines,
            (SELECT COALESCE(sum(debit), 0)::text FROM accounting_journal_lines) AS journal_debits,
            (SELECT COALESCE(sum(credit), 0)::text FROM accounting_journal_lines) AS journal_credits,
            (SELECT count(*) FROM rp_customer_invoices WHERE maintenance_work_order_id IS NOT NULL) AS maintenance_ar_invoices,
            (SELECT COALESCE(sum(total_amount), 0)::text FROM rp_customer_invoices WHERE maintenance_work_order_id IS NOT NULL) AS maintenance_ar_gross,
            (SELECT count(*) FROM rp_open_items WHERE party_type = 'customer') AS customer_open_items,
            (SELECT COALESCE(sum(remaining_amount), 0)::text FROM rp_open_items WHERE party_type = 'customer') AS customer_open_remaining
    SQL);
    $unbalancedJournals = (int) DB::table('accounting_journals as j')->where('j.status', 'posted')
        ->whereExists(function ($query): void {
            $query->selectRaw('1')->from('accounting_journal_lines as jl')->whereColumn('jl.journal_id', 'j.id')
                ->groupBy('jl.journal_id')->havingRaw('sum(jl.debit) <> sum(jl.credit)');
        })->count();
    if ($unbalancedJournals !== 0 || bccomp((string) $reconciliation->journal_debits, (string) $reconciliation->journal_credits, 2) !== 0) {
        throw new RuntimeException('Restored readback contains unbalanced posted journals.');
    }

    $receipt = [
        'all_pass' => true,
        'database' => $actual->database,
        'host' => $actual->host,
        'port' => (int) $actual->port,
        'schema' => DB::table('migrations')->max('migration'),
        'owner_table_fingerprints' => $fingerprints,
        'owner_reconciliation_totals' => (array) $reconciliation,
        'unbalanced_posted_journals' => $unbalancedJournals,
        'verified_official_private_pdfs' => $pdfs,
        'read_only' => true,
    ];
} finally {
    DB::rollBack();
}

fwrite(STDOUT, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

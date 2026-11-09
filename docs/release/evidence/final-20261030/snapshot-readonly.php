<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Read-only application/database metadata receipt. Never emits connection secrets or row contents.
$phase = $argv[1] ?? '';
if (! in_array($phase, ['before', 'after'], true)) {
    fwrite(STDERR, "Usage: php snapshot-readonly.php before|after\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
if ($connection->getDriverName() !== 'pgsql') {
    throw new RuntimeException('Read-only snapshot requires PostgreSQL.');
}

$database = (string) $connection->getDatabaseName();
$host = (string) config('database.connections.'.config('database.default').'.host', '');
$port = (int) config('database.connections.'.config('database.default').'.port', 0);
$server = $connection->selectOne('select current_database() as database, current_setting(\'server_version\') as version, current_schema() as schema');
$tables = $connection->select("select schemaname, tablename from pg_catalog.pg_tables where schemaname not in ('pg_catalog', 'information_schema') order by schemaname, tablename");
$migrations = Schema::hasTable('migrations')
    ? $connection->table('migrations')->select('migration', 'batch')->orderBy('id')->get()->map(static fn ($row) => ['migration' => $row->migration, 'batch' => (int) $row->batch])->all()
    : [];
$counts = [];
foreach (['companies', 'branches', 'users', 'projects', 'inventory_movements', 'accounting_journals', 'rp_customer_invoices', 'rp_supplier_invoices', 'treasury_movements', 'maintenance_work_orders', 'workshop_jobs'] as $table) {
    if (Schema::hasTable($table)) {
        $counts[$table] = (int) $connection->table($table)->count();
    }
}
$activation = Schema::hasTable('accounting_activation') ? $connection->table('accounting_activation')->where('id', 1)->first(['go_live_date', 'activated_at']) : null;
$administrator = null;
if (Schema::hasTable('roles') && Schema::hasTable('permission_catalog') && Schema::hasTable('role_permissions')) {
    $roleId = $connection->table('roles')->where('code', 'Administrator')->value('id');
    $catalogCount = (int) $connection->table('permission_catalog')->count();
    $assignedCount = $roleId ? (int) $connection->table('role_permissions')->where('role_id', $roleId)->count() : 0;
    $missingCount = $roleId ? (int) $connection->table('permission_catalog as p')->leftJoin('role_permissions as rp', function ($join) use ($roleId) {
        $join->on('rp.permission_code', '=', 'p.code')->where('rp.role_id', '=', $roleId);
    })->whereNull('rp.id')->count() : $catalogCount;
    $administrator = ['role_present' => $roleId !== null, 'registered_permission_keys' => $catalogCount,
        'persisted_administrator_permission_rows' => $assignedCount, 'missing_registered_permission_keys' => $missingCount];
}
$gitStatus = trim((string) shell_exec('git -C '.escapeshellarg($root).' status --porcelain 2>NUL'));
$gitLines = $gitStatus === '' ? [] : preg_split('/\R/', $gitStatus);

$safeScalar = static function (string $sql): mixed {
    try {
        return DB::selectOne($sql)?->value;
    } catch (Throwable) {
        return null;
    }
};
$snapshot = [
    'captured_utc' => gmdate(DATE_ATOM),
    'phase' => $phase,
    'application' => [
        'environment' => app()->environment(),
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
        'timezone' => config('app.timezone'),
        'git_head' => trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>NUL')) ?: null,
        'git_dirty_entry_count' => count($gitLines),
        'git_changed_tracked_count' => count(array_filter($gitLines, static fn (string $line) => ! str_starts_with($line, '??'))),
        'git_untracked_entry_count' => count(array_filter($gitLines, static fn (string $line) => str_starts_with($line, '??'))),
    ],
    'database' => [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => $port,
        'name' => $database,
        'server_version' => $server->version,
        'current_schema' => $server->schema,
        'size_pretty' => $safeScalar('select pg_size_pretty(pg_database_size(current_database())) as value'),
        'relations' => $tables,
        'relation_count' => count($tables),
        'migrations' => $migrations,
    ],
    'selected_record_counts' => $counts,
    'administrator_permission_coverage' => $administrator,
    'activation_snapshot' => [
        'projects' => Schema::hasTable('projects') ? $connection->table('projects')->select('id', 'status', 'version')->orderBy('id')->limit(100)->get()->map(static fn ($row) => ['id' => (int) $row->id, 'status' => $row->status, 'version' => (int) $row->version])->all() : [],
        'accounting' => $activation ? ['go_live_date' => $activation->go_live_date, 'activated' => $activation->activated_at !== null] : null,
    ],
];

$directory = __DIR__;
$path = $directory.DIRECTORY_SEPARATOR.'development-'.$phase.'.json';
file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, basename($path)." written; database={$database}; port={$port}; tables=".count($tables)."\n");

<?php

// Two-process M08 source-conversion and technician-overlap races. Fixtures remain
// isolated on the dedicated Phase 3 PostgreSQL cluster; no development data writes.
use App\Models\User;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$port = getenv('PHASE3_MAINTENANCE_CONCURRENCY_PORT') ?: '55444';
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.$port);
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = $port;
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => (int) $port,
    'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$db = DB::selectOne('select current_database() db, host(inet_server_addr()) address, inet_server_port() port');
if (DB::connection()->getDriverName() !== 'pgsql' || $db->db !== 'erp_elec_new_test' || $db->address !== '127.0.0.1'
    || (string) $db->port !== (string) $port || $port !== '55444') {
    throw new RuntimeException('ABORT before writes: M08 concurrency check requires isolated erp_elec_new_test PostgreSQL on 127.0.0.1:55444.');
}
$need = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
if (! DB::table('companies')->where('id', 1)->exists()) {
    Company::factory()->create();
}
$branch = Branch::factory()->create(['code' => 'M08-RACE-'.strtoupper(Str::random(8))]);
$customer = Customer::factory()->create(['code' => 'M08-RACE-'.strtoupper(Str::random(8)), 'name' => 'Synthetic M08 concurrency customer']);
$dispatcher = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
$technician = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
foreach ([$dispatcher, $technician] as $user) {
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}
foreach (['view', 'request_create', 'request_edit', 'request_approve', 'workorder_manage', 'standalone_create', 'assign', 'schedule_override'] as $permission) {
    DB::table('access_grants')->insert(['user_id' => $dispatcher->id, 'permission_code' => 'M08.'.$permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}
DB::table('access_grants')->insert(['user_id' => $dispatcher->id, 'permission_code' => 'M03.customer_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
DB::table('access_grants')->insert(['user_id' => $technician->id, 'permission_code' => 'M08.visit_record', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
$base = ['branch_id' => $branch->id, 'customer_id' => $customer->id, 'responsible_id' => $technician->id, 'description' => 'Synthetic assigned field maintenance overlap race.', 'priority' => 'normal'];
$actions = app(MaintenanceActions::class);
$request = $actions->saveRequest($dispatcher->id, null, 0, (string) Str::uuid(), $base + ['reported_at' => CarbonImmutable::now('UTC')->subHour()->toIso8601String(), 'preferred_date' => now()->toDateString()]);
$actions->requestState($dispatcher->id, $request, 1, 'submit');
$actions->requestState($dispatcher->id, $request, 2, 'accept');
$workKey = (string) Str::uuid();
$race = static function (array $tasks) use ($root, $port): array {
    $tmp = sys_get_temp_dir().'/m08-race-'.getmypid().'-'.bin2hex(random_bytes(5));
    if (! mkdir($tmp, 0700, true)) {
        throw new RuntimeException('Cannot create isolated M08 worker barrier.');
    }
    $gate = $tmp.'/go';
    $running = [];
    foreach ($tasks as $i => $task) {
        $ready = $tmp.'/ready-'.$i;
        $payload = json_encode($task, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $process = new Process([PHP_BINARY, $root.'/tests/Support/phase3-maintenance-worker.php', $payload, $gate, $ready, (string) $port], $root, null, null, 60);
        $process->start();
        $running[] = [$process, $ready];
    }
    $deadline = microtime(true) + 30;
    foreach ($running as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
    }
    foreach ($running as [, $ready]) {
        if (! is_file($ready)) {
            throw new RuntimeException('M08 workers failed to reach the PostgreSQL race barrier.');
        }
    }
    $readyRows = array_map(fn ($p) => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR), array_column($running, 1));
    if (count(array_unique(array_column($readyRows, 'pg_backend_pid'))) !== count($tasks)) {
        throw new RuntimeException('M08 race workers did not hold independent PostgreSQL connections.');
    }
    file_put_contents($gate, 'go', LOCK_EX);
    $out = [];
    foreach ($running as [$process]) {
        $process->wait();
        $out[] = ['exit_code' => $process->getExitCode(), 'stdout' => trim($process->getOutput()), 'stderr' => trim($process->getErrorOutput())];
    }
    foreach (glob($tmp.'/*') ?: [] as $file) {
        @unlink($file);
    } @rmdir($tmp);

    return ['ready' => $readyRows, 'workers' => $out];
};
$conversion = $race(array_fill(0, 2, ['operation' => 'convert', 'actor' => (int) $dispatcher->id, 'request' => (int) $request, 'version' => 3, 'key' => $workKey, 'input' => $base + ['instructions' => 'Inspect the panel and document the repair.', 'billable' => false, 'acknowledgment_required' => true]]));
$conversionIds = [];
foreach ($conversion['workers'] as $worker) {
    $data = json_decode($worker['stdout'], true);
    if (($data['status'] ?? null) === 'PASS') {
        $conversionIds[] = (int) $data['work_order_id'];
    }
}
$need(count($conversionIds) === 2 && $conversionIds[0] === $conversionIds[1]
    && DB::table('maintenance_work_orders')->where('creation_key', $workKey)->count() === 1
    && DB::table('maintenance_requests')->where('id', $request)->where('status', 'work_order_created')->where('version', 4)->exists(),
    'two independent conversions of one accepted request and creation key produce one work order');
$workA = $actions->createWorkOrder($dispatcher->id, null, 0, (string) Str::uuid(), $base + ['instructions' => 'Inspect the first site.']);
$workB = $actions->createWorkOrder($dispatcher->id, null, 0, (string) Str::uuid(), $base + ['instructions' => 'Inspect the second site.']);
$interval = ['starts_at' => CarbonImmutable::now('UTC')->addHours(3)->toIso8601String(), 'ends_at' => CarbonImmutable::now('UTC')->addHours(4)->toIso8601String()];
$assignment = $race([['operation' => 'assign', 'actor' => (int) $dispatcher->id, 'work' => $workA, 'technician' => (int) $technician->id, 'input' => $interval],
    ['operation' => 'assign', 'actor' => (int) $dispatcher->id, 'work' => $workB, 'technician' => (int) $technician->id, 'input' => $interval]]);
$successes = count(array_filter($assignment['workers'], fn ($w) => $w['exit_code'] === 0));
$conflicts = count(array_filter($assignment['workers'], fn ($w) => $w['exit_code'] === 2 && str_contains($w['stdout'], '"status":"REJECTED","code":422')));
$overlaps = DB::table('maintenance_assignments')->where('technician_id', $technician->id)->where('active', true)
    ->where('starts_at', '<', $interval['ends_at'])->where('ends_at', '>', $interval['starts_at'])->count();
$need($successes === 1 && $conflicts === 1 && $overlaps === 1,'two-process overlapping assignments for one technician admit exactly one active assignment');
echo 'RESULT all_pass=true assertions=2 database='.$db->db.' port='.$db->port.PHP_EOL;

<?php

use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Projects\Application\TenderActions;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$expectedPort = (int) (getenv('TENDER_AUDIT_PORT') ?: 55481);
if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || (int) DB::connection()->getConfig('port') !== $expectedPort || $expectedPort !== 55481) {
    throw new RuntimeException('Tender race checks require the guarded disposable PostgreSQL clone on port 55481.');
}

$root = dirname(__DIR__, 4);
$worker = __DIR__.'/tender-creation-race-worker.php';
$stamp = strtoupper(bin2hex(random_bytes(5)));
$raceRoot = $root.'/.tmp/tender-creation-race-'.$stamp;
if (! is_dir($raceRoot) && ! mkdir($raceRoot, 0770, true) && ! is_dir($raceRoot)) {
    throw new RuntimeException('Unable to create this race check’s unique fixture directory.');
}
$receipt = __DIR__.'/tender-creation-race-'.$stamp.'.txt';
$verify = static function (bool $condition, string $message) use ($receipt): void {
    $line = ($condition ? 'PASS ' : 'FAIL ').$message.PHP_EOL;
    file_put_contents($receipt, $line, FILE_APPEND | LOCK_EX);
    echo $line;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

if (! DB::table('companies')->where('id', 1)->exists()) {
    Company::factory()->create();
}
$branch = Branch::factory()->create(['company_id' => 1, 'code' => 'TN-RACE-'.$stamp, 'name' => 'فرع اختبار تزامن المناقصات']);
$actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
$adminRole = DB::table('roles')->where('code', 'Administrator')->first();
if (! $adminRole) {
    throw new RuntimeException('Administrator role is required for the disposable race actor.');
}
DB::table('user_roles')->insert(['user_id' => $actor->id, 'role_id' => $adminRole->id, 'created_at' => now(), 'updated_at' => now()]);
DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
config(['release.tenders_enabled' => true]);

$input = [
    'external_reference' => 'TN-RACE-'.$stamp,
    'title' => 'مناقصة اختبار إعادة الإرسال المتزامن',
    'description' => 'سجل تجريبي لاختبار عاملين على عملية الحفظ نفسها.',
    'issuer' => 'الجهة التجريبية لاختبار التزامن',
    'customer_id' => null,
    'category' => 'supply',
    'branch_id' => (int) $branch->id,
    'responsible_id' => (int) $actor->id,
    'published_on' => now('Africa/Cairo')->toDateString(),
    'requirements' => 'اختبار حفظ متزامن بمفتاح ثابت.',
    'deadlines' => ['site_visit_at' => null, 'clarification_at' => null, 'technical_at' => null, 'financial_at' => null,
        'final_at' => now('UTC')->addDays(20)->toIso8601String(), 'opening_at' => null],
];
$key = Uuid::uuid4()->toString();
$configPath = $raceRoot.'/fixture.json';
mkdir($raceRoot.'/storage', 0770, true);
mkdir($raceRoot.'/private', 0770, true);
file_put_contents($configPath, json_encode([
    'actor_id' => (int) $actor->id, 'branch_id' => (int) $branch->id, 'key' => $key, 'input' => $input,
    'storage_root' => $raceRoot.'/storage', 'private_disk' => $raceRoot.'/private',
    'operation' => 'save', 'tender_id' => 0, 'version' => 0, 'payload' => $input,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
$before = DB::table('project_tenders')->count();
$gate = $raceRoot.'/go';
$processes = [];
for ($i = 0; $i < 2; $i++) {
    $process = new Process([PHP_BINARY, __DIR__.'/tender-action-race-worker.php', $configPath, $configPath, $raceRoot, (string) $i], $root, [
        'APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55481', 'DB_DATABASE' => 'erp_elec_new_test',
        'DB_USERNAME' => 'erp_app', 'DB_PASSWORD' => '', 'TENDER_AUDIT_PORT' => '55481',
    ], null, 60);
    $process->start();
    $processes[$i] = $process;
}

$readyDeadline = microtime(true) + 25;
while (microtime(true) < $readyDeadline && (! file_exists($raceRoot.'/ready-0') || ! file_exists($raceRoot.'/ready-1'))) {
    foreach ($processes as $index => $process) {
        if (! $process->isRunning()) {
            throw new RuntimeException('Tender race worker exited before the common gate: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }
    }
    usleep(50000);
}
$verify(file_exists($raceRoot.'/ready-0') && file_exists($raceRoot.'/ready-1'), 'Two independent PHP/PostgreSQL workers reached the shared creation barrier');
file_put_contents($gate, 'go', LOCK_EX);
$results = [];
foreach ($processes as $index => $process) {
    $process->wait();
    if (! $process->isSuccessful()) {
        throw new RuntimeException('Tender race worker '.$index.' failed: '.trim($process->getErrorOutput().' '.$process->getOutput()));
    }
    $results[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
}

$rows = DB::table('project_tenders')->where('creation_key', $key)->get();
$events = DB::table('project_tender_events')->whereIn('tender_id', $rows->pluck('id'))->where('action', 'tender.created')->count();
$tenderIds = array_values(array_unique(array_map(static fn (array $result): int => (int) ($result['tender_id'] ?? 0), $results)));
$verify(count($results) === 2 && count($tenderIds) === 1 && $tenderIds[0] > 0
    && $rows->count() === 1 && (int) $rows[0]->id === $tenderIds[0] && $events === 1
    && DB::table('project_tenders')->count() === $before + 1,
    'Concurrent exact creation-key retries return the same one tender and produce one creation event: '.json_encode(['workers' => $results, 'rows' => $rows->count(), 'events' => $events], JSON_UNESCAPED_UNICODE));

file_put_contents($receipt, 'Committed isolated fixture IDs: tender='.$rows[0]->id.' branch='.$branch->id.' actor='.$actor->id.PHP_EOL, FILE_APPEND | LOCK_EX);
echo "Receipt={$receipt}\n";

<?php

use App\Modules\Projects\Application\ProjectActions;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Application\TenderAwards;
use App\Modules\Projects\Application\TenderDocuments;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
    throw new RuntimeException('Tender multi-race checks require the guarded disposable PostgreSQL clone on port 55481.');
}

$root = dirname(__DIR__, 4);
$stamp = strtoupper(bin2hex(random_bytes(5)));
$raceRoot = $root.'/.tmp/tender-multirace-'.$stamp;
if (! is_dir($raceRoot) && ! mkdir($raceRoot, 0770, true) && ! is_dir($raceRoot)) {
    throw new RuntimeException('Unable to create isolated tender race fixture directory.');
}
$fixturePath = $raceRoot.'/fixture.json';
$receipt = __DIR__.'/tender-multirace-'.$stamp.'.txt';
$verify = static function (bool $condition, string $message) use ($receipt): void {
    $line = ($condition ? 'PASS ' : 'FAIL ').$message.PHP_EOL;
    file_put_contents($receipt, $line, FILE_APPEND | LOCK_EX);
    echo $line;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$phpEnv = ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55481', 'DB_DATABASE' => 'erp_elec_new_test',
    'DB_USERNAME' => 'erp_app', 'DB_PASSWORD' => '', 'TENDER_AUDIT_PORT' => '55481'];
$prepare = new Process([PHP_BINARY, __DIR__.'/check-tender-actions.php'], $root, $phpEnv + [
    'TENDER_AUDIT_PREPARE_RACE' => '1', 'TENDER_AUDIT_RACE_FIXTURE' => $fixturePath,
], null, 180);
$prepare->run();
file_put_contents($receipt, $prepare->getOutput().$prepare->getErrorOutput(), FILE_APPEND | LOCK_EX);
if (! $prepare->isSuccessful() || ! is_file($fixturePath)) {
    throw new RuntimeException('Could not prepare the real Tender submission fixture: '.mb_substr(trim($prepare->getOutput().' '.$prepare->getErrorOutput()), 0, 2200));
}
$fixture = json_decode(file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
app()->useStoragePath($fixture['storage_root']);
config(['filesystems.disks.local.root' => $fixture['private_disk'], 'release.tenders_enabled' => true]);
Storage::forgetDisk('local');

$race = static function (string $operation, array $task) use ($root, $raceRoot, $fixturePath, $phpEnv): array {
    $directory = $raceRoot.'/'.$operation.'-'.bin2hex(random_bytes(3));
    if (! mkdir($directory, 0770, true)) {
        throw new RuntimeException('Unable to create race barrier directory.');
    }
    $taskPath = $directory.'/task.json';
    file_put_contents($taskPath, json_encode($task, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $p = new Process([PHP_BINARY, __DIR__.'/tender-action-race-worker.php', $fixturePath, $taskPath, $directory, (string) $i], $root, $phpEnv, null, 90);
        $p->start();
        $workers[$i] = $p;
    }
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline && (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1'))) {
        foreach ($workers as $p) {
            if (! $p->isRunning()) {
                throw new RuntimeException('Tender worker exited before the barrier: '.trim($p->getErrorOutput().' '.$p->getOutput()));
            }
        }
        usleep(25000);
    }
    if (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
        throw new RuntimeException('Two tender workers did not reach the shared start barrier.');
    }
    file_put_contents($directory.'/go', 'go', LOCK_EX);
    $results = [];
    foreach ($workers as $p) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('Tender worker process failed: '.trim($p->getErrorOutput().' '.$p->getOutput()));
        }
        $results[] = json_decode(trim($p->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    return $results;
};

$tenderId = (int) $fixture['tender_id'];
$actorId = (int) $fixture['actor_id'];
$checkerId = (int) $fixture['checker_id'];
$submissionResults = $race('submit', [
    'operation' => 'submit', 'actor_id' => $actorId, 'tender_id' => $tenderId, 'version' => $fixture['version'],
    'key' => $fixture['submission_key'], 'payload' => $fixture['submission_payload'],
]);
$submissionIds = array_values(array_unique(array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), array_filter($submissionResults, static fn (array $r): bool => $r['outcome'] === 'passed'))));
$verify(count($submissionIds) === 1 && count(array_filter($submissionResults, static fn (array $r): bool => $r['outcome'] === 'passed')) === 2
    && DB::table('project_tender_submissions')->where('tender_id', $tenderId)->count() === 1
    && DB::table('project_tenders')->where('id', $tenderId)->where('submitted_id', $submissionIds[0])->exists(),
    'Two independent workers submitting the same tender key and immutable payload return one submission: '.json_encode($submissionResults));

$current = DB::table('project_tenders')->find($tenderId);
$awardEvidence = app(TenderDocuments::class)->upload($actorId, $tenderId, (int) $current->version, \Ramsey\Uuid\Uuid::uuid4()->toString(),
    'دليل نتيجة العطاء المتزامن', 'award', UploadedFile::fake()->create('award.pdf', 2, 'application/pdf'));
$current = DB::table('project_tenders')->find($tenderId);
$financial = DB::table('project_tender_financial')->find($current->financial_id);
$awardPayload = ['outcome' => 'awarded', 'decided_on' => now('Africa/Cairo')->toDateString(), 'reference' => 'قرار ترسية متزامن موثق',
    'evidence_id' => $awardEvidence, 'award_amount' => (string) $financial->total];
$awardRace = $race('award', ['operation' => 'award', 'actor_id' => $actorId, 'tender_id' => $tenderId, 'version' => (int) $current->version, 'payload' => $awardPayload]);
$awardWinners = array_filter($awardRace, static fn (array $r): bool => $r['outcome'] === 'passed');
$awardLosers = array_filter($awardRace, static fn (array $r): bool => $r['outcome'] === 'rejected' && $r['status'] === 409);
$awardResult = DB::table('project_tender_results')->where('tender_id', $tenderId)->first();
$verify(count($awardWinners) === 1 && count($awardLosers) === 1 && $awardResult !== null
    && DB::table('project_tender_results')->where('tender_id', $tenderId)->count() === 1
    && DB::table('project_tenders')->where('id', $tenderId)->where('status', 'awarded')->exists(),
    'Two award-result workers produce one immutable result and one stale-version 409: '.json_encode($awardRace));

$buildDestination = static function (array $fixture, string $suffix): array {
    return ['project' => ['code' => 'TN-RC-'.$suffix, 'name' => 'مشروع ترسية متزامن', 'manager_id' => (int) $fixture['actor_id'],
        'start_date' => now('Africa/Cairo')->addDays(1)->toDateString(), 'end_date' => now('Africa/Cairo')->addDays(60)->toDateString(),
        'description' => 'تحويل تجريبي آمن من مناقصة مرساة.', 'members' => []],
        'quotation' => ['date' => '2027-01-02', 'valid_until' => '2027-01-31', 'reference' => 'تعيين مسودة متزامنة',
            'branch_id' => (int) $fixture['branch_id'], 'customer_id' => (int) $fixture['customer_id'],
            'lines' => [['product_id' => (int) $fixture['product_id'], 'unit_id' => (int) $fixture['unit_id']]]]];
};

$current = DB::table('project_tenders')->find($tenderId);
$failurePlan = app(TenderAwards::class)->plan($actorId, $tenderId, (int) $current->version, [
    'result_id' => (int) $awardResult->id, 'reference' => 'خطة فشل الترحيل المتزامن', 'destinations' => $buildDestination($fixture, 'FAIL-'.$stamp),
]);
$current = DB::table('project_tenders')->find($tenderId);
app(TenderAwards::class)->approve($checkerId, $tenderId, (int) $current->version, $failurePlan, 'اعتماد خطة فشل تجريبية مستقلة');
$current = DB::table('project_tenders')->find($tenderId);
$failureCounts = [
    'projects' => DB::table('projects')->count(), 'quotations' => DB::table('sales_quotations')->count(),
    'sources' => DB::table('sales_tender_sources')->count(), 'handoffs' => DB::table('project_tender_handoffs')->count(),
    'audits' => DB::table('audit_entries')->count(),
];
DB::unprepared("CREATE FUNCTION codex_tender_handoff_failure() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.tender_id = {$tenderId} AND NEW.destination = 'quotation' THEN RAISE EXCEPTION 'intentional tender handoff acceptance failure'; END IF; RETURN NEW; END \$\$");
DB::unprepared('CREATE TRIGGER codex_tender_handoff_failure BEFORE INSERT ON project_tender_handoffs FOR EACH ROW EXECUTE FUNCTION codex_tender_handoff_failure()');
$injectedFailure = false;
try {
    app(TenderAwards::class)->convert($actorId, $tenderId, (int) $current->version, $failurePlan);
} catch (Throwable $e) {
    $injectedFailure = str_contains($e->getMessage(), 'intentional tender handoff acceptance failure');
} finally {
    DB::unprepared('DROP TRIGGER IF EXISTS codex_tender_handoff_failure ON project_tender_handoffs');
    DB::unprepared('DROP FUNCTION IF EXISTS codex_tender_handoff_failure()');
}
$verify($injectedFailure && $failureCounts === [
    'projects' => DB::table('projects')->count(), 'quotations' => DB::table('sales_quotations')->count(),
    'sources' => DB::table('sales_tender_sources')->count(), 'handoffs' => DB::table('project_tender_handoffs')->count(),
    'audits' => DB::table('audit_entries')->count(),
], 'Injected failure after project and M04 quotation draft creation rolls both owners, handoff rows and audits back atomically');

$current = DB::table('project_tenders')->find($tenderId);
$validPlan = app(TenderAwards::class)->plan($actorId, $tenderId, (int) $current->version, [
    'result_id' => (int) $awardResult->id, 'reference' => 'خطة تحويل صحيحة للسباق', 'destinations' => $buildDestination($fixture, $stamp),
]);
$current = DB::table('project_tenders')->find($tenderId);
app(TenderAwards::class)->approve($checkerId, $tenderId, (int) $current->version, $validPlan, 'اعتماد خطة التحويل المتزامن مستقلاً');
$current = DB::table('project_tenders')->find($tenderId);
$convertRace = $race('convert', ['operation' => 'convert', 'actor_id' => $actorId, 'tender_id' => $tenderId,
    'version' => (int) $current->version, 'plan_id' => $validPlan]);
$convertWinners = array_filter($convertRace, static fn (array $r): bool => $r['outcome'] === 'passed');
$convertLosers = array_filter($convertRace, static fn (array $r): bool => $r['outcome'] === 'rejected' && $r['status'] === 409);
$handoffs = DB::table('project_tender_handoffs')->where('tender_id', $tenderId)->where('plan_id', $validPlan)->get();
$verify(count($convertWinners) === 1 && count($convertLosers) === 1 && $handoffs->count() === 2
    && $handoffs->pluck('destination')->sort()->values()->all() === ['project', 'quotation']
    && DB::table('sales_tender_sources')->where('tender_id', $tenderId)->count() === 1,
    'Concurrent handoff conversion commits exactly one project and one ordinary M04 draft; the second worker is rejected: '.json_encode($convertRace));

$receiptPath = __DIR__.'/tender-multirace-'.$stamp.'.txt';
file_put_contents($receiptPath, 'Committed isolated fixture IDs: tender='.$tenderId.' branch='.$fixture['branch_id'].' actor='.$actorId.' checker='.$checkerId.PHP_EOL, FILE_APPEND | LOCK_EX);
echo "Receipt={$receiptPath}\n";

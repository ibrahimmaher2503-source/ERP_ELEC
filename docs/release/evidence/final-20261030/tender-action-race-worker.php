<?php

use App\Modules\Projects\Application\TenderAwards;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Application\TenderBids;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$fixture = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$task = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$barrier = $argv[3];
$workerId = $argv[4];
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || (int) DB::connection()->getConfig('port') !== 55481) {
    throw new RuntimeException('Tender race worker refused a non-disposable database target.');
}
app()->useStoragePath($fixture['storage_root']);
config(['filesystems.disks.local.root' => $fixture['private_disk'], 'release.tenders_enabled' => true]);
Storage::forgetDisk('local');
file_put_contents($barrier.'/ready-'.$workerId, 'ready', LOCK_EX);
$deadline = microtime(true) + 30;
while (! file_exists($barrier.'/go') && microtime(true) < $deadline) {
    usleep(20000);
}
if (! file_exists($barrier.'/go')) {
    throw new RuntimeException('Tender race worker timed out waiting for the shared start gate.');
}

try {
    $result = match ($task['operation']) {
        'save' => ['outcome' => 'passed', 'tender_id' => app(TenderActions::class)->save(
            (int) $task['actor_id'], null, 0, $task['key'], $task['payload']),
        ],
        'submit' => ['outcome' => 'passed', 'id' => app(TenderBids::class)->submit(
            (int) $task['actor_id'], (int) $task['tender_id'], (int) $task['version'], $task['key'], $task['payload']),
        ],
        'award' => ['outcome' => 'passed', 'id' => app(TenderAwards::class)->result(
            (int) $task['actor_id'], (int) $task['tender_id'], (int) $task['version'], $task['payload']),
        ],
        'convert' => ['outcome' => 'passed', 'targets' => app(TenderAwards::class)->convert(
            (int) $task['actor_id'], (int) $task['tender_id'], (int) $task['version'], (int) $task['plan_id']),
        ],
        default => throw new RuntimeException('Unsupported tender race operation.'),
    };
} catch (HttpExceptionInterface $e) {
    $result = ['outcome' => 'rejected', 'status' => $e->getStatusCode()];
} catch (Throwable $e) {
    $result = ['outcome' => 'error', 'class' => $e::class, 'message' => $e->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;

<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Inventory\Application\MaintenanceStock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=pgsql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=55441');
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
putenv('DB_URL=');

$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = '55441';
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
    'database.connections.pgsql.port' => 55441,
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
    || (int) $connection->port !== 55441) {
    throw new RuntimeException('Abort before reads: expected isolated loopback PostgreSQL erp_elec_new_test on port 55441.');
}

$assertions = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    $assertions[] = $message;
    fwrite(STDOUT, "PASS {$message}\n");
};

DB::beginTransaction();
try {
    $actor = 641;
    $branch = (int) DB::table('maintenance_work_orders')->where('id', 111)->value('branch_id');
    $return = DB::table('inventory_movements')->find(185);
    $issue = DB::table('inventory_movements')->find(184);

    if (! $return || ! $issue || $return->operation !== 'maintenance_return' || $issue->operation !== 'maintenance_issue') {
        throw new RuntimeException('Expected previously accepted maintenance issue and return evidence is missing.');
    }

    $originalJournal = DB::table('inventory_movement_journals')->where('movement_id', $issue->id)->value('journal_id');
    $expectedProject = (int) DB::table('accounting_project_dimensions')->where('journal_id', $originalJournal)->value('project_id');
    $assert($expectedProject === 81, 'fixture confirms the original posted issue has its frozen M07 project dimension');

    $source = app(MaintenanceStock::class)->financialSource($actor, $return);
    $assert($source['project_id'] === $expectedProject, 'maintenance return obtains its project through the Accounting owner contract');

    try {
        app(AccountingApi::class)->inventorySourceProject($actor, 99999999, $branch);
        throw new RuntimeException('Missing posted inventory source was unexpectedly accepted.');
    } catch (HttpExceptionInterface $exception) {
        $assert($exception->getStatusCode() === 409, 'missing posted source journal is denied with conflict');
    }

    try {
        app(AccountingApi::class)->inventorySourceProject($actor, $issue->id, $branch + 1000000);
        throw new RuntimeException('Wrong-branch inventory source was unexpectedly accepted.');
    } catch (HttpExceptionInterface $exception) {
        $assert($exception->getStatusCode() === 403, 'unavailable branch source is denied by Accounting scope');
    }
} finally {
    DB::rollBack();
}

fwrite(STDOUT, 'RESULT all_pass=true assertions='.count($assertions).' database='.$connection->database.' port='.$connection->port.' transaction_rolled_back=true'.PHP_EOL);

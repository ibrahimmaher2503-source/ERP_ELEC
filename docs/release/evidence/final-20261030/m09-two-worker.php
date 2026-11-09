<?php

declare(strict_types=1);

use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesService;
use App\Modules\WorkshopRepairs\Application\WorkshopFinance;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use App\Modules\WorkshopRepairs\Application\WorkshopParts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = 'C:/projects/ERP_ELEC_NEW';
$auditPort = (int) (getenv('M09_AUDIT_PORT') ?: '55462');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
Carbon::setTestNow(CarbonImmutable::now('Africa/Cairo')->addYear()->startOfDay()->subDay()->addHours(12));

$identity = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || $identity->db !== 'erp_elec_new_test'
    || ! in_array($identity->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $identity->port !== $auditPort
    || ! DB::table('migrations')->where('migration', '2026_10_31_195000_link_company_repair_inventory_identity')->exists()) {
    throw new RuntimeException('Worker abort: isolated PostgreSQL target guard failed.');
}

$action = $argv[1] ?? '';
$actor = $argv[2] ?? 0;
$job = $argv[3] ?? 0;
$key = $argv[4] ?? '';
$amountOrDate = $argv[5] ?? '';
$dueOrReservation = $argv[6] ?? '';
$quantity = $argv[7] ?? '';
$actor = (int) $actor;
$job = (int) $job;
if ($action === 'issue') {
    $reservation = (int) $amountOrDate;
    $result = app(WorkshopParts::class)->issue($actor, $job, $reservation, (string) $dueOrReservation, (string) $key);
    echo json_encode(['action' => $action, 'movement_id' => $result['movement_id'], 'journal_id' => $result['journal_id']], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if ($action === 'invoice') {
    $id = app(WorkshopFinance::class)->requestInvoice($actor, $job, (string) $key, (string) $amountOrDate, (string) $dueOrReservation);
    echo json_encode(['action' => $action, 'invoice_id' => $id, 'invoice_status' => DB::table('rp_customer_invoices')->where('id', $id)->value('status')], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if ($action === 'verify') {
    try {
        app(WorkshopExecution::class)->verify($actor, $job, (int) $key, (string) $amountOrDate, (string) $dueOrReservation);
        echo json_encode(['action' => $action, 'result' => 'verified'], JSON_THROW_ON_ERROR)."\n";
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
        if ($error->getStatusCode() !== 409) {
            throw $error;
        }
        echo json_encode(['action' => $action, 'result' => 'conflict', 'status' => 409], JSON_THROW_ON_ERROR)."\n";
    }
    exit(0);
}
if ($action === 'deliver') {
    try {
        $payload = base64_decode((string) $dueOrReservation, true);
        if ($payload === false) {
            throw new InvalidArgumentException('Delivery fixture payload is not valid base64.');
        }
        $deliveryId = app(WorkshopExecution::class)->deliver($actor, $job, (int) $amountOrDate, (string) $key, json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
        echo json_encode(['action' => $action, 'result' => 'delivered', 'delivery_id' => $deliveryId], JSON_THROW_ON_ERROR)."\n";
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
        if ($error->getStatusCode() !== 409) {
            throw $error;
        }
        echo json_encode(['action' => $action, 'result' => 'conflict', 'status' => 409], JSON_THROW_ON_ERROR)."\n";
    }
    exit(0);
}
throw new InvalidArgumentException('Unsupported worker action.');

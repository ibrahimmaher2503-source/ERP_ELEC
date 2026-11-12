<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
$port = getenv('PHASE3_FINANCIAL_PORT') ?: '55441';
if ($port !== '55441') {
    throw new RuntimeException('ABORT before reads: M08 concurrency checks run only on disposable port 55441.');
}
$evidencePath = $root.'/docs/qa/evidence/phase3-maintenance-financial.json';
$evidence = json_decode(file_get_contents($evidencePath), true, 512, JSON_THROW_ON_ERROR);
if (($evidence['all_pass'] ?? false) !== true || ($evidence['fixture_committed'] ?? false) !== true || ($evidence['postgres_port'] ?? null) !== 55441) {
    throw new RuntimeException('ABORT: first run financial check with PHASE3_FINANCIAL_COMMIT_FIXTURES=1 on 55441.');
}
$f = $evidence['concurrency_fixture'] ?? [];
foreach (['actor_id', 'maker_id', 'tech_id', 'work_order_id', 'requirement_id', 'reservation_id', 'reservation2_id', 'issue_key', 'quantity', 'posting_date', 'assignment_id', 'work_version', 'main_work_order_id', 'main_work_version', 'invoice_id', 'invoice_key', 'invoice_date', 'invoice_due', 'warehouse_id', 'product_id'] as $key) {
    if (! array_key_exists($key, $f)) {
        throw new RuntimeException('Committed concurrency fixture missing '.$key);
    }
}
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=pgsql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.$port);
putenv('DB_DATABASE=erp_elec_new_test');
putenv('DB_USERNAME=postgres');
putenv('DB_PASSWORD=');
putenv('DB_URL=');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = $_SERVER['DB_PORT'] = $port;
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
$_ENV['DB_USERNAME'] = $_SERVER['DB_USERNAME'] = 'postgres';
$_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = '';
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => '127.0.0.1', 'database.connections.pgsql.port' => 55441, 'database.connections.pgsql.database' => 'erp_elec_new_test', 'database.connections.pgsql.username' => 'postgres', 'database.connections.pgsql.password' => '', 'database.connections.pgsql.url' => null]);
DB::purge('pgsql');
$conn = DB::selectOne('select current_database() db, inet_server_addr()::text address, inet_server_port() port');
if ($conn->db !== 'erp_elec_new_test' || ! in_array($conn->address, ['127.0.0.1', '127.0.0.1/32'], true) || (int) $conn->port !== 55441) {
    throw new RuntimeException('ABORT before race: wrong database.');
}
$need = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};
$rootTmp = $root.'/.tmp/phase3-races-'.getmypid().'-'.bin2hex(random_bytes(4));
if (! mkdir($rootTmp, 0700, true)) {
    throw new RuntimeException('Cannot create local race barrier under workspace .tmp.');
}
$env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55441', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_USERNAME' => 'postgres', 'DB_PASSWORD' => '', 'PHASE3_FINANCIAL_PORT' => '55441'];
$counter = 0;
$race = static function (string $mode, array $payloads) use ($root, $rootTmp, $env, &$counter): array {
    $gate = $rootTmp.'/go-'.(++$counter);
    $work = [];
    foreach ($payloads as $i => $payload) {
        $ready = $rootTmp.'/ready-'.$counter.'-'.$i;
        $process = new Process([PHP_BINARY, $root.'/tests/Support/phase3-maintenance-issue-worker.php', $mode, json_encode($payload, JSON_THROW_ON_ERROR), $gate, $ready], $root, $env, null, 60);
        $process->start();
        $work[] = [$process, $ready];
    }
    $deadline = microtime(true) + 35;
    foreach ($work as [, $ready]) {
        while (! is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
    }
    foreach ($work as [, $ready]) {
        if (! is_file($ready)) {
            throw new RuntimeException($mode.' workers failed to reach synchronized start.');
        }
    }
    file_put_contents($gate, 'go', LOCK_EX);
    $out = [];
    foreach ($work as [$process]) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($mode.' worker process failed: '.$process->getErrorOutput().' '.$process->getOutput());
        }$out[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
};
$postDate = $f['posting_date'];
$uuid = static fn (): string => (string) Str::uuid();
try {
    $spareKeys = [$uuid(), $uuid()];
    $spare = $race('reserve', array_map(fn ($key) => ['actor' => $f['actor_id'], 'work' => $f['work_order_id'], 'requirement' => $f['requirement_id'], 'quantity' => '3.00000000', 'key' => $key, 'posting_date' => $postDate], $spareKeys));
    $need(count(array_filter($spare, fn ($r) => $r['status'] === 'accepted')) === 2, 'Two same-warehouse reservation requests should both persist, allowing partial availability: '.json_encode($spare));
    $spareRows = DB::table('inventory_reservations')->whereIn('idempotency_key', $spareKeys)->get();
    $balance = DB::table('inventory_balances')->where('warehouse_id', $f['warehouse_id'])->where('product_id', $f['product_id'])->first();
    $spareReserved = (string) $spareRows->sum('reserved');
    $need($spareRows->count() === 2 && bccomp($balance->reserved, $balance->on_hand, 8) <= 0 && bccomp($spareReserved, '2.00000000', 8) <= 0, 'Concurrent spare reservations never exceed finite available stock or balance on-hand.');

    $same = $race('issue', array_fill(0, 2, ['actor' => $f['actor_id'], 'work' => $f['work_order_id'], 'reservation' => $f['reservation_id'], 'quantity' => $f['quantity'], 'key' => $f['issue_key'], 'posting_date' => $postDate]));
    $need($same[0]['status'] === 'accepted' && $same[1]['status'] === 'accepted' && $same[0]['result']['movement_id'] === $same[1]['result']['movement_id'], 'Same-key workers converge to one successful FIFO issue movement.');
    $sameCount = DB::table('maintenance_part_issues')->where('issue_key', $f['issue_key'])->count();
    $sameMovement = DB::table('inventory_movements')->where('idempotency_key', 'reservation:'.$f['issue_key'])->count();
    $need($sameCount === 1 && $sameMovement === 1, 'Same-key issue race persists one source row and one movement.');

    $distinctKeys = [$uuid(), $uuid()];
    $distinct = $race('issue', array_map(fn ($key) => ['actor' => $f['actor_id'], 'work' => $f['work_order_id'], 'reservation' => $f['reservation2_id'], 'quantity' => $f['quantity'], 'key' => $key, 'posting_date' => $postDate], $distinctKeys));
    $need(count(array_filter($distinct, fn ($r) => $r['status'] === 'accepted')) === 1 && count(array_filter($distinct, fn ($r) => $r['status'] === 'rejected' && ($r['http_status'] ?? 0) === 422)) === 1, 'Distinct-key issues competing for one outstanding reserved unit allow exactly one effect.');
    $distinctRows = DB::table('maintenance_part_issues')->whereIn('issue_key', $distinctKeys)->count();
    $need($distinctRows === 1, 'Losing distinct-key issue leaves no duplicate maintenance source row.');
    $distinctWinner = current(array_values(array_filter($distinct, fn ($r) => $r['status'] === 'accepted')));
    $raceMovement = DB::table('inventory_movements')->where('id', $distinctWinner['result']['movement_id'])->first();
    $raceJournal = DB::table('accounting_journals')->where('id', $distinctWinner['result']['journal_id'])->first();
    $raceTotals = DB::table('accounting_journal_lines')->where('journal_id', $distinctWinner['result']['journal_id'])->selectRaw('sum(debit) debit,sum(credit) credit')->first();
    $raceIssue = DB::table('maintenance_part_issues')->where('issue_key', $distinctKeys[array_search($distinctWinner, $distinct, true)])->first();
    $competingReservation = DB::table('inventory_reservations')->find($f['reservation2_id']);
    $need($raceMovement && $raceMovement->operation === 'maintenance_issue' && $raceMovement->source_module === 'FieldMaintenance' && $raceMovement->source_id === (string) $f['work_order_id'] && $raceMovement->source_line === (string) $f['requirement_id']
        && $raceIssue && $raceJournal && $raceJournal->status === 'posted' && bccomp($raceTotals->debit, $raceTotals->credit, 2) === 0 && bccomp($competingReservation->fulfilled, '1.00000000', 8) === 0,
        'Winning distinct-key fulfillment retains exact M08 source identity and a balanced committed GL journal.');

    $assignmentVersion = (int) DB::table('maintenance_work_orders')->where('id', $f['work_order_id'])->value('version');
    $starts = CarbonImmutable::parse($postDate.' 01:00:00', 'Africa/Cairo')->subDays(10)->toIso8601String();
    $ends = CarbonImmutable::parse($postDate.' 02:00:00', 'Africa/Cairo')->subDays(10)->toIso8601String();
    $assignment = $race('assign', [
        ['actor' => $f['maker_id'], 'work' => $f['work_order_id'], 'version' => $assignmentVersion, 'technician_id' => $f['tech_id'], 'replace_id' => $f['assignment_id'], 'starts_at' => $starts, 'ends_at' => $ends, 'override_reason' => 'Synthetic documented schedule override for assignment race acceptance.', 'posting_date' => $postDate],
        ['actor' => $f['maker_id'], 'work' => $f['work_order_id'], 'version' => $assignmentVersion, 'technician_id' => $f['maker_id'], 'replace_id' => $f['assignment_id'], 'starts_at' => $starts, 'ends_at' => $ends, 'override_reason' => 'Synthetic documented schedule override for assignment race acceptance.', 'posting_date' => $postDate],
    ]);
    $need(count(array_filter($assignment, fn ($r) => $r['status'] === 'accepted')) === 1 && count(array_filter($assignment, fn ($r) => $r['status'] === 'rejected' && ($r['http_status'] ?? 0) === 409)) === 1, 'Competing assignment updates with one work-order version produce one update and one stale-version conflict: '.json_encode($assignment));
    $oldAssignment = DB::table('maintenance_assignments')->find($f['assignment_id']);
    $recordedVisitId = DB::table('maintenance_labor_entries')->where('id', $evidence['labor']['unrated_entry_id'])->value('visit_id');
    $need(! $oldAssignment->active && DB::table('maintenance_assignments')->where('work_order_id', $f['work_order_id'])->where('active', true)->count() === 1 && DB::table('maintenance_visits')->where('id', $recordedVisitId)->where('status', 'recorded')->exists(),
        'Winning assignment revision preserves recorded visit history and one active assignment.');

    $sameInvoice = $race('service_invoice', array_fill(0, 2, ['actor' => $f['maker_id'], 'work' => $f['main_work_order_id'], 'key' => $f['invoice_key'], 'invoice_date' => $f['invoice_date'], 'due_date' => $f['invoice_due'], 'posting_date' => $postDate]));
    $need($sameInvoice[0]['status'] === 'accepted' && $sameInvoice[1]['status'] === 'accepted' && (int) $sameInvoice[0]['result'] === (int) $f['invoice_id'] && (int) $sameInvoice[1]['result'] === (int) $f['invoice_id'], 'Concurrent same-key service invoice replay returns one existing invoice.');
    $newInvoiceKeys = [$uuid(), $uuid()];
    $newInvoice = $race('service_invoice', array_map(fn ($key) => ['actor' => $f['maker_id'], 'work' => $f['main_work_order_id'], 'key' => $key, 'invoice_date' => $f['invoice_date'], 'due_date' => $f['invoice_due'], 'posting_date' => $postDate], $newInvoiceKeys));
    $need(count(array_filter($newInvoice, fn ($r) => $r['status'] === 'rejected' && ($r['http_status'] ?? 0) === 409)) === 2 && DB::table('rp_customer_invoices')->where('maintenance_work_order_id', $f['main_work_order_id'])->count() === 1, 'Distinct-key service invoice requests cannot create a second invoice for the same maintenance work order.');

    $close = $race('state', array_fill(0, 2, ['actor' => $f['maker_id'], 'work' => $f['main_work_order_id'], 'version' => $f['main_work_version'], 'action' => 'close', 'reason' => 'Concurrent close race test with posted service invoice.', 'posting_date' => $postDate]));
    $need(count(array_filter($close, fn ($r) => $r['status'] === 'accepted')) === 1 && count(array_filter($close, fn ($r) => $r['status'] === 'rejected' && ($r['http_status'] ?? 0) === 409)) === 1 && DB::table('maintenance_work_orders')->where('id', $f['main_work_order_id'])->where('status', 'closed')->exists(), 'Same-version concurrent close yields one successful close and one conflict.');
    $closedVersion = (int) DB::table('maintenance_work_orders')->where('id', $f['main_work_order_id'])->value('version');
    $reopen = $race('state', array_fill(0, 2, ['actor' => $f['maker_id'], 'work' => $f['main_work_order_id'], 'version' => $closedVersion, 'action' => 'reopen', 'reason' => 'Concurrent reopen race test after valid close.', 'posting_date' => $postDate]));
    $need(count(array_filter($reopen, fn ($r) => $r['status'] === 'accepted')) === 1 && count(array_filter($reopen, fn ($r) => $r['status'] === 'rejected' && ($r['http_status'] ?? 0) === 409)) === 1 && DB::table('maintenance_work_orders')->where('id', $f['main_work_order_id'])->where('status', 'in_progress')->exists(), 'Same-version concurrent reopen yields one successful reopen and one conflict.');
    $mainInvoice = DB::table('rp_customer_invoices')->where('id', $f['invoice_id'])->first();
    $need($mainInvoice && $mainInvoice->status === 'posted' && DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $f['invoice_id'])->exists() && DB::table('maintenance_completions')->where('work_order_id', $f['main_work_order_id'])->exists(),
        'Concurrent close/reopen preserves posted service invoice AR and append-only completion evidence.');

    $evidence['concurrent_issue_race'] = ['same_key' => $f['issue_key'], 'movement_id' => (int) $same[0]['result']['movement_id'], 'journal_id' => (int) $same[0]['result']['journal_id'], 'issue_rows' => $sameCount, 'inventory_movement_rows' => $sameMovement, 'both_workers_converged' => true];
    $evidence['concurrent_distinct_issue_race'] = ['keys' => $distinctKeys, 'accepted_count' => 1, 'rejected_count' => 1, 'source_issue_rows' => 1, 'movement_id' => (int) $raceMovement->id, 'journal_id' => (int) $raceJournal->id,
        'journal_debit' => (string) $raceTotals->debit, 'journal_credit' => (string) $raceTotals->credit, 'fulfilled_quantity' => (string) $competingReservation->fulfilled, 'fulfillment_was_capped_by_outstanding_reservation' => true];
    $evidence['concurrent_reservation_race'] = ['idempotency_keys' => $spareKeys, 'reservation_rows' => $spareRows->count(), 'requested_each' => '3.00000000', 'reserved_sum' => $spareReserved, 'warehouse_on_hand' => (string) $balance->on_hand, 'warehouse_reserved' => (string) $balance->reserved, 'within_stock' => true];
    $evidence['concurrent_assignment_race'] = ['accepted_count' => 1, 'stale_version_conflicts' => 1, 'work_order_id' => $f['work_order_id'], 'base_version' => $assignmentVersion];
    $evidence['concurrent_service_invoice_races'] = ['same_key_invoice_id' => (int) $f['invoice_id'], 'same_key_accepted' => 2, 'distinct_key_rejections' => 2, 'invoice_count_for_work_order' => 1];
    $evidence['concurrent_close_reopen_races'] = ['close_success' => 1, 'close_conflict' => 1, 'reopen_success' => 1, 'reopen_conflict' => 1, 'final_status' => 'in_progress'];
    file_put_contents($evidencePath, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    echo "PASS distinct-key issue cap, competing reservations, assignment version, service-invoice dedupe, and close/reopen races\n";
} finally {
    foreach (glob($rootTmp.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }rmdir($rootTmp);
}

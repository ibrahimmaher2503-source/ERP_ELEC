<?php

use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\AccountingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase1-supplier-invoice-storage';
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
$actual = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if ($db->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test'
    || config('database.connections.pgsql.database') !== 'erp_elec_new_test' || config('database.connections.pgsql.host') !== '127.0.0.1'
    || ! in_array((string) config('database.connections.pgsql.port'), ['5443', '55442'], true) || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || $actual->db !== 'erp_elec_new_test' || ! in_array($actual->address, ['127.0.0.1', '127.0.0.1/32'], true) || ! in_array((string) $actual->port, ['5443', '55442'], true)) {
    throw new RuntimeException('ABORT: journal reversal check requires isolated erp_elec_new_test PostgreSQL on loopback port 5443 or disposable 55442.');
}
$need = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (callable $work, string $kind): Throwable {
    try {
        $work();
    } catch (Throwable $error) {
        $ok = $kind === 'source_denial' ? $error instanceof ValidationException
            : ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 409);
        if ($ok) {
            return $error;
        }
        throw new RuntimeException('Unexpected '.$kind.' failure: '.$error::class.' '.$error->getMessage(), 0, $error);
    }
    throw new RuntimeException('Expected '.$kind.' denial did not occur.');
};
$actorIds = json_decode(file_get_contents($root.'/docs/qa/evidence/phase1-financial-concurrency.json'), true, 512, JSON_THROW_ON_ERROR)['committed_synthetic_fixture_ids']['actors'] ?? [];
$actor = User::query()->whereKey((int) ($actorIds[0] ?? 0))->where('active', true)->first();
$supplierInvoice = DB::table('rp_supplier_invoices')->where('status', 'posted')->orderByDesc('id')->first();
$need($actor && $supplierInvoice, 'Committed synthetic actor or posted supplier source journal missing.');
$branch = (int) $supplierInvoice->branch_id;
$reviewerId = (int) DB::table('rp_credit_notes')->where('branch_id', $branch)->whereNotNull('approved_by')->orderByDesc('id')->value('approved_by');
$reviewer = User::query()->whereKey($reviewerId)->where('active', true)->first();
$need($reviewer && (int) $reviewer->id !== (int) $actor->id, 'Independent synthetic manual-journal reviewer missing.');
DB::beginTransaction();
$evidence = [];
try {
    foreach ([[(int) $actor->id, ['M11.journal_create', 'M11.journal_submit', 'M11.journal_post']],
        [(int) $reviewer->id, ['M11.journal_approve', 'M11.journal_post']]] as [$user, $codes]) {
        // No authority is granted to a real account: these actor IDs are synthetic fixtures in the isolated test DB.
        foreach ($codes as $code) {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $user, 'permission_code' => $code, 'branch_id' => $branch,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $periodReopened = false;
    $period = DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', now('Africa/Cairo')->toDateString())
        ->where('ends_on', '>=', now('Africa/Cairo')->toDateString())->first();
    if (! $period) {
        $closedPeriod = DB::table('accounting_periods')->where('status', 'closed')->where('starts_on', '<=', now('Africa/Cairo')->toDateString())
            ->where('ends_on', '>=', now('Africa/Cairo')->toDateString())->orderByDesc('id')->first();
        $need($closedPeriod !== null, 'Neither an open nor a closed current accounting period exists in the isolated synthetic activation.');
        // Phase 1 concurrency closes this synthetic period. Reopen only through the owning action, for this synthetic actor.
        DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id, 'permission_code' => 'M11.period_reopen', 'branch_id' => null,
            'created_at' => now(), 'updated_at' => now()]);
        app(AccountingActions::class)->reopen((int) $actor->id, (int) $closedPeriod->id, (int) $closedPeriod->version,
            'Synthetic journal reversal acceptance setup after the isolated concurrency period close.');
        $period = DB::table('accounting_periods')->where('id', $closedPeriod->id)->where('status', 'open')->first();
        $periodReopened = true;
    }
    $need($period !== null, 'Open current accounting period missing after the synthetic owner-action setup.');
    $accountIds = DB::table('accounting_account_mappings')->whereIn('purpose', ['sales', 'ppv'])->pluck('account_id', 'purpose');
    $need(isset($accountIds['sales'], $accountIds['ppv']), 'Synthetic revenue/expense accounts are missing.');
    $sourceJournal = DB::table('accounting_journals')->where('id', $supplierInvoice->journal_id)->first();
    $need($sourceJournal && $sourceJournal->status === 'posted' && $sourceJournal->source_key !== null, 'Supplier invoice source journal evidence missing.');

    $beforeOriginal = (array) DB::table('accounting_journals')->find($sourceJournal->id);
    $beforeLines = DB::table('accounting_journal_lines')->where('journal_id', $sourceJournal->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $sourceDenied = $expect(fn () => app(AccountingService::class)->reversePostedJournal((int) $actor->id, (int) $sourceJournal->id,
        now('Africa/Cairo')->toDateString(), 'Reject source-owned journal reversal'), 'source_denial');
    $need(DB::table('accounting_journals')->where('id', $sourceJournal->id)->value('status') === 'posted'
        && ! DB::table('accounting_journals')->where('source_key', 'journal-reversal:'.$sourceJournal->id)->exists(),
        'Rejected source-journal reversal changed posted evidence or created a reversal.');

    $actions = app(AccountingActions::class);
    $key = (string) Str::uuid();
    $manualId = $actions->journal((int) $actor->id, null, 0, $key, ['branch_id' => $branch, 'journal_date' => now('Africa/Cairo')->toDateString(),
        'description' => 'PHASE1 synthetic bank/cash reclassification reversal acceptance', 'lines' => [
            ['account_id' => (int) $accountIds['ppv'], 'debit' => '1.00', 'credit' => '0.00', 'description' => 'Synthetic test expense correction debit'],
            ['account_id' => (int) $accountIds['sales'], 'debit' => '0.00', 'credit' => '1.00', 'description' => 'Synthetic test income correction credit'],
        ]]);
    $actions->advance((int) $actor->id, $manualId, 1, 'submit');
    $actions->advance((int) $reviewer->id, $manualId, 2, 'approve');
    $actions->advance((int) $reviewer->id, $manualId, 3, 'post');
    $manual = DB::table('accounting_journals')->find($manualId);
    $need($manual && $manual->status === 'posted' && $manual->source_key === null, 'Synthetic manual journal did not post through the authorized owner lifecycle.');
    $originalLines = DB::table('accounting_journal_lines')->where('journal_id', $manualId)->orderBy('id')->get();
    $debit = (string) $originalLines->sum('debit');
    $credit = (string) $originalLines->sum('credit');
    $need(bccomp($debit, $credit, 2) === 0 && bccomp($debit, '1.00', 2) === 0, 'Manual test journal is not balanced at EGP 1.00.');

    $service = app(AccountingService::class);
    $reverseDate = now('Africa/Cairo')->toDateString();
    $reason = 'Synthetic authorized manual journal correction';
    $reversalId = $service->reversePostedJournal((int) $actor->id, $manualId, $reverseDate, $reason);
    $replayId = $service->reversePostedJournal((int) $actor->id, $manualId, $reverseDate, $reason);
    $reversal = DB::table('accounting_journals')->find($reversalId);
    $reversalLines = DB::table('accounting_journal_lines')->where('journal_id', $reversalId)->get();
    $need($reversalId === $replayId && DB::table('accounting_journals')->where('source_key', 'journal-reversal:'.$manualId)->count() === 1,
        'Manual journal reversal replay created more than one corrective journal.');
    $need($reversal && $reversal->status === 'posted' && $reversal->source_key === 'journal-reversal:'.$manualId
        && bccomp((string) $reversalLines->sum('debit'), '1.00', 2) === 0 && bccomp((string) $reversalLines->sum('credit'), '1.00', 2) === 0,
        'Source-linked manual reversal is missing or not exactly balanced at EGP 1.00.');
    foreach ($originalLines as $line) {
        $inverse = $reversalLines->firstWhere('account_id', $line->account_id);
        $need($inverse && bccomp($inverse->debit, $line->credit, 2) === 0 && bccomp($inverse->credit, $line->debit, 2) === 0,
            'Reversal did not exactly swap the original manual journal amounts by account.');
    }
    $need((array) DB::table('accounting_journals')->find($manualId) === (array) $manual,
        'Original posted manual journal header changed during reversal.');
    $expect(fn () => $service->reversePostedJournal((int) $actor->id, $reversalId, $reverseDate, $reason), 'reversal_of_reversal');
    $need(DB::table('accounting_journals')->where('source_key', 'journal-reversal:'.$reversalId)->doesntExist(),
        'A reversal journal was allowed to be reversed again.');
    $need((array) DB::table('accounting_journals')->find($sourceJournal->id) === $beforeOriginal
        && DB::table('accounting_journal_lines')->where('journal_id', $sourceJournal->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all() === $beforeLines,
        'Source-owned supplier journal or lines changed after rejected reversal attempt.');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $evidence = ['database' => $actual->db, 'port' => (int) $actual->port, 'manual_journal_id' => $manualId,
        'reversal_journal_id' => $reversalId, 'retry_same_id' => true, 'reversal_balanced' => true, 'original_unchanged' => true,
        'reversal_of_reversal_denied' => true, 'source_journal_id' => (int) $sourceJournal->id, 'source_reversal_denied' => true,
        'setup_period_reopened_in_rollback_transaction' => $periodReopened,
        'source_journal_unchanged' => true, 'all_pass' => true];
} catch (Throwable $error) {
    $evidence = ['database' => $actual->db, 'port' => (int) $actual->port, 'all_pass' => false,
        'error_class' => $error::class, 'error' => mb_substr($error->getMessage(), 0, 1200), 'trace' => array_slice($error->getTrace(), 0, 7)];
    throw $error;
} finally {
    if ($db->transactionLevel() > 0) {
        DB::rollBack();
    }
    $path = $root.'/docs/qa/evidence/phase1-journal-reversal.json';
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0770, true);
    }
    $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    file_put_contents($path, $json, LOCK_EX);
    echo $json;
}

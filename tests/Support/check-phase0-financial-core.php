<?php

// Action-level M11 acceptance probe. Writes only synthetic rows to the private 55433 PostgreSQL restore.
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\OpeningWorkbook;
use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_DATABASE=erp_elec_new_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'erp_elec_new_test';
if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55433') {
    throw new RuntimeException('ABORT: explicit isolated 55433 required.');
}
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase0-recovery-20261027/restore-storage';
if (! is_dir($storage)) {
    mkdir($storage, 0700, true);
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'erp_elec_new_test'
    || DB::selectOne('select current_database() db')->db !== 'erp_elec_new_test'
    || config('database.connections.pgsql.port') != 55433) {
    throw new RuntimeException('ABORT: exact isolated database guard failed.');
}
$ok = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$denied = static function (callable $action, string $kind): bool {
    try {
        $action();
    } catch (Throwable $e) {
        return $kind === 'forbidden' ? ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403)
            : ($e instanceof ValidationException);
    }

    return false;
};
$branch = (int) DB::table('branches')->where('active', true)->orderBy('id')->value('id');
$actors = [];
foreach (['maker' => ['M11.journal_create', 'M11.journal_submit', 'M11.opening_manage', 'M11.opening_entry'], 'reviewer' => ['M11.journal_approve'], 'poster' => ['M11.journal_post']] as $key => $codes) {
    $user = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $actors[$key] = (int) $user->id;
    DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($codes as $code) {
        DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $code, 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
    if ($key === 'maker') {
        DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => 'M11.opening_manage', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}
$maker = $actors['maker'];
$reviewer = $actors['reviewer'];
$poster = $actors['poster'];
$deniedActor = (int) User::factory()->create(['active' => true, 'email_verified_at' => now()])->id;
$ok($maker && $reviewer && $poster && $branch && $deniedActor, 'Synthetic M11 actor fixture missing.');
$period = DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', '2024-06-01')->where('ends_on', '>=', '2024-06-01')->exists();
$ok($period, 'Synthetic 2024 open accounting period missing.');
$ordinary = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->whereNotIn('id', DB::table('accounting_account_mappings')->select('account_id'))->orderBy('id')->limit(2)->get();
if ($ordinary->count() < 2) {
    $ordinary = collect([$ordinary->first(), AccountingAccount::factory()->create(['code' => 'CORE-TEST-'.Str::random(8), 'name' => 'Synthetic manual journal account', 'kind' => 'equity'])])->filter()->values();
}
$ok($ordinary->count() === 2, 'Two eligible non-control accounts missing.');
$control = (int) DB::table('accounting_account_mappings')->where('purpose', 'inventory')->value('account_id');
$actions = app(AccountingActions::class);
$id = $actions->journal($maker, null, 0, (string) Str::uuid(), [
    'branch_id' => $branch, 'journal_date' => '2024-06-01', 'description' => 'Synthetic M11 manual journal acceptance',
    'lines' => [['account_id' => $ordinary[0]->id, 'debit' => '12.50', 'credit' => '0.00', 'description' => 'Synthetic debit'], ['account_id' => $ordinary[1]->id, 'debit' => '0.00', 'credit' => '12.50', 'description' => 'Synthetic credit']]]);
$ok($denied(fn () => $actions->advance($deniedActor, $id, 1, 'submit'), 'forbidden'), 'Missing journal_submit permission was not denied.');
$actions->advance($maker, $id, 1, 'submit');
$actions->advance($reviewer, $id, 2, 'approve');
$actions->advance($poster, $id, 3, 'post');
$journal = DB::table('accounting_journals')->find($id);
$ok($journal->status === 'posted' && $journal->official_document_id && $journal->official_number, 'Manual journal did not post with official document.');
file_put_contents($root.'/.tmp/phase0-core-stage', 'journal-posted');
$doc = DB::table('system_official_documents')->find($journal->official_document_id);
$ok($doc !== null, 'Persisted immutable official journal PDF row missing.');
$pdf = app(OfficialDocuments::class)->retrieve($journal->official_document_id);
$ok(str_starts_with($pdf['bytes'], '%PDF-') && hash_equals($doc->sha256, hash('sha256', $pdf['bytes'])) && hash_equals($doc->sha256, $pdf['sha256']), 'Official journal PDF bytes/hash do not match persisted immutable snapshot.');
$immutableAttempt = static function (callable $mutation): bool {
    DB::beginTransaction();
    try {
        $mutation();
        DB::rollBack();

        return false;
    } catch (Throwable) {
        DB::rollBack();

        return true;
    }
};
$immutableUpdate = $immutableAttempt(fn () => DB::table('system_official_documents')->where('id', $journal->official_document_id)->update(['sha256' => str_repeat('0', 64)]));
$immutableDelete = $immutableAttempt(fn () => DB::table('system_official_documents')->where('id', $journal->official_document_id)->delete());
$ok($immutableUpdate && $immutableDelete, 'Official journal document row allowed mutation or deletion.');
$makeJournal = static function (string $date, int $debitAccount, int $creditAccount) use ($actions, $maker, $branch): int {
    return $actions->journal($maker, null, 0, (string) Str::uuid(), ['branch_id' => $branch, 'journal_date' => $date, 'description' => 'Synthetic negative-path journal acceptance',
        'lines' => [['account_id' => $debitAccount, 'debit' => '1.00', 'credit' => '0.00'], ['account_id' => $creditAccount, 'debit' => '0.00', 'credit' => '1.00']]]);
};
$unbalanced = $actions->journal($maker, null, 0, (string) Str::uuid(), ['branch_id' => $branch, 'journal_date' => '2024-06-01', 'description' => 'Synthetic unbalanced journal denial',
    'lines' => [['account_id' => $ordinary[0]->id, 'debit' => '1.00', 'credit' => '0.00'], ['account_id' => $ordinary[1]->id, 'debit' => '0.00', 'credit' => '0.99']]]);
$unbalancedDenied = $denied(fn () => $actions->advance($maker, $unbalanced, 1, 'submit'), 'validation');
$ok($unbalancedDenied, 'Unbalanced journal was not rejected at submit.');
$controlJournal = $makeJournal('2024-06-01', $control, (int) $ordinary[1]->id);
$actions->advance($maker, $controlJournal, 1, 'submit');
$actions->advance($reviewer, $controlJournal, 2, 'approve');
$controlDenied = $denied(fn () => $actions->advance($poster, $controlJournal, 3, 'post'), 'validation');
$ok($controlDenied, 'Manual posting to a protected control account was not rejected.');
$closedJournal = $makeJournal('2036-01-01', (int) $ordinary[0]->id, (int) $ordinary[1]->id);
$actions->advance($maker, $closedJournal, 1, 'submit');
$actions->advance($reviewer, $closedJournal, 2, 'approve');
$closedDenied = $denied(fn () => $actions->advance($poster, $closedJournal, 3, 'post'), 'validation');
$ok($closedDenied, 'Posting in a date outside every open period was not rejected.');
$before = ['batches' => DB::table('accounting_opening_batches')->count(), 'lines' => DB::table('accounting_opening_lines')->count(), 'activation' => DB::table('accounting_activation')->where('id', 1)->value('version')];
$workbook = app(OpeningWorkbook::class);
$template = $workbook->template($maker);
file_put_contents($root.'/.tmp/phase0-core-stage', 'template-created');
$book = IOFactory::load($template);
$sheet = $book->getSheetByName('Data');
$fixtureRows = [['gl', 'SYN-GL-D', (string) $branch, (string) $ordinary[0]->id, '1.00', '0.00'], ['gl', 'SYN-GL-C', (string) $branch, (string) $ordinary[1]->id, '0.00', '1.00']];
foreach ($fixtureRows as $ri => $row) {
    foreach ($row as $ci => $value) {
        $sheet->getCell([$ci + 1, $ri + 2])->setValueExplicit($value, DataType::TYPE_STRING);
    }
}
$fixture = $storage.'/app/private/phase0-opening-preview-'.Str::uuid().'.xlsx';
if (! is_dir(dirname($fixture))) {
    mkdir(dirname($fixture), 0700, true);
}
(new Xlsx($book))->save($fixture);
$book->disconnectWorksheets();
$zip = new ZipArchive;
if ($zip->open($fixture) !== true) {
    throw new RuntimeException('Cannot reopen generated XLSX fixture.');
}
$xml = $zip->getFromName('xl/worksheets/sheet1.xml');
if (! is_string($xml) || ! str_contains($xml, 'A1:L501')) {
    throw new RuntimeException('Unexpected generated template Data range.');
}
$zip->close();
$previewStarted = microtime(true);
$preview = $workbook->read($maker, $fixture);
$previewElapsed = round(microtime(true) - $previewStarted, 3);
file_put_contents($root.'/.tmp/phase0-core-stage', 'preview-read');
$after = ['batches' => DB::table('accounting_opening_batches')->count(), 'lines' => DB::table('accounting_opening_lines')->count(), 'activation' => DB::table('accounting_activation')->where('id', 1)->value('version')];
$ok(count($preview) === 2 && $before === $after, 'Opening workbook preview did not remain effect-free.');
$result = ['database' => 'erp_elec_new_test', 'postgres_port' => 55433, 'migration' => '2026_10_27_170000_preserve_fifo_unit_cost_lineage',
    'manual_journal' => ['journal_id' => $id, 'status' => $journal->status, 'official_number' => $journal->official_number, 'pdf_bytes' => strlen($pdf['bytes']), 'pdf_sha256' => hash('sha256', $pdf['bytes']), 'independent_approval' => $journal->approved_by !== $maker, 'immutable_pdf_update_rejected' => $immutableUpdate, 'immutable_pdf_delete_rejected' => $immutableDelete, 'passed' => true],
    'denials' => ['missing_submit_permission' => true, 'unbalanced_journal' => $unbalancedDenied, 'protected_control_account' => $controlDenied, 'closed_period' => $closedDenied],
    'opening_workbook' => ['template_bytes' => filesize($template), 'template_data_range' => 'A1:L501', 'preview_rows' => count($preview), 'preview_elapsed_seconds' => $previewElapsed, 'before' => $before, 'after' => $after, 'activation_effects' => false, 'passed' => true], 'all_pass' => true];
$path = $root.'/docs/qa/evidence/phase0-financial-core.json';
file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

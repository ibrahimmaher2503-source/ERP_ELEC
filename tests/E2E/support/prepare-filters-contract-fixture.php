<?php

use App\Modules\ServiceContracts\Domain\ServiceContract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$private = $root.'/.tmp/forms-ux-20261102/private';
$bootstrap = json_decode(file_get_contents($private.'/bootstrap.json'), true, flags: JSON_THROW_ON_ERROR);
$marker = json_decode(file_get_contents($private.'/.codex-forms-ux-owned.json'), true, flags: JSON_THROW_ON_ERROR);
if (($bootstrap['status'] ?? null) !== 'READY' || ($bootstrap['database'] ?? null) !== 'erp_elec_new_test' || (int) ($bootstrap['db_port'] ?? 0) !== 55467 || ($marker['port'] ?? null) !== 55467 || ($marker['database'] ?? null) !== 'erp_elec_new_test' || ! hash_equals((string) ($marker['nonce'] ?? ''), (string) ($bootstrap['env']['E2E_CLUSTER_TOKEN'] ?? ''))) {
    throw new RuntimeException('Refusing to create select fixtures outside the marked private forms UX database.');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne("SELECT current_database() AS database, current_setting('port') AS port, current_setting('data_directory') AS data_directory, (SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database = current_database()) AS nonce");
if ($identity->database !== 'erp_elec_new_test' || $identity->port !== '55467' || realpath($identity->data_directory) !== realpath($root.'/'.$bootstrap['postgres_data_directory']) || ! hash_equals(trim($identity->nonce), $marker['nonce'])) {
    throw new RuntimeException('Private PostgreSQL identity or ownership receipt mismatch.');
}

$actor = json_decode(file_get_contents($root.'/.tmp/filters-ux-20261102/actor.json'), true, flags: JSON_THROW_ON_ERROR);
if (! DB::table('user_branch_scopes')->where('user_id', $actor['user_id'])->where('branch_id', $actor['branch_id'])->exists()) {
    throw new RuntimeException('Synthetic actor scope is missing.');
}
$contract = ServiceContract::query()->where('created_by', $actor['user_id'])->where('branch_id', $actor['branch_id'])->where('status', 'draft')->first()
    ?? ServiceContract::factory()->create(['branch_id' => $actor['branch_id'], 'created_by' => $actor['user_id']]);
file_put_contents($root.'/.tmp/filters-ux-20261102/contract.json', json_encode(['contract_id' => $contract->id], JSON_THROW_ON_ERROR));
echo 'READY synthetic draft contract for filter review; no activation or billing.'.PHP_EOL;

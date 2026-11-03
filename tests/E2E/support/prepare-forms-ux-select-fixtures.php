<?php

use App\Modules\Products\Domain\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
$private = $root.'/.tmp/forms-ux-20261102/private';
$bootstrap = json_decode(file_get_contents($private.'/bootstrap.json'), true, flags: JSON_THROW_ON_ERROR);
$marker = json_decode(file_get_contents($private.'/.codex-forms-ux-owned.json'), true, flags: JSON_THROW_ON_ERROR);
if (($bootstrap['status'] ?? null) !== 'READY' || ($bootstrap['database'] ?? null) !== 'erp_elec_new_test' || (int) ($bootstrap['db_port'] ?? 0) !== 55467 || ($marker['port'] ?? null) !== 55467 || ($marker['database'] ?? null) !== 'erp_elec_new_test' || ! hash_equals((string) ($marker['nonce'] ?? ''), (string) ($bootstrap['env']['E2E_CLUSTER_TOKEN'] ?? ''))) {
    throw new RuntimeException('Refusing to create select fixtures outside the marked private forms UX database.');
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = DB::selectOne("SELECT current_database() AS database, current_setting('port') AS port, current_setting('data_directory') AS data_directory, (SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database = current_database()) AS nonce");
if ($identity->database !== 'erp_elec_new_test' || $identity->port !== '55467' || realpath($identity->data_directory) !== realpath($root.'/'.$bootstrap['postgres_data_directory']) || ! hash_equals(trim($identity->nonce), $marker['nonce'])) {
    throw new RuntimeException('Private PostgreSQL identity or ownership receipt mismatch.');
}

$tag = $argv[1] ?? '';
if (! preg_match('/^[A-Z0-9]{6,12}$/', $tag)) {
    throw new RuntimeException('A synthetic fixture tag is required.');
}
$fixtures = Unit::factory()->count(9)->sequence(fn ($sequence) => [
    'code' => 'E2E-'.$tag.'-UOM-'.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT),
    'name' => 'اختبار واجهة '.$tag.' '.($sequence->index + 1),
])->create();
file_put_contents($private.'/select-fixtures.json', json_encode([
    'status' => 'READY', 'database' => 'erp_elec_new_test', 'port' => 55467,
    'unit_ids' => $fixtures->modelKeys(), 'codes' => $fixtures->pluck('code')->all(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo 'READY synthetic unit fixture count='.$fixtures->count().PHP_EOL;

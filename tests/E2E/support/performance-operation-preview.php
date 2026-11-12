<?php

declare(strict_types=1);

use App\Modules\Products\Domain\Product;
use App\Modules\System\Application\DataOperations\OperationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
$actorId = (int) ($argv[1] ?? 0);
$storage = getenv('E2E_APP_STORAGE') ?: '';
$cluster = $root.'/.tmp/page-speed-audit/postgres-55461';
$markerPath = $cluster.'/codex-owned.json';
$marker = is_file($markerPath) ? json_decode((string) file_get_contents($markerPath), true) : null;
if ($actorId < 1 || (int) getenv('E2E_DB_PORT') !== 55461 || getenv('E2E_DB_DATABASE') !== 'erp_elec_new_test'
    || ! str_contains(str_replace('\\', '/', $storage), '/.tmp/page-speed-audit/') || ! is_array($marker)
    || ($marker['kind'] ?? null) !== 'codex-e2e-postgres' || ($marker['repo'] ?? null) !== $root || ($marker['port'] ?? null) !== 55461
    || ($marker['token'] ?? null) !== getenv('E2E_CLUSTER_TOKEN') || strlen((string) getenv('E2E_CLUSTER_TOKEN')) < 32) {
    fwrite(STDERR, "Refusing preview fixture outside the marker-owned performance database.\n");
    exit(2);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne("select current_database() as database, current_setting('port') as port, current_setting('data_directory') as data_directory");
if (($identity->database ?? null) !== 'erp_elec_new_test' || (string) ($identity->port ?? '') !== '55461'
    || realpath((string) ($identity->data_directory ?? '')) !== realpath($cluster)) {
    fwrite(STDERR, "Refusing preview fixture: active PostgreSQL identity mismatch.\n");
    exit(3);
}

$actor = DB::table('users')->where('id', $actorId)->where('name', 'like', 'DEMO-EG E2E performance refresh %')->first();
$isAdministrator = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
    ->where('user_roles.user_id', $actorId)->where('roles.code', 'Administrator')->exists();
if (! $actor || ! $isAdministrator) {
    fwrite(STDERR, "Refusing preview fixture for an unexpected actor.\n");
    exit(4);
}

$productId = (int) Product::query()->where('active', true)->where('kind', '<>', 'service')->orderBy('id')->value('id');
if ($productId < 1) {
    fwrite(STDERR, "No active non-service product is available for the preview-only fixture.\n");
    exit(5);
}
$run = app(OperationService::class)->previewBulk($actorId, 'products', [$productId], [
    'short_description' => ['mode' => 'set', 'value' => 'معاينة أداء فقط — دون تنفيذ'],
]);
fwrite(STDOUT, json_encode(['run_id' => (string) $run->id, 'actor_id' => $actorId, 'item_count' => $run->items()->count()], JSON_THROW_ON_ERROR)."\n");

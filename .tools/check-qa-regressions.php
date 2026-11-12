<?php

use App\Models\User;
use App\Modules\Products\Application\ProductActions;
use App\Modules\Products\Application\ProductDataDefinition;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\System\Domain\DataOperationRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
};
$throws = static function (callable $action, string $message) use ($assert): void {
    try {
        $action();
    } catch (HttpExceptionInterface $exception) {
        $assert($exception->getStatusCode() === 403, $message);

        return;
    }
    throw new RuntimeException($message.' (no 403)');
};

$assert(DB::connection()->getDriverName() === 'pgsql' && DB::connection()->getDatabaseName() === 'erp_elec_new_test', 'QA regression check uses only the exact PostgreSQL .env.testing database');
$originalRoot = config('filesystems.disks.local.root');
$originalStorage = Storage::getFacadeRoot();
$diskRoot = storage_path('framework/testing/disks/qa-regressions-'.bin2hex(random_bytes(5)));
Storage::fake('local', ['root' => $diskRoot]);
DB::beginTransaction();
try {
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach (['M02.product_view', 'M02.product_create', 'M02.product_import'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $unit = Unit::factory()->create(['dimension' => 'count']);
    $base = ['sku' => 'QA-REG-'.strtoupper(bin2hex(random_bytes(4))), 'name' => 'QA regression product', 'name_en' => null,
        'short_description' => null, 'description' => null, 'kind' => 'bulk', 'tracking' => 'quantity', 'category_id' => null,
        'brand_id' => null, 'model' => null, 'base_unit_id' => $unit->id, 'barcode' => null];
    $actions = app(ProductActions::class);
    $throws(fn () => $actions->save($actor->id, [...$base, 'active' => false]), 'direct inactive Product create requires M02.product_status');
    $active = $actions->save($actor->id, [...$base, 'sku' => $base['sku'].'-ACTIVE', 'active' => true]);
    $assert($active->active, 'ordinary Product create defaults to active without M02.product_status');

    $definition = app(ProductDataDefinition::class);
    $row = ['sku' => $base['sku'].'-IMPORT', 'name' => 'Imported inactive product', 'kind' => 'bulk', 'tracking' => 'quantity', 'base_unit_code' => $unit->code, 'active' => '0'];
    $throws(fn () => $definition->previewImport($actor->id, $row, 'create'), 'inactive import preview requires M02.product_status');
    $row['active'] = '1';
    $plan = $definition->previewImport($actor->id, $row, 'create');
    $result = $definition->execute($actor->id, $plan);
    $assert(Product::find($result['id'])?->active === true, 'active import preview and execution need no M02.product_status');

    $source = 'data-operations/sources/qa-cleanup-'.bin2hex(random_bytes(4)).'.xlsx';
    Storage::disk('local')->put($source, 'synthetic source');
    $run = DataOperationRun::factory()->create(['actor_id' => $actor->id, 'status' => 'preview', 'expires_at' => now()->subMinute(), 'source_path' => $source]);
    $fakeDisk = Storage::disk('local');
    $diskMock = Mockery::mock($fakeDisk)->makePartial();
    $deleteAttempt = 0;
    $diskMock->shouldReceive('delete')->with($source)->atLeast()->once()->andReturnUsing(function () use (&$deleteAttempt, $fakeDisk, $source): bool {
        return ++$deleteAttempt === 1 ? false : $fakeDisk->delete($source);
    });
    $diskMock->shouldReceive('files')->with('data-operations/tmp')->andReturn([]);
    Storage::shouldReceive('disk')->with('local')->andReturn($diskMock);
    Artisan::call('erp:cleanup-data-operations');
    $assert($run->fresh()->status === 'expired' && $run->fresh()->source_path === $source && $fakeDisk->exists($source), 'cleanup delete failure expires preview while retaining source_path and file');
    Artisan::call('erp:cleanup-data-operations');
    $assert($deleteAttempt === 2 && $run->fresh()->source_path === null && ! $fakeDisk->exists($source), 'later cleanup retry deletes the retained source and clears source_path');
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::swap($originalStorage);
    try {
        Mockery::close();
    } finally {
        Storage::clearResolvedInstances();
        config(['filesystems.disks.local.root' => $originalRoot]);
        Storage::forgetDisk('local');
        File::deleteDirectory($diskRoot);
    }
    echo "Disposable QA regression transaction rolled back; fake disk reset.\n";
}

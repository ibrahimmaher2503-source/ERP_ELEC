<?php

use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductConversion;
use App\Modules\Products\Domain\ProductMedia;
use App\Modules\System\Domain\DataOperationItem;
use App\Modules\System\Domain\DataOperationRun;
use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$assert(app()->environment('testing'), 'The demo check must run in testing.');
$assert(DB::connection()->getDriverName() === 'pgsql', 'Only PostgreSQL is accepted.');
$assert(DB::connection()->getDatabaseName() === 'erp_elec_new_test', 'The check requires the exact .env.testing database.');

$diskParent = storage_path('framework/testing/disks');
$diskRoot = $diskParent.DIRECTORY_SEPARATOR.'demo-factory-check-'.strtoupper(bin2hex(random_bytes(5)));
$assert(str_starts_with($diskRoot, $diskParent.DIRECTORY_SEPARATOR), 'Refusing to use a demo fixture path outside the isolated testing folder.');
config(['filesystems.disks.local.root' => $diskRoot]);
Storage::forgetDisk('local');
File::ensureDirectoryExists($diskRoot);

$primaryKeys = [
    'permission_catalog' => 'code', 'cache' => 'key', 'sessions' => 'id',
    'password_reset_tokens' => 'email', 'jobs' => 'id', 'job_batches' => 'id', 'failed_jobs' => 'id',
];
$tables = [
    'companies', 'branches', 'users', 'access_grants', 'access_audits', 'audit_entries',
    'permission_catalog', 'roles', 'role_permissions', 'user_roles', 'user_branch_scopes',
    'product_categories', 'brands', 'units_of_measure', 'products', 'product_unit_conversions', 'product_media',
    'data_operation_runs', 'data_operation_items',
    'passkeys', 'cache', 'sessions', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs',
];
$fingerprint = static function () use ($tables, $primaryKeys): array {
    $result = [];
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $key = $primaryKeys[$table] ?? 'id';
        $result[$table] = [];
        foreach (DB::table($table)->orderBy($key)->get() as $row) {
            $values = (array) $row;
            $result[$table][(string) $values[$key]] = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
        }
    }

    return $result;
};
$before = $fingerprint();
DB::beginTransaction();

try {
    app(RealisticDemoSeeder::class)->run();

    $branchCodes = ['DEMO-BR-CAI', 'DEMO-BR-ALX', 'DEMO-BR-GIZ', 'DEMO-BR-MAN', 'DEMO-BR-ASW'];
    $assert(DB::table('branches')->whereIn('code', $branchCodes)->count() === 5, 'Expected five demo branches.');

    $emails = array_map(fn (int $number) => sprintf('demo.user%02d@example.test', $number), range(1, 12));
    $users = DB::table('users')->whereIn('email', $emails)->get();
    $assert($users->count() === 12, 'Expected twelve demo users.');

    $factoryActorId = (int) $users->first()->id;
    $factoryProduct = Product::factory()->create([
        'created_by' => $factoryActorId,
        'updated_by' => $factoryActorId,
    ]);
    $factoryConversion = ProductConversion::factory()->create(['product_id' => $factoryProduct->id]);
    $factoryMedia = ProductMedia::factory()->create([
        'product_id' => $factoryProduct->id,
        'created_by' => $factoryActorId,
    ]);
    $factoryProduct->load(['category', 'brand', 'baseUnit']);
    $factoryUnit = $factoryConversion->unit()->first();
    $assert($factoryProduct->category !== null && $factoryProduct->brand !== null && $factoryProduct->baseUnit !== null, 'Product factory must create valid catalog relationships.');
    $assert((int) $factoryConversion->product_id === (int) $factoryProduct->id && $factoryUnit !== null && $factoryProduct->baseUnit->dimension === $factoryUnit->dimension && bccomp((string) $factoryConversion->factor, '10.00000000', 8) === 0, 'Conversion factory must keep the alternative unit in the actual product base dimension and factor ten exactly.');
    $assert((int) $factoryMedia->product_id === (int) $factoryProduct->id && str_starts_with($factoryMedia->path, 'product-media/'.$factoryProduct->id.'/') && Storage::disk('local')->exists($factoryMedia->path), 'Media factory must create an existing file under the protected product path.');

    $previewRun = DataOperationRun::factory()->create([
        'actor_id' => $factoryActorId,
        'entity' => 'products',
        'kind' => 'bulk',
        'mode' => 'update',
        'status' => 'preview',
        'completed_at' => null,
    ]);
    $previewItem = DataOperationItem::factory()->create([
        'run_id' => $previewRun->id,
        'row_number' => 1,
        'identifier' => $factoryProduct->sku,
        'plan' => ['fixture_only' => true, 'operation' => 'preview_only', 'product_id' => $factoryProduct->id,
            'changes' => ['short_description' => 'Synthetic proposed description.']],
        'before_values' => ['short_description' => $factoryProduct->short_description],
        'after_values' => ['short_description' => 'Synthetic proposed description.'],
        'result' => null,
    ]);
    $assert($previewRun->exists && $previewRun->status === 'preview' && $previewRun->completed_at === null
        && $previewItem->exists && $previewItem->run_id === $previewRun->id && $previewItem->row_number === 1
        && $previewItem->status === 'valid' && $previewItem->identifier === $factoryProduct->sku
        && $previewItem->plan['product_id'] === $factoryProduct->id && $previewItem->result === null,
        'Data operation factories persist a coherent preview-only item for a real synthetic product without completing or applying the operation.');

    $firstRun = $fingerprint();

    $userIds = $users->pluck('id')->all();
    $assert($users->every(fn ($user) => ! $user->active && $user->email_verified_at === null && in_array($user->gender, ['male', 'female'], true)), 'Demo users must stay disabled and unverified with valid genders.');
    $assert($users->every(fn ($user) => ! Hash::check('password', $user->password)), 'Demo users must not receive the known factory password.');
    foreach (['user_roles', 'user_branch_scopes', 'access_grants', 'access_audits'] as $table) {
        $assert(DB::table($table)->whereIn($table === 'access_audits' ? 'subject_user_id' : 'user_id', $userIds)->count() === 0, "Demo users must have no {$table} records.");
    }

    $assert(DB::table('product_categories')->where('code', 'like', 'DEMO-CAT-%')->count() === 8, 'Expected eight demo categories.');
    $assert(DB::table('brands')->whereIn('name', ['Schneider Electric', 'ABB', 'Siemens', 'Legrand', 'Eaton', 'Phoenix Contact'])->distinct()->count('name') === 6, 'Expected six distinct demo brands.');
    $assert(DB::table('units_of_measure')->where('code', 'like', 'DEMO-UOM-%')->count() === 8, 'Expected eight demo units.');
    $productSkus = array_map(fn (int $number) => sprintf('DEMO-PROD-%03d', $number), range(1, 30));
    $products = DB::table('products')->whereIn('sku', $productSkus)->get()->keyBy('sku');
    $assert($products->count() === 30, 'Expected thirty demo products.');
    $assert($products->every(fn ($product) => str_starts_with($product->barcode, 'DEMO-BAR-') && $product->created_by > 0 && $product->updated_by > 0), 'Demo products need internal barcodes and actual creator references.');
    $assert(DB::table('products')->whereIn('sku', $productSkus)->whereIn('kind', ['serialized_equipment', 'bulk', 'service'])->count() === 30, 'Product kinds must use the catalog contract.');
    $assert(DB::table('products')->whereIn('sku', $productSkus)->whereIn('tracking', ['serial', 'quantity', 'none'])->count() === 30, 'Product tracking must use the catalog contract.');

    $cartonId = DB::table('units_of_measure')->where('code', 'DEMO-UOM-CARTON')->value('id');
    $pieceId = DB::table('units_of_measure')->where('code', 'DEMO-UOM-PIECE')->value('id');
    $assert(DB::table('units_of_measure')->whereIn('id', [$cartonId, $pieceId])->where('dimension', 'count')->count() === 2, 'Carton and piece units must share the count dimension.');
    $conversions = DB::table('product_unit_conversions')->whereIn('product_id', $products->pluck('id'))->get();
    $assert($conversions->count() === 3, 'Expected three explicit carton conversions.');
    $assert($conversions->every(fn ($row) => (int) $row->unit_id === (int) $cartonId && bccomp((string) $row->factor, '10.00000000', 8) === 0), 'Carton conversions must be exactly ten pieces.');
    $assert($products->every(fn ($product) => is_array(json_decode($product->attributes, true)) && json_decode($product->attributes, true) !== []), 'Products need structured specifications.');

    $media = DB::table('product_media')->whereIn('product_id', $products->pluck('id'))->whereNull('removed_at')->get();
    $assert($media->count() === 30, 'Expected one private generated PNG for each demo product.');
    foreach ($media as $file) {
        $assert(str_starts_with($file->path, 'product-media/'.$file->product_id.'/'), 'Media paths must match the protected product route.');
        $assert(basename($file->name) === $file->name && $file->mime === 'image/png' && $file->is_image, 'Generated media metadata must be safe.');
        $assert(Storage::disk('local')->exists($file->path), 'Generated media file is missing.');
        $image = @getimagesize(Storage::disk('local')->path($file->path));
        $assert($image !== false && $image['mime'] === 'image/png' && $image[0] <= 6000 && $image[1] <= 6000, 'Generated media must be a valid bounded PNG.');
    }

    $oldAuditIds = array_keys($before['audit_entries'] ?? []);
    $newAuditRows = DB::table('audit_entries')->where('action', 'demo.created')
        ->when($oldAuditIds !== [], fn ($query) => $query->whereNotIn('id', $oldAuditIds))
        ->get();
    $assert($newAuditRows->every(fn ($row) => $row->actor_id === null && $row->outcome === 'succeeded'), 'Synthetic creation audits must be anonymous successful events.');
    foreach ($newAuditRows as $audit) {
        $table = match ($audit->source_module.'|'.$audit->source_type) {
            'Organization|company' => 'companies', 'Organization|branch' => 'branches', 'Organization|user' => 'users',
            'Products|category' => 'product_categories', 'Products|brand' => 'brands', 'Products|unit' => 'units_of_measure', 'Products|product' => 'products',
            default => null,
        };
        if ($table !== null) {
            $assert(DB::table($table)->where('id', $audit->source_id)->exists(), 'A demo audit must point to an actual source record.');
        }
    }

    app(RealisticDemoSeeder::class)->run();
    $secondRun = $fingerprint();
    $assert($firstRun === $secondRun, 'The second seed run must be idempotent.');
    foreach ($before as $table => $rows) {
        foreach ($rows as $id => $hash) {
            $assert(($firstRun[$table][$id] ?? null) === $hash, "The seeder modified pre-existing {$table} data.");
        }
    }
    $assert($before['permission_catalog'] === $firstRun['permission_catalog'], 'The permission catalog must remain unchanged.');
    $assert($before['roles'] === $firstRun['roles'] && $before['role_permissions'] === $firstRun['role_permissions'], 'Fixed roles and permission matrices must remain unchanged.');

    fwrite(STDOUT, "Demo checks passed: PostgreSQL test guard, preserved records, disabled users, 8 categories, 6 brands, 8 units, 30 products, valid private PNGs, and idempotent seeding.\n");
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::forgetDisk('local');
    File::deleteDirectory($diskRoot);
}

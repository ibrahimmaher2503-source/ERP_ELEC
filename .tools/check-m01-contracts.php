<?php

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\ErrorCode;
use App\Modules\Organization\Application\Contracts\GetBranchRequest;
use App\Modules\Organization\Application\Contracts\ListAllowedWarehousesRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\OrganizationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Bounded contract smoke; fixtures exist only in the isolated PostgreSQL test transaction.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Smoke check requires the separate PostgreSQL test database.');
}

function contractVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

function contractError(Closure $action, ErrorCode $expected, string $message): void
{
    try {
        $action();
    } catch (ContractException $exception) {
        contractVerify($exception->errorCode === $expected, $message);

        return;
    }
    throw new RuntimeException($message.' (no contract error)');
}

DB::beginTransaction();
try {
    $api = app(OrganizationApi::class);
    contractVerify($api instanceof OrganizationService && app(OrganizationApi::class) instanceof OrganizationService, 'OrganizationApi resolves through the OrganizationService binding');

    $requestTypes = [AuthorizeRequest::class, GetBranchRequest::class, ListAllowedWarehousesRequest::class];
    $resultTypes = [App\Modules\Organization\Application\Contracts\AuthorizeResult::class, App\Modules\Organization\Application\Contracts\GetBranchResult::class, App\Modules\Organization\Application\Contracts\ListAllowedWarehousesResult::class];
    foreach (array_merge($requestTypes, $resultTypes) as $type) {
        contractVerify((new ReflectionClass($type))->isReadOnly(), class_basename($type).' is readonly');
    }

    $actor = User::factory()->create();
    $inactive = User::factory()->create(['active' => false]);
    $unverified = User::factory()->create(['email_verified_at' => null]);
    DB::table('companies')->insertOrIgnore(['id' => 1, 'code' => 'API-SMOKE', 'name' => 'API Smoke Company', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $branchA = DB::table('branches')->insertGetId(['company_id' => 1, 'code' => 'API-A-'.$suffix, 'name' => 'API Branch A', 'address' => 'Local address', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchB = DB::table('branches')->insertGetId(['company_id' => 1, 'code' => 'API-B-'.$suffix, 'name' => 'API Branch B', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $warehouseA = Warehouse::query()->create(['branch_id' => $branchA, 'code' => 'API-WH-A-'.$suffix, 'name' => 'API Warehouse A', 'active' => true, 'version' => 1]);
    $warehouseB = Warehouse::query()->create(['branch_id' => $branchB, 'code' => 'API-WH-B-'.$suffix, 'name' => 'API Warehouse B', 'active' => true, 'version' => 1]);
    DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouseA->id, 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouseB->id, 'branch_id' => $branchB, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => 'M01.branch_view', 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);

    $allowed = $api->authorize(new AuthorizeRequest($actor->id, 'M01.branch_view', $branchA, null, ['id', 'name']));
    contractVerify($allowed->allowedFields === ['id', 'name'] && $allowed->branchIds === [$branchA] && $allowed->warehouseIds === [], 'authorize returns only requested safe branch fields and exact branch scope');
    $branch = $api->getBranch(new GetBranchRequest($actor->id, $branchA, $branchA, [], ['id', 'company_id', 'code', 'name', 'active', 'address']));
    contractVerify($branch->branch['id'] === $branchA && $branch->branch['company_id'] === 1 && $branch->branch['name'] === 'API Branch A', 'getBranch returns company-one safe scalar fields');
    contractVerify(count(array_filter($branch->branch, fn ($value) => ! is_scalar($value) && $value !== null)) === 0, 'getBranch result contains no model or object values');
    contractError(fn () => $api->getBranch(new GetBranchRequest($actor->id, $branchB)), ErrorCode::NotFound, 'cross-branch read is hidden');
    contractError(fn () => $api->getBranch(new GetBranchRequest($actor->id, $branchA, null, [], ['id', 'password'])), ErrorCode::Forbidden, 'sensitive branch fields are denied');
    contractError(fn () => $api->authorize(new AuthorizeRequest($actor->id, 'M01.branch_view', $branchA, null, ['password'])), ErrorCode::Forbidden, 'authorization field allowlist rejects secrets');

    DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M01.branch_view')->delete();
    contractError(fn () => $api->authorize(new AuthorizeRequest($actor->id, 'M01.branch_view', $branchA)), ErrorCode::Forbidden, 'revoked permission is denied immediately');
    contractError(fn () => $api->authorize(new AuthorizeRequest($inactive->id, 'M01.company_view')), ErrorCode::Forbidden, 'inactive actor is denied');
    contractError(fn () => $api->authorize(new AuthorizeRequest($unverified->id, 'M01.company_view')), ErrorCode::Forbidden, 'unverified actor is denied');

    contractError(fn () => new GetBranchRequest($actor->id, 0), ErrorCode::ValidationFailed, 'request constructor rejects invalid branch identifier');
    contractError(fn () => $api->getBranch(new GetBranchRequest($actor->id, $branchA, null, [], [], ['unsupported' => 'value'])), ErrorCode::ValidationFailed, 'branch query rejects unsupported filters');
    contractError(fn () => $api->listAllowedWarehouses(new ListAllowedWarehousesRequest($actor->id, null, [], [], ['unsupported' => 'value'])), ErrorCode::ValidationFailed, 'warehouse query validates filters before setup state');
    $allowedWarehouses = $api->listAllowedWarehouses(new ListAllowedWarehousesRequest($actor->id));
    contractVerify($allowedWarehouses->warehouseIds === [(int) $warehouseA->id] && $allowedWarehouses->total === 1,
        'warehouse listing intersects explicit warehouse and branch scopes');
    $filteredWarehouses = $api->listAllowedWarehouses(new ListAllowedWarehousesRequest($actor->id, $branchA, [(int) $warehouseA->id]));
    contractVerify($filteredWarehouses->warehouseIds === [(int) $warehouseA->id] && $filteredWarehouses->page === 1 && $filteredWarehouses->pageSize === 100,
        'warehouse listing honors requested branch and warehouse filters');
    $hiddenWarehouses = $api->listAllowedWarehouses(new ListAllowedWarehousesRequest($actor->id, $branchB));
    contractVerify($hiddenWarehouses->warehouseIds === [] && $hiddenWarehouses->total === 0,
        'warehouse listing hides a warehouse outside explicit branch scope');

    $branchRow = DB::table('branches')->where('id', $branchA)->first();
    contractVerify((int) $branchRow->company_id === 1 && DB::table('companies')->count() === 1, 'database enforces singleton company and branch association');
} finally {
    DB::rollBack();
    echo "Disposable test transaction rolled back.\n";
}

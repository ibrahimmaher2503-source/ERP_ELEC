<?php

use App\Models\User;
use App\Modules\System\Domain\DataOperationRun;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__).'/vendor/autoload.php';
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(ConsoleKernel::class)->bootstrap();

if (app()->environment() !== 'testing'
    || DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('HTTP operation route check requires the separate PostgreSQL test database.');
}

function operationRouteVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
}

function operationRouteGrant(User $user, string $permission): void
{
    DB::table('access_grants')->insert([
        'user_id' => $user->id,
        'permission_code' => $permission,
        'branch_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function operationRouteGet(User $user, string $path): Response
{
    $guard = app('auth')->guard('web');
    $guard->forgetUser();
    $guard->setUser($user);
    $request = Request::create($path, 'GET');
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);

    try {
        return $response;
    } finally {
        $kernel->terminate($request, $response);
    }
}

config(['session.driver' => 'array']);

DB::beginTransaction();
try {
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $otherActor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $viewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    operationRouteGrant($actor, 'M03.customer_view');
    operationRouteGrant($actor, 'M03.customer_import');
    operationRouteGrant($actor, 'M03.customer_bulk_update');
    operationRouteGrant($actor, 'M03.customer_template');
    operationRouteGrant($actor, 'M02.product_view');
    operationRouteGrant($actor, 'M02.product_import');
    operationRouteGrant($actor, 'M02.product_template');
    operationRouteGrant($viewer, 'M03.customer_view');

    $customerImport = DataOperationRun::factory()->create([
        'actor_id' => $actor->id,
        'entity' => 'customers',
        'kind' => 'import',
        'mode' => 'create',
        'template_version' => 'M03-customers-v1',
    ]);
    $customerBulk = DataOperationRun::factory()->create([
        'actor_id' => $actor->id,
        'entity' => 'customers',
        'kind' => 'bulk',
        'mode' => 'update',
        'template_version' => 'M03-customers-v1',
    ]);
    $productImport = DataOperationRun::factory()->create([
        'actor_id' => $actor->id,
        'entity' => 'products',
        'kind' => 'import',
        'mode' => 'create',
        'template_version' => 'M02-products-v1',
    ]);
    $foreignRun = DataOperationRun::factory()->create([
        'actor_id' => $otherActor->id,
        'entity' => 'customers',
        'kind' => 'import',
        'template_version' => 'M03-customers-v1',
    ]);

    foreach ([
        [$customerImport, 'M03-customers-v1', 'customer import'],
        [$customerBulk, 'M03-customers-v1', 'customer bulk'],
        [$productImport, 'M02-products-v1', 'product import'],
    ] as [$run, $marker, $label]) {
        $response = operationRouteGet($actor, route('operations.show', ['runId' => $run->id]));
        $status = $response->getStatusCode();
        operationRouteVerify($status === 200, "GET /data-operations/{runId} returns 200 for {$label} receipt (received HTTP {$status})");
        operationRouteVerify(str_contains($response->getContent(), $marker), "{$label} receipt resolves its entity from the owned run");
    }

    foreach ([
        ['/customers/import', 'M03-customers-v1', 'customer static import route'],
        ['/products/import', 'M02-products-v1', 'product static import route'],
        ['/data-operations/import/customers', 'M03-customers-v1', 'generic customer import route'],
    ] as [$path, $marker, $label]) {
        $response = operationRouteGet($actor, $path);
        operationRouteVerify($response->getStatusCode() === 200 && str_contains($response->getContent(), $marker), "{$label} resolves its route entity and returns 200");
    }

    $foreignResponse = operationRouteGet($actor, route('operations.show', ['runId' => $foreignRun->id]));
    operationRouteVerify($foreignResponse->getStatusCode() === 404, 'another actor cannot read a run receipt');

    $missingResponse = operationRouteGet($actor, route('operations.show', ['runId' => '00000000-0000-4000-8000-000000000001']));
    operationRouteVerify($missingResponse->getStatusCode() === 404, 'a missing run receipt returns 404');

    $deniedResponse = operationRouteGet($viewer, '/customers/import');
    operationRouteVerify($deniedResponse->getStatusCode() === 403, 'the customer import route still requires its import permission');
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    echo "Disposable HTTP route fixtures rolled back.\n";
}

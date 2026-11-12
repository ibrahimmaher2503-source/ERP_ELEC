<?php

use App\Models\User;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\SaveRolePermissions;
use App\Modules\Organization\Application\SetUserBranchScope;
use App\Modules\Organization\Presentation\UserAccessSection;
use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Presentation\CatalogPage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Role permission propagation check requires the separate PostgreSQL test database.');
}

function propagationVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
}

function propagationForbidden(Closure $action, string $message): void
{
    try {
        $action();
    } catch (HttpException $exception) {
        propagationVerify($exception->getStatusCode() === 403, $message);

        return;
    }

    throw new RuntimeException($message.' (request was allowed)');
}

function propagationValidation(Closure $action, string $message): void
{
    try {
        $action();
    } catch (ValidationException) {
        propagationVerify(true, $message);

        return;
    }

    throw new RuntimeException($message.' (invalid matrix was accepted)');
}

DB::beginTransaction();
try {
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert([
        'user_id' => $actor->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $roles = DB::table('roles')->orderBy('id')->get(['id', 'code']);
    propagationVerify($roles->count() === 14, 'all 14 fixed roles are present');

    $assign = app(AssignUserRole::class);
    $savePermissions = app(SaveRolePermissions::class);
    $access = app(PermissionAccess::class);
    $productAccess = app(ProductAccess::class);
    $api = app(OrganizationApi::class);

    foreach ($roles as $role) {
        $target = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $version = (int) DB::table('roles')->where('id', $role->id)->value('version');
        $assign->execute($actor, (int) $role->id, $target->id, true, $version);
        if ($role->code === 'Administrator') {
            $registeredPermissions = DB::table('permission_catalog')->orderBy('code')->pluck('code')->all();
            $administratorPermissions = DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all();
            propagationVerify($administratorPermissions === $registeredPermissions,
                'Administrator matrix contains every registered capability');
            propagationVerify($access->allows($target, 'M02.product_view')
                && $productAccess->allows($target->id, 'M02.product_view'),
                'Administrator membership grants the registered company-scoped M02 permission');
            propagationVerify(! $access->allows($target, 'M01.branch_view', 1)
                && ! $access->allows($target, 'M04.sales_order_view', 1),
                'Administrator membership does not create branch scope');

            continue;
        }

        propagationVerify(! $access->allows($target, 'M02.product_view'), "{$role->code}: non-administrator role assignment alone grants no M02 permission");

        $granted = ['M02.product_view'];
        $version = (int) DB::table('roles')->where('id', $role->id)->value('version');
        $savePermissions->execute($actor, (int) $role->id, $granted, $version);

        propagationVerify($access->allows($target, 'M02.product_view')
            && $productAccess->allows($target->id, 'M02.product_view'), "{$role->code}: assigned user's M02 permission becomes effective");
        if ($role->code === 'ManagerReadOnly') {
            propagationValidation(fn () => $savePermissions->execute($actor, (int) $role->id,
                ['M02.product_view', 'M02.product_update'], (int) DB::table('roles')->where('id', $role->id)->value('version')),
                'ManagerReadOnly still rejects write permissions');
        }
        $api->authorize(new AuthorizeRequest($target->id, 'M02.product_view'));
        propagationVerify(true, "{$role->code}: OrganizationApi authorizes the current M02 request");

        $catalogHtml = Livewire::actingAs($target)->test(CatalogPage::class)->html();
        propagationVerify(str_contains($catalogHtml, 'الأصناف')
            && str_contains($catalogHtml, route('products.index')), "{$role->code}: catalog route renders with its sidebar link");

        $version = (int) DB::table('roles')->where('id', $role->id)->value('version');
        $savePermissions->execute($actor, (int) $role->id, [], $version);
        propagationVerify(! $access->allows($target, 'M02.product_view'), "{$role->code}: permission revocation takes effect on the same user instance");
        Auth::setUser($target);
        propagationForbidden(fn () => (new CatalogPage)->boot(), "{$role->code}: catalog authorization denies after permission revocation");

        $version = (int) DB::table('roles')->where('id', $role->id)->value('version');
        $savePermissions->execute($actor, (int) $role->id, $granted, $version);
        propagationVerify($access->allows($target, 'M02.product_view'), "{$role->code}: permission can be granted to the current role member");

        $version = (int) DB::table('roles')->where('id', $role->id)->value('version');
        $assign->execute($actor, (int) $role->id, $target->id, false, $version);
        propagationVerify(! $access->allows($target, 'M02.product_view'), "{$role->code}: role permission without user membership is denied");
        propagationForbidden(fn () => (new CatalogPage)->boot(), "{$role->code}: catalog authorization denies after membership revocation");
    }

    $administrator = DB::table('roles')->where('code', 'Administrator')->first();
    $adminTarget = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $version = (int) DB::table('roles')->where('id', $administrator->id)->value('version');
    $assign->execute($actor, (int) $administrator->id, $adminTarget->id, true, $version);
    propagationVerify($access->allows($adminTarget, 'M02.product_view'), 'Administrator role grants registered company-level product access');
    propagationVerify(! $access->allows($adminTarget, 'M01.branch_view', 1), 'Administrator role does not imply branch authority');
    propagationVerify(! $access->allows($adminTarget, 'M02.product_view', 1), 'company M02 authority rejects branch-scoped requests');
    propagationForbidden(fn () => $assign->execute($actor, (int) $administrator->id, $actor->id, true,
        (int) DB::table('roles')->where('id', $administrator->id)->value('version')),
        'security administrator still cannot assign a role to themself');

    DB::table('companies')->insertOrIgnore([
        'id' => 1, 'code' => 'ROLE-PROPAGATION', 'name' => 'Role Propagation Check', 'currency' => 'EGP',
        'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    $branchA = DB::table('branches')->insertGetId([
        'company_id' => 1, 'code' => 'RP-A-'.$suffix, 'name' => 'Propagation A', 'active' => true,
        'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $branchB = DB::table('branches')->insertGetId([
        'company_id' => 1, 'code' => 'RP-B-'.$suffix, 'name' => 'Propagation B', 'active' => true,
        'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    propagationVerify(! $access->allows($adminTarget, 'M04.sales_order_view', $branchA),
        'Administrator Sales Order permission is denied before a branch scope is assigned');
    app(SetUserBranchScope::class)->execute($actor, $adminTarget->id, $branchA, true);
    propagationVerify($access->allows($adminTarget, 'M01.branch_view', $branchA)
        && ! $access->allows($adminTarget, 'M01.branch_view', $branchB)
        && $access->allows($adminTarget, 'M04.sales_order_view', $branchA)
        && ! $access->allows($adminTarget, 'M04.sales_order_view', $branchB),
        'Administrator branch permissions work only for the explicitly scoped branch');
    $salesRoleId = (int) DB::table('roles')->where('code', 'SalesClerk')->value('id');
    $branchTarget = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $version = (int) DB::table('roles')->where('id', $salesRoleId)->value('version');
    $savePermissions->execute($actor, $salesRoleId, ['M01.branch_view'], $version);
    $version = (int) DB::table('roles')->where('id', $salesRoleId)->value('version');
    $assign->execute($actor, $salesRoleId, $branchTarget->id, true, $version);
    app(SetUserBranchScope::class)->execute($actor, $branchTarget->id, $branchA, true);
    propagationVerify($access->allows($branchTarget, 'M01.branch_view', $branchA)
        && ! $access->allows($branchTarget, 'M01.branch_view', $branchB), 'branch role permission remains limited to its explicitly granted branch scope');

    DB::table('access_grants')->insert([
        'user_id' => $actor->id, 'permission_code' => 'M01.user_admin', 'branch_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $emptyRoleTarget = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $unassignedHtml = Livewire::actingAs($actor)->test(UserAccessSection::class,
        ['user' => $emptyRoleTarget->id])->html();
    propagationVerify(str_contains($unassignedHtml, 'لا توجد أدوار مسندة لهذا الحساب'), 'user access section renders its no-role empty state');
} finally {
    DB::rollBack();
    echo "Role permission propagation transaction rolled back.\n";
}

<?php

use App\Models\User;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\ErrorCode;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SaveRolePermissions;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Administrator-default checks require the exact separate PostgreSQL test database.');
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};

DB::beginTransaction();
try {
    $known = collect(array_keys(PermissionCatalog::DEFINITIONS))
        ->filter(fn (string $code) => DB::table('permission_catalog')->where('code', $code)->exists())
        ->sort()->values()->all();
    $administrator = DB::table('roles')->where('code', 'Administrator')->first();
    $verify($administrator !== null && $known !== [], 'Administrator and known registered permission catalog entries exist');

    $adminMatrix = DB::table('role_permissions')->where('role_id', $administrator->id)->orderBy('permission_code')->pluck('permission_code')->all();
    $otherMatrixBefore = DB::table('role_permissions')->where('role_id', '<>', $administrator->id)->orderBy('role_id')->orderBy('permission_code')->get(['role_id', 'permission_code'])->toArray();
    $verify($adminMatrix === $known, 'Administrator matrix contains every current registered known permission');

    $access = app(PermissionAccess::class);
    $admin = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $unassignedAdmin = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $roleless = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_roles')->insert(['user_id' => $admin->id, 'role_id' => $administrator->id, 'created_at' => now(), 'updated_at' => now()]);
    $verify(! in_array(false, array_map(fn (string $code) => $access->roleCapability($admin->id, $code), $known), true), 'Administrator membership grants every known registered capability');
    $verify(! $access->roleCapability($unassignedAdmin->id, 'M04.quotation_view') && ! $access->roleCapability($roleless->id, 'M04.quotation_view'), 'Administrator role name without user membership grants no capability');
    $verify(! $access->roleCapability($admin->id, 'M99.unknown'), 'Unknown permission keys are rejected for Administrator');

    $unverifiedAdmin = User::factory()->create(['active' => true, 'email_verified_at' => null]);
    DB::table('user_roles')->insert(['user_id' => $unverifiedAdmin->id, 'role_id' => $administrator->id, 'created_at' => now(), 'updated_at' => now()]);
    $verify($access->allows($unverifiedAdmin, 'M04.quotation_view'), 'Active unverified Administrator has its role capability');
    try {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($unverifiedAdmin->id, 'M04.quotation_view'));
        throw new RuntimeException('Organization API accepted an unverified Administrator.');
    } catch (ContractException $exception) {
        $verify($exception->errorCode === ErrorCode::Forbidden, 'Organization API preserves its verified-identity gate for Administrator');
    }

    $verify(! $access->allows($admin, 'M01.branch_view', 1), 'Assigned Administrator branch permission remains limited to explicit branch scope');
    $verify(! $access->allows($admin, 'M04.quotation_view', null, 1), 'Assigned Administrator still cannot access a warehouse');

    $missing = $known[0];
    DB::table('role_permissions')->where('role_id', $administrator->id)->where('permission_code', $missing)->delete();
    $verify($access->roleCapability($admin->id, $missing), 'Administrator membership retains a known capability when its matrix row is absent');
    $versionBeforeSync = (int) DB::table('roles')->where('id', $administrator->id)->value('version');
    $auditBeforeSync = DB::table('access_audits')->where('action', 'role.administrator_defaults_synced')->count();
    app(SyncAdministratorPermissions::class)->execute();
    $versionAfterSync = (int) DB::table('roles')->where('id', $administrator->id)->value('version');
    $auditAfterSync = DB::table('access_audits')->where('action', 'role.administrator_defaults_synced')->count();
    $verify(DB::table('role_permissions')->where('role_id', $administrator->id)->where('permission_code', $missing)->exists()
        && $versionAfterSync === $versionBeforeSync + 1 && $auditAfterSync === $auditBeforeSync + 1,
        'Synchronizer restores a missing Administrator key with one role version bump and audit record');

    $added = app(SyncAdministratorPermissions::class)->execute();
    $verify($added === [] && (int) DB::table('roles')->where('id', $administrator->id)->value('version') === $versionAfterSync
        && DB::table('access_audits')->where('action', 'role.administrator_defaults_synced')->count() === $auditAfterSync,
        'Repeated synchronization is idempotent and creates no extra version or audit change');
    $otherMatrixAfter = DB::table('role_permissions')->where('role_id', '<>', $administrator->id)->orderBy('role_id')->orderBy('permission_code')->get(['role_id', 'permission_code'])->toArray();
    $verify($otherMatrixAfter == $otherMatrixBefore, 'Synchronization leaves every other role permission unchanged');

    try {
        app(SaveRolePermissions::class)->execute($admin, (int) $administrator->id, [], (int) DB::table('roles')->where('id', $administrator->id)->value('version'));
        throw new RuntimeException('Administrator manual matrix edits were accepted.');
    } catch (ValidationException) {
        $verify(true, 'Administrator matrix cannot be manually reduced');
    }

    // Isolate the global security-administrator invariant inside this rollback fixture.
    DB::table('access_grants')->where('permission_code', 'M01.security_admin')->delete();
    DB::table('user_roles')->where('role_id', $administrator->id)->where('user_id', '<>', $admin->id)->delete();
    DB::table('role_permissions')->where('permission_code', 'M01.security_admin')->where('role_id', '<>', $administrator->id)->delete();
    DB::table('user_roles')->whereIn('role_id', DB::table('role_permissions')->select('role_id')->where('permission_code', 'M01.security_admin'))->where('user_id', '<>', $admin->id)->delete();
    DB::table('role_permissions')->where('role_id', $administrator->id)->where('permission_code', 'M01.security_admin')->delete();
    app(RbacGuard::class)->assertAdministratorRemains();
    $verify(true, 'Active verified Administrator membership satisfies the security invariant without a matrix security key');

    DB::table('user_roles')->where('user_id', $admin->id)->where('role_id', $administrator->id)->delete();
    $verify(! $access->roleCapability($admin->id, 'M04.quotation_view'), 'Removing Administrator membership immediately removes role capability');
    try {
        app(RbacGuard::class)->assertAdministratorRemains();
        throw new RuntimeException('Security invariant accepted removal of its only Administrator membership.');
    } catch (ValidationException) {
        $verify(true, 'Security invariant rejects removal of its only active verified Administrator');
    }

    $inactiveAdmin = User::factory()->create(['active' => false, 'email_verified_at' => now()]);
    DB::table('user_roles')->insert(['user_id' => $inactiveAdmin->id, 'role_id' => $administrator->id, 'created_at' => now(), 'updated_at' => now()]);
    $verify(! $access->allows($inactiveAdmin, 'M04.quotation_view'), 'Inactive account gate remains effective for Administrator');
} finally {
    DB::rollBack();
    echo "Administrator-default check transaction rolled back.\n";
}

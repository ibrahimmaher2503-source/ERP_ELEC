<?php

use App\Models\User;
use App\Modules\Organization\Application\AssignUserRole;
use App\Modules\Organization\Application\ChangeUserStatus;
use App\Modules\Organization\Application\OrganizationAccess;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\ResetManagedUserPassword;
use App\Modules\Organization\Application\RevokeDirectGrant;
use App\Modules\Organization\Application\SaveRolePermissions;
use App\Modules\Organization\Application\SaveUser;
use App\Modules\Organization\Application\SetUserBranchScope;
use App\Modules\Organization\Application\UserAccess;
use App\Modules\Organization\Application\UserSecurityHistory;
use App\Modules\Organization\Presentation\RoleDetailsPage;
use App\Modules\Organization\Presentation\RolesPage;
use App\Modules\Organization\Presentation\UserAccessSection;
use App\Modules\Organization\Presentation\UserDetailsPage;
use App\Modules\Organization\Presentation\UsersPage;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Disposable RBAC smoke check; all fixtures and security changes roll back in the isolated PostgreSQL test database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('RBAC smoke check requires the separate PostgreSQL test database.');
}

function verify(bool $result, string $message): void
{
    if (! $result) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

function forbidden(Closure $action): void
{
    try {
        $action();
    } catch (HttpException $exception) {
        verify(in_array($exception->getStatusCode(), [403, 404], true), 'unauthorized or hidden action rejected');

        return;
    }
    throw new RuntimeException('Unauthorized action was allowed.');
}

function invalid(Closure $action, string $message): void
{
    try {
        $action();
    } catch (ValidationException) {
        verify(true, $message);

        return;
    }
    throw new RuntimeException($message.' was not rejected.');
}

DB::beginTransaction();
try {
    $admin = User::factory()->create();
    $subject = User::factory()->create();
    $branchBUser = User::factory()->create();
    $directUser = User::factory()->create();
    $disabledAdmin = User::factory()->create(['active' => false]);
    $readOnly = User::factory()->create();
    $manager = User::factory()->create();
    $inactiveSecurity = User::factory()->create(['active' => false]);
    $scopedSecurity = User::factory()->create();
    $failedLoginUser = User::factory()->create();
    $successfulLoginUser = User::factory()->create(['last_login_at' => null]);
    $resetLinkUser = User::factory()->create();
    $passwordChangeUser = User::factory()->create(['password' => 'Secret!OldPassword2026']);
    $legacyHistoryTarget = User::factory()->create();
    DB::table('companies')->insertOrIgnore(['id' => 1, 'code' => 'M01-RBAC-SMOKE', 'name' => 'M01 RBAC Smoke', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    $branchA = DB::table('branches')->insertGetId(['company_id' => 1, 'code' => 'RBAC-A-'.$suffix, 'name' => 'RBAC Smoke A', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchB = DB::table('branches')->insertGetId(['company_id' => 1, 'code' => 'RBAC-B-'.$suffix, 'name' => 'RBAC Smoke B', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('users')->where('id', $subject->id)->update(['branch_id' => $branchA]);
    DB::table('users')->where('id', $branchBUser->id)->update(['branch_id' => $branchB]);
    DB::table('access_grants')->insert(['user_id' => $scopedSecurity->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $scopedViewGrantId = DB::table('access_grants')->insertGetId(['user_id' => $scopedSecurity->id, 'permission_code' => 'M01.user_view', 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);
    $adminGrantId = DB::table('access_grants')->insertGetId(['user_id' => $admin->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $admin->id, 'permission_code' => 'M01.user_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $disabledAdmin->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $manager->id, 'permission_code' => 'M01.user_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('access_grants')->insert(['user_id' => $inactiveSecurity->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $readOnlyRoleId = (int) DB::table('roles')->where('code', 'ManagerReadOnly')->value('id');
    DB::table('user_roles')->insert(['user_id' => $readOnly->id, 'role_id' => $readOnlyRoleId, 'created_at' => now(), 'updated_at' => now()]);

    $access = app(PermissionAccess::class);
    verify($access->allows($admin, 'M01.security_admin') && ! DB::table('user_roles')->where('user_id', $admin->id)->exists(), 'verified bootstrap actor uses direct security grant without Administrator role');
    $readOnlyPermissions = DB::table('role_permissions')->where('role_id', $readOnlyRoleId)->orderBy('permission_code')->pluck('permission_code')->all();
    $readOnlySalesPermissions = ['M04.sales_order_view', 'M04.sales_order_history', 'M04.sales_order_invoice_view', 'M04.sales_order_return_view'];
    app(SaveRolePermissions::class)->execute($admin, $readOnlyRoleId, array_values(array_unique(array_merge($readOnlyPermissions, $readOnlySalesPermissions))), (int) DB::table('roles')->where('id', $readOnlyRoleId)->value('version'));
    $updatedReadOnlyPermissions = DB::table('role_permissions')->where('role_id', $readOnlyRoleId)->orderBy('permission_code')->pluck('permission_code')->all();
    verify(array_diff($readOnlySalesPermissions, $updatedReadOnlyPermissions) === [], 'ManagerReadOnly accepts all registered Sales Order read-only permissions');
    $administratorRole = DB::table('roles')->where('code', 'Administrator')->first();
    $knownPermissionCodes = collect(array_keys(PermissionCatalog::DEFINITIONS))->sort()->values()->all();
    $administratorPermissionCodes = $administratorRole
        ? DB::table('role_permissions')->where('role_id', $administratorRole->id)->orderBy('permission_code')->pluck('permission_code')->all()
        : [];
    verify(DB::table('roles')->count() === 14 && $administratorRole?->protected
        && $administratorPermissionCodes === $knownPermissionCodes,
        '14 approved roles seed with Administrator synchronized to every registered permission');
    $administratorMember = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('user_roles')->insert(['user_id' => $administratorMember->id, 'role_id' => $administratorRole->id, 'created_at' => now(), 'updated_at' => now()]);
    verify($access->allows($administratorMember, 'M02.product_view')
        && ! $access->allows($administratorMember, 'M04.sales_order_view', $branchA),
        'Administrator membership grants registered company capability but requires branch scope');
    DB::table('user_branch_scopes')->insert(['user_id' => $administratorMember->id, 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);
    verify($access->allows($administratorMember, 'M04.sales_order_view', $branchA)
        && ! $access->allows($administratorMember, 'M04.sales_order_view', $branchB),
        'Administrator branch capability is limited to the explicitly scoped branch');
    $expectedM01Permissions = collect(array_keys(PermissionCatalog::DEFINITIONS))->filter(fn ($code) => str_starts_with($code, 'M01.'))->sort()->values()->all();
    $expectedM02Permissions = collect(array_keys(PermissionCatalog::DEFINITIONS))->filter(fn ($code) => str_starts_with($code, 'M02.'))->sort()->values()->all();
    $m01Permissions = DB::table('permission_catalog')->where('code', 'like', 'M01.%')->orderBy('code')->pluck('code')->all();
    $m02Catalog = DB::table('permission_catalog')->where('code', 'like', 'M02.%')->orderBy('code')->get(['code', 'scope']);
    verify($m01Permissions === $expectedM01Permissions
        && $m02Catalog->pluck('code')->all() === $expectedM02Permissions
        && $m02Catalog->every(fn ($permission) => $permission->scope === 'company'),
        'permission catalog matches current M01 and M02 definitions with company-scoped M02 permissions');
    verify(! $access->allows($admin, 'M14.payroll_view') && ! $access->allows($admin, 'M06.warehouse_view', $branchA)
        && ! $access->allows($admin, 'M99.unknown_permission') && ! $access->allows($admin, 'M01.branch_view', $branchA, 1),
        'unknown, finance, warehouse, and warehouse-scoped permissions fail closed');

    $legacyAuditId = DB::table('audit_entries')->insertGetId([
        'actor_id' => null, 'source_module' => 'Organization', 'source_type' => 'user', 'source_id' => $legacyHistoryTarget->id,
        'action' => 'legacy_fixture', 'safe_before' => json_encode(['name' => 'قبل', 'ip_address' => '198.51.100.73']),
        'safe_after' => json_encode(['name' => 'بعد', 'active' => true, 'password' => 'NOT_A_REAL_CREDENTIAL', 'token' => 'NOT_A_REAL_TOKEN']),
        'correlation_id' => (string) Str::uuid(), 'occurred_at' => now(), 'outcome' => null,
    ]);
    $legacyAccessAuditId = DB::table('access_audits')->insertGetId([
        'actor_id' => $admin->id, 'subject_user_id' => $subject->id, 'action' => 'legacy_fixture',
        'before_snapshot' => json_encode(['branches' => [], 'password' => 'NOT_A_REAL_CREDENTIAL']),
        'after_snapshot' => json_encode(['branches' => [$branchA], 'email' => 'NOT_A_REAL_EMAIL@example.test', 'token' => 'NOT_A_REAL_TOKEN', 'ip_address' => '198.51.100.73']),
        'occurred_at' => now(), 'outcome' => null,
    ]);
    $legacyAccessSnapshot = DB::table('access_audits')->where('id', $legacyAccessAuditId)->first(['before_snapshot', 'after_snapshot', 'outcome']);
    $legacyActivity = app(UserSecurityHistory::class)->forUser($admin, $legacyHistoryTarget->id)->first();
    verify($legacyActivity['outcome'] === null && $legacyActivity['before'] === ['الاسم' => 'قبل']
        && $legacyActivity['after'] === ['الاسم' => 'بعد', 'نشط' => 'نعم']
        && $legacyActivity['actor'] === 'فاعل غير موثّق (طلب عام)', 'anonymous legacy activity preserves nullable outcome and exposes only approved fields');
    $immutableRejected = false;
    try {
        DB::transaction(fn () => DB::table('audit_entries')->where('id', $legacyAuditId)->update(['outcome' => 'succeeded']));
    } catch (QueryException) {
        $immutableRejected = true;
    }
    verify($immutableRejected && DB::table('audit_entries')->where('id', $legacyAuditId)->value('outcome') === null,
        'legacy system audit outcome remains immutable and nullable');

    event(new Failed('web', null, ['email' => $failedLoginUser->email, 'password' => 'FailedLoginSecret2026', 'ip_address' => '198.51.100.73']));
    $failedLoginAudit = DB::table('audit_entries')->where('source_type', 'user')->where('source_id', $failedLoginUser->id)->where('action', 'login_failed')->first();
    $failedLoginPayload = json_encode([$failedLoginAudit?->safe_before, $failedLoginAudit?->safe_after]);
    verify($failedLoginAudit && $failedLoginAudit->actor_id === null && $failedLoginAudit->outcome === 'failed'
        && json_decode($failedLoginAudit->safe_after, true) === []
        && ! str_contains($failedLoginPayload, 'FailedLoginSecret2026') && ! str_contains($failedLoginPayload, '198.51.100.73')
        && ! str_contains($failedLoginPayload, $failedLoginUser->email), 'known failed login is anonymous and stores no credentials, email, or IP');
    $failedLoginHistory = app(UserSecurityHistory::class)->forUser($admin, $failedLoginUser->id)->first();
    verify($failedLoginHistory['outcome'] === 'failed' && $failedLoginHistory['actor'] === 'فاعل غير موثّق (طلب عام)'
        && $failedLoginHistory['label'] === 'محاولة دخول فاشلة', 'failed login appears as anonymous failed security activity');

    event(new Login('web', $successfulLoginUser, false));
    $successfulLoginAudits = DB::table('audit_entries')->where('source_type', 'user')->where('source_id', $successfulLoginUser->id)->where('action', 'login_succeeded')->get();
    verify($successfulLoginAudits->count() === 1 && $successfulLoginAudits->first()->outcome === 'succeeded'
        && DB::table('users')->where('id', $successfulLoginUser->id)->value('last_login_at') !== null,
        'successful login updates last_login_at and records exactly one success event');

    event(new PasswordResetLinkSent($resetLinkUser));
    $resetLinkAudit = DB::table('audit_entries')->where('source_type', 'user')->where('source_id', $resetLinkUser->id)->where('action', 'password_reset_link_sent')->first();
    verify($resetLinkAudit && $resetLinkAudit->actor_id === null && $resetLinkAudit->outcome === 'succeeded'
        && json_decode($resetLinkAudit->safe_after, true) === [] && ! str_contains((string) $resetLinkAudit->safe_after, $resetLinkUser->email),
        'password reset link delivery is recorded without token or email metadata');

    $passwordSession = 'rbac-self-password-'.$suffix;
    DB::table('sessions')->insert(['id' => $passwordSession, 'user_id' => $passwordChangeUser->id, 'payload' => 'smoke', 'last_activity' => now()->timestamp]);
    DB::table('password_reset_tokens')->insert(['email' => $passwordChangeUser->email, 'token' => 'NOT_A_REAL_RESET_TOKEN', 'created_at' => now()]);
    $passwordSessionVersion = (int) DB::table('users')->where('id', $passwordChangeUser->id)->value('session_version');
    Livewire::actingAs($passwordChangeUser)->test('pages::settings.security')
        ->set('current_password', 'Secret!OldPassword2026')->set('password', 'Secret!NewPassword2026')
        ->set('password_confirmation', 'Secret!NewPassword2026')->call('updatePassword')->assertHasNoErrors();
    $passwordChangeAudits = DB::table('audit_entries')->where('source_type', 'user')->where('source_id', $passwordChangeUser->id)->where('action', 'self_password_changed')->get();
    $passwordChangeAudit = $passwordChangeAudits->first();
    verify($passwordChangeAudits->count() === 1 && $passwordChangeAudit->actor_id === $passwordChangeUser->id && $passwordChangeAudit->outcome === 'succeeded'
        && json_decode($passwordChangeAudit->safe_after, true) === ['sessions_revoked' => true]
        && password_verify('Secret!NewPassword2026', DB::table('users')->where('id', $passwordChangeUser->id)->value('password'))
        && (int) DB::table('users')->where('id', $passwordChangeUser->id)->value('session_version') === $passwordSessionVersion + 1
        && ! DB::table('sessions')->where('id', $passwordSession)->exists()
        && ! DB::table('password_reset_tokens')->where('email', $passwordChangeUser->email)->exists(), 'self password change revokes sessions and reset tokens with one secret-free audit event');

    forbidden(fn () => app(SaveRolePermissions::class)->execute($disabledAdmin, 1, [], 1));
    verify(! $access->allows($readOnly, 'M01.user_create') && ! $access->allows($readOnly, 'M01.company_update'), 'ManagerReadOnly has no write permissions');
    $readOnlyPermissions = DB::table('role_permissions')->where('role_id', $readOnlyRoleId)->orderBy('permission_code')->pluck('permission_code')->all();
    invalid(fn () => app(SaveRolePermissions::class)->execute($admin, $readOnlyRoleId, ['M01.user_create'], (int) DB::table('roles')->where('id', $readOnlyRoleId)->value('version')), 'ManagerReadOnly rejects write permission assignment');
    verify(DB::table('role_permissions')->where('role_id', $readOnlyRoleId)->orderBy('permission_code')->pluck('permission_code')->all() === $readOnlyPermissions, 'rejected ManagerReadOnly write leaves permission matrix unchanged');
    forbidden(fn () => app(SaveUser::class)->execute($readOnly, ['name' => 'Denied', 'email' => 'rbac-readonly@example.test', 'gender' => 'male', 'password' => 'Secret!ReadOnly2026', 'password_confirmation' => 'Secret!ReadOnly2026']));
    $inactiveSecurityVersion = (int) DB::table('users')->where('id', $inactiveSecurity->id)->value('version');
    forbidden(fn () => app(SaveUser::class)->execute($manager, ['name' => $inactiveSecurity->name, 'email' => $inactiveSecurity->email, 'gender' => 'female'], $inactiveSecurity->id, $inactiveSecurityVersion));
    forbidden(fn () => app(ChangeUserStatus::class)->execute($manager, $inactiveSecurity->id, true, $inactiveSecurityVersion));
    forbidden(fn () => app(ResetManagedUserPassword::class)->execute($manager, $inactiveSecurity->id, ['password' => 'Secret!ProtectedUser2026', 'password_confirmation' => 'Secret!ProtectedUser2026'], $inactiveSecurityVersion));

    $businessRole = DB::table('roles')->where('code', 'Accountant')->first();
    abort_unless($businessRole, 500, 'Expected approved Accountant role fixture.');
    $roleId = (int) $businessRole->id;
    $saveRole = app(SaveRolePermissions::class);
    $assignRole = app(AssignUserRole::class);
    $setScope = app(SetUserBranchScope::class);
    $revokeGrant = app(RevokeDirectGrant::class);
    $beforeAuditId = DB::table('access_audits')->max('id') ?? 0;

    $saveRole->execute($admin, $roleId, ['M01.branch_view', 'M01.user_view', 'M01.user_create'], 1);
    verify(DB::table('role_permissions')->where('role_id', $roleId)->count() === 3, 'business role permission matrix persisted');
    $roleVersion = (int) DB::table('roles')->where('id', $roleId)->value('version');
    $assignRole->execute($admin, $roleId, $subject->id, true, $roleVersion);
    $roleVersion = (int) DB::table('roles')->where('id', $roleId)->value('version');
    $setScope->execute($admin, $subject->id, $branchA, true);
    verify($access->allows($subject, 'M01.branch_view', $branchA) && ! $access->allows($subject, 'M01.branch_view', $branchB)
        && ! $access->allows($subject, 'M01.branch_view') && $access->allows($subject, 'M01.user_create')
        && ! $access->allows($subject, 'M01.user_create', $branchA), 'branch roles require exact branch scope while company roles need no location');
    verify(app(OrganizationAccess::class)->branches($subject)->pluck('id')->map(fn ($id) => (int) $id)->all() === [$branchA]
        && ! app(UserAccess::class)->query($subject)->where('u.id', $branchBUser->id)->exists(), 'organization and user queries inherit exact RBAC branch scope');

    $saveRole->execute($admin, $roleId, ['M01.user_create'], $roleVersion);
    verify(! $access->allows($subject, 'M01.branch_view', $branchA) && $access->allows($subject, 'M01.user_create'), 'role permission revocation takes effect immediately');
    invalid(fn () => $saveRole->execute($admin, $roleId, ['M01.user_view'], $roleVersion), 'stale role matrix version rejected');
    verify(DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission_code')->all() === ['M01.user_create'], 'stale role save leaves current permissions unchanged');
    $setScope->execute($admin, $subject->id, $branchA, false);
    verify(DB::table('user_branch_scopes')->where('user_id', $subject->id)->where('branch_id', $branchA)->doesntExist(), 'branch scope revocation persisted');

    forbidden(fn () => $assignRole->execute($admin, $roleId, $admin->id, true, (int) DB::table('roles')->where('id', $roleId)->value('version')));
    forbidden(fn () => $setScope->execute($admin, $admin->id, $branchA, true));
    $selfGrantId = DB::table('access_grants')->insertGetId(['user_id' => $admin->id, 'permission_code' => 'M01.company_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    forbidden(fn () => $revokeGrant->execute($admin, $selfGrantId));
    forbidden(fn () => $revokeGrant->execute($admin, $adminGrantId));

    DB::table('user_roles')->insert(['user_id' => $admin->id, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
    forbidden(fn () => $saveRole->execute($admin, $roleId, ['M01.user_create'], (int) DB::table('roles')->where('id', $roleId)->value('version')));
    DB::table('user_roles')->where('user_id', $admin->id)->where('role_id', $roleId)->delete();

    $administratorPermissionsBefore = DB::table('role_permissions')->where('role_id', $administratorRole->id)->orderBy('permission_code')->pluck('permission_code')->all();
    invalid(fn () => $saveRole->execute($admin, $administratorRole->id, [], (int) $administratorRole->version), 'Administrator permission matrix is synchronized rather than manually editable');
    verify($administratorPermissionsBefore === $knownPermissionCodes
        && DB::table('role_permissions')->where('role_id', $administratorRole->id)->orderBy('permission_code')->pluck('permission_code')->all() === $knownPermissionCodes
        && (int) DB::table('roles')->where('id', $administratorRole->id)->value('version') === (int) $administratorRole->version,
        'rejected Administrator matrix edit preserves every registered permission and version');

    $directGrantId = DB::table('access_grants')->insertGetId(['user_id' => $directUser->id, 'permission_code' => 'M01.branch_view', 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);
    verify($access->allows($directUser, 'M01.branch_view', $branchA), 'direct scoped grant authorizes its exact branch');
    $revokeGrant->execute($admin, $directGrantId);
    verify(! $access->allows($directUser, 'M01.branch_view', $branchA), 'direct grant revocation takes effect immediately');

    $rolesPage = Livewire::actingAs($admin)->test(RolesPage::class)->assertSee('Administrator')->assertSee('Accountant');
    $rolesPage->call('selectRole', $roleId)->assertSet('selectedRole', $roleId)->assertSee($businessRole->name);
    $embeddedRole = Livewire::actingAs($admin)->test(RoleDetailsPage::class, ['role' => $roleId, 'embedded' => true])
        ->assertSee($businessRole->name)->assertSet('embedded', true)->assertSet('selectedPermissions', ['M01.user_create']);
    $embeddedRole->call('togglePermission', 'M01.branch_view')
        ->assertSet('selectedPermissions', ['M01.user_create', 'M01.branch_view']);
    verify(DB::table('role_permissions')->where('role_id', $roleId)->orderBy('permission_code')->pluck('permission_code')->all() === ['M01.user_create'], 'embedded role permission toggle changes draft only before save');
    $embeddedRole->call('savePermissions')->assertHasNoErrors()->assertDispatched('role-matrix-updated');
    verify(DB::table('role_permissions')->where('role_id', $roleId)->orderBy('permission_code')->pluck('permission_code')->all() === ['M01.branch_view', 'M01.user_create'], 'embedded role save persists draft permissions');
    Livewire::actingAs($admin)->test(RoleDetailsPage::class, ['role' => $administratorRole->id, 'embedded' => true])
        ->call('togglePermission', 'M01.security_admin')->assertForbidden();
    Livewire::actingAs($admin)->test(RoleDetailsPage::class, ['role' => $readOnlyRoleId, 'embedded' => true])
        ->call('togglePermission', 'M01.user_create')->assertForbidden();
    $uiRoleUser = User::factory()->create();
    $userAccess = Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $uiRoleUser->id])
        ->set('selectedRole', (string) $roleId)->call('assignRole')->assertHasNoErrors()->assertDispatched('user-access-updated');
    verify(DB::table('user_roles')->where('user_id', $uiRoleUser->id)->where('role_id', $roleId)->exists()
        && $access->allows($uiRoleUser, 'M01.user_create'), 'user access section assigns role and permission becomes effective immediately');
    $userAccess->call('revokeRole', $roleId)->assertHasNoErrors()->assertDispatched('user-access-updated');
    verify(! DB::table('user_roles')->where('user_id', $uiRoleUser->id)->where('role_id', $roleId)->exists()
        && ! $access->allows($uiRoleUser, 'M01.user_create'), 'user access section role revocation immediately removes effective permission');

    $userAccess = Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $subject->id])
        ->assertSee('RBAC Smoke A')->set('selectedBranch', (string) $branchB)->call('grantScope')->assertHasNoErrors()->assertDispatched('user-access-updated');
    verify($access->allows($subject, 'M01.branch_view', $branchB) && ! $access->allows($subject, 'M01.branch_view', $branchA)
        && DB::table('access_audits')->where('subject_user_id', $subject->id)->where('action', 'scope.branch_granted')->where('outcome', 'succeeded')->exists(), 'branch scope grant is exact, effective immediately, and audited with a succeeded outcome');
    $userAccess->call('revokeScope', $branchB)->assertHasNoErrors()->assertDispatched('user-access-updated');
    verify(! $access->allows($subject, 'M01.branch_view', $branchB)
        && DB::table('access_audits')->where('subject_user_id', $subject->id)->where('action', 'scope.branch_revoked')->exists(), 'branch scope revocation removes effective access and is audited');
    app(SetUserBranchScope::class)->execute($admin, $subject->id, $branchA, true);
    $guardedMutation = Livewire::actingAs($scopedSecurity)->test(UserAccessSection::class, ['user' => $subject->id]);
    $guardedRender = Livewire::actingAs($scopedSecurity)->test(UserAccessSection::class, ['user' => $subject->id]);
    $scopedAuditCount = DB::table('access_audits')->where('actor_id', $scopedSecurity->id)->where('subject_user_id', $subject->id)->count();
    DB::table('access_grants')->where('id', $scopedViewGrantId)->delete();
    $guardedMutation->call('revokeScope', $branchA)->assertForbidden();
    $guardedRender->set('selectedBranch', (string) $branchB)->assertForbidden();
    verify(DB::table('user_branch_scopes')->where('user_id', $subject->id)->where('branch_id', $branchA)->exists()
        && DB::table('access_audits')->where('actor_id', $scopedSecurity->id)->where('subject_user_id', $subject->id)->count() === $scopedAuditCount,
        'losing target user_view blocks mounted access mutations and rerender without changes');
    $historyOnlyHtml = Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $subject->id, 'historyOnly' => true])->html();
    verify(str_contains($historyOnlyHtml, 'سجل تغييرات الوصول') && str_contains($historyOnlyHtml, 'RBAC Smoke A')
        && str_contains($historyOnlyHtml, $admin->name) && str_contains($historyOnlyHtml, 'غير معروف')
        && ! str_contains($historyOnlyHtml, 'NOT_A_REAL_CREDENTIAL') && ! str_contains($historyOnlyHtml, 'NOT_A_REAL_TOKEN')
        && ! str_contains($historyOnlyHtml, 'NOT_A_REAL_EMAIL') && ! str_contains($historyOnlyHtml, '198.51.100.73'),
        'permission history is rendered with actor, normalized branch snapshots, nullable outcomes, and no secret fields');
    $legacyAccessCurrent = DB::table('access_audits')->where('id', $legacyAccessAuditId)->first(['before_snapshot', 'after_snapshot', 'outcome']);
    verify($legacyAccessCurrent->outcome === $legacyAccessSnapshot->outcome
        && json_decode($legacyAccessCurrent->before_snapshot, true) === json_decode($legacyAccessSnapshot->before_snapshot, true)
        && json_decode($legacyAccessCurrent->after_snapshot, true) === json_decode($legacyAccessSnapshot->after_snapshot, true),
        'legacy permission history retains nullable outcome and snapshots unchanged');
    $accessImmutableRejected = false;
    try {
        DB::transaction(fn () => DB::table('access_audits')->where('id', $legacyAccessAuditId)->update(['outcome' => 'succeeded']));
    } catch (QueryException) {
        $accessImmutableRejected = true;
    }
    verify($accessImmutableRejected && DB::table('access_audits')->where('id', $legacyAccessAuditId)->value('outcome') === null,
        'legacy permission audit outcome cannot be rewritten');
    event(new Failed('web', null, ['email' => $subject->email, 'password' => 'NoActivityDisclosureSecret2026']));
    $securityDetailsHtml = Livewire::actingAs($admin)->test(UserDetailsPage::class, ['user' => $subject->id])->html();
    $completeTabSelector = "[data-tab=' + tab + ']";
    $securityDetailsChecks = [
        'basic_tab' => str_contains($securityDetailsHtml, 'المعلومات الأساسية'),
        'access_tab' => str_contains($securityDetailsHtml, 'الأدوار ونطاقات الوصول'),
        'history_tab' => str_contains($securityDetailsHtml, 'سجل الصلاحيات'),
        'security_tab' => str_contains($securityDetailsHtml, 'الأمان والنشاط'),
        'keyboard_selector' => str_contains($securityDetailsHtml, $completeTabSelector),
        'failed_login_activity' => str_contains($securityDetailsHtml, 'محاولة دخول فاشلة'),
    ];
    verify(! in_array(false, $securityDetailsChecks, true), 'security-admin user details renders four tabs, keyboard selector, and security activity '.json_encode($securityDetailsChecks));
    $managerDetailsHtml = Livewire::actingAs($manager)->test(UserDetailsPage::class, ['user' => $subject->id])->html();
    verify(! str_contains($managerDetailsHtml, 'محاولة دخول فاشلة'), 'security activity content is hidden from user-admin without security grant');
    forbidden(fn () => app(UserSecurityHistory::class)->forUser($manager, $subject->id));
    forbidden(fn () => app(UserSecurityHistory::class)->forUser($scopedSecurity, $branchBUser->id));

    $redirectEmail = 'rbac-created-'.$suffix.'@example.test';
    $createUserPage = Livewire::actingAs($admin)->test(UsersPage::class)->call('create')
        ->set('form.name', 'RBAC Created Security User')->set('form.email', $redirectEmail)->set('form.gender', 'female')
        ->set('form.branch_id', '')->set('form.password', 'Secret!RBACCreated2026')->set('form.password_confirmation', 'Secret!RBACCreated2026')
        ->call('save')->assertHasNoErrors();
    $redirectUrl = $createUserPage->effects['redirect'] ?? '';
    $redirectTargetId = (int) basename((string) parse_url($redirectUrl, PHP_URL_PATH));
    $redirectTarget = DB::table('users')->where('id', $redirectTargetId)->first();
    $expectedRedirectPath = parse_url(route('organization.users.show', $redirectTargetId), PHP_URL_PATH);
    $actualRedirectPath = parse_url($redirectUrl, PHP_URL_PATH);
    verify($redirectTargetId > 0 && $actualRedirectPath === $expectedRedirectPath && $redirectTarget?->email === strtolower($redirectEmail), 'security-admin new-user redirect targets created user details');
    verify(parse_url($redirectUrl, PHP_URL_FRAGMENT) === 'user-access', 'security-admin new-user redirect targets access section anchor');
    verify($redirectTargetId > 0 && ! DB::table('access_grants')->where('user_id', $redirectTargetId)->exists()
        && ! DB::table('user_roles')->where('user_id', $redirectTargetId)->exists(), 'security-admin-created user redirects to access section without automatic grants');

    $unverifiedTarget = User::factory()->create(['email_verified_at' => null]);
    DB::table('access_grants')->insert(['user_id' => $unverifiedTarget->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $securityLabel = PermissionCatalog::DEFINITIONS['M01.security_admin'][0];
    Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $unverifiedTarget->id])
        ->assertSee($securityLabel)->assertSee('لا توجد صلاحيات فعالة لهذا المستخدم.');
    $inactiveAccessTarget = User::factory()->create(['active' => false]);
    DB::table('access_grants')->insert(['user_id' => $inactiveAccessTarget->id, 'permission_code' => 'M01.company_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $inactiveAccessTarget->id])
        ->assertSee('لا توجد صلاحيات فعالة لهذا المستخدم.');

    $foreignGrantId = DB::table('access_grants')->insertGetId(['user_id' => $directUser->id, 'permission_code' => 'M01.company_view', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $uiRoleUser->id])->call('revokeDirect', $foreignGrantId)->assertNotFound();
    verify(DB::table('access_grants')->where('id', $foreignGrantId)->exists(), 'forged direct-grant id for another user is hidden and unchanged');
    Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $admin->id])->set('selectedRole', (string) $roleId)->call('assignRole')->assertForbidden();
    Livewire::actingAs($admin)->test(UserAccessSection::class, ['user' => $admin->id])->set('selectedBranch', (string) $branchA)->call('grantScope')->assertForbidden();
    Livewire::actingAs($manager)->test(UserAccessSection::class, ['user' => $subject->id])->assertForbidden();

    $inactiveTarget = User::factory()->create(['active' => false]);
    invalid(fn () => app(SetUserBranchScope::class)->execute($admin, $inactiveTarget->id, $branchA, true), 'new branch scope for inactive user rejected');
    DB::table('branches')->where('id', $branchB)->update(['active' => false]);
    invalid(fn () => app(SetUserBranchScope::class)->execute($admin, $subject->id, $branchB, true), 'new branch scope for inactive branch rejected');
    verify(DB::table('user_branch_scopes')->where('user_id', $inactiveTarget->id)->doesntExist()
        && DB::table('user_branch_scopes')->where('user_id', $subject->id)->where('branch_id', $branchB)->doesntExist(), 'inactive user and branch scope grants leave no records');
    verify(! Route::has('organization.access'), 'standalone organization access route has been removed');

    $otherAdmins = DB::table('users as u')->where('u.id', '<>', $admin->id)->where('u.active', true)->whereNotNull('u.email_verified_at')
        ->where(function ($query) {
            $query->whereExists(fn ($query) => $query->selectRaw('1')->from('access_grants as ag')->whereColumn('ag.user_id', 'u.id')->where('ag.permission_code', 'M01.security_admin')->whereNull('ag.branch_id'))
                ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->whereColumn('ur.user_id', 'u.id')->where('rp.permission_code', 'M01.security_admin'));
        })->pluck('u.id');
    DB::table('users')->whereIn('id', $otherAdmins)->update(['active' => false]);
    DB::table('access_grants')->where('id', $adminGrantId)->delete();
    invalid(fn () => app(RbacGuard::class)->assertAdministratorRemains(), 'last active security administrator invariant rejects an empty admin set');
    $audits = DB::table('access_audits')->where('actor_id', $admin->id)->where('id', '>', $beforeAuditId)
        ->get(['before_snapshot', 'after_snapshot']);
    $auditText = $audits->map(fn ($audit) => json_encode([$audit->before_snapshot, $audit->after_snapshot]))->implode(' ');
    verify($audits->isNotEmpty() && ! str_contains(strtolower($auditText), 'password') && ! str_contains(strtolower($auditText), 'email')
        && ! str_contains(strtolower($auditText), 'secret'), 'security audits capture mutations without credential or personal secrets');

    $selfEmailAdmin = User::factory()->create();
    DB::table('access_grants')->insert(['user_id' => $selfEmailAdmin->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    $otherAdmins = DB::table('users as u')->where('u.id', '<>', $selfEmailAdmin->id)->where('u.active', true)->whereNotNull('u.email_verified_at')
        ->where(function ($query) {
            $query->whereExists(fn ($query) => $query->selectRaw('1')->from('access_grants as ag')->whereColumn('ag.user_id', 'u.id')->where('ag.permission_code', 'M01.security_admin')->whereNull('ag.branch_id'))
                ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->whereColumn('ur.user_id', 'u.id')->where('rp.permission_code', 'M01.security_admin'));
        })->pluck('u.id');
    DB::table('users')->whereIn('id', $otherAdmins)->update(['active' => false]);
    $originalEmail = $selfEmailAdmin->email;
    $originalVerified = DB::table('users')->where('id', $selfEmailAdmin->id)->value('email_verified_at');
    $lastAdminSession = 'rbac-last-admin-'.$suffix;
    DB::table('sessions')->insert(['id' => $lastAdminSession, 'user_id' => $selfEmailAdmin->id, 'payload' => 'smoke', 'last_activity' => now()->timestamp]);
    DB::table('password_reset_tokens')->insert(['email' => $originalEmail, 'token' => 'smoke-last-admin-token', 'created_at' => now()]);
    $lastAdminSessionVersion = (int) DB::table('users')->where('id', $selfEmailAdmin->id)->value('session_version');
    Livewire::actingAs($selfEmailAdmin)->test('pages::settings.profile')->set('email', 'rbac-last-admin-'.$suffix.'@example.test')
        ->call('updateProfileInformation')->assertHasErrors('assignment');
    verify(DB::table('users')->where('id', $selfEmailAdmin->id)->value('email') === $originalEmail
        && DB::table('users')->where('id', $selfEmailAdmin->id)->value('email_verified_at') === $originalVerified
        && (int) DB::table('users')->where('id', $selfEmailAdmin->id)->value('session_version') === $lastAdminSessionVersion
        && DB::table('sessions')->where('id', $lastAdminSession)->exists()
        && DB::table('password_reset_tokens')->where('email', $originalEmail)->exists(),
        'blocked last security administrator email change preserves email, verification, reset token, and sessions');

    $profileUser = User::factory()->create();
    $profileOldEmail = $profileUser->email;
    $profileNewEmail = 'rbac-profile-'.$suffix.'@example.test';
    $profileSession = 'rbac-profile-'.$suffix;
    DB::table('sessions')->insert(['id' => $profileSession, 'user_id' => $profileUser->id, 'payload' => 'smoke', 'last_activity' => now()->timestamp]);
    DB::table('password_reset_tokens')->insert(['email' => $profileOldEmail, 'token' => 'smoke-profile-token', 'created_at' => now()]);
    $profileSessionVersion = (int) DB::table('users')->where('id', $profileUser->id)->value('session_version');
    Livewire::actingAs($profileUser)->test('pages::settings.profile')->set('email', $profileNewEmail)->call('updateProfileInformation')->assertHasNoErrors();
    verify(DB::table('users')->where('id', $profileUser->id)->value('email') === $profileNewEmail
        && DB::table('users')->where('id', $profileUser->id)->value('email_verified_at') === null
        && (int) DB::table('users')->where('id', $profileUser->id)->value('session_version') === $profileSessionVersion + 1
        && ! DB::table('sessions')->where('id', $profileSession)->exists()
        && ! DB::table('password_reset_tokens')->where('email', $profileOldEmail)->exists(),
        'ordinary profile email change clears verification and revokes old reset token and sessions');
} finally {
    DB::rollBack();
    echo "RBAC smoke transaction rolled back.\n";
}
verify(DB::table('users')->whereIn('id', [$admin->id, $subject->id, $branchBUser->id, $directUser->id, $disabledAdmin->id, $readOnly->id, $manager->id, $inactiveSecurity->id, $scopedSecurity->id, $failedLoginUser->id, $successfulLoginUser->id, $resetLinkUser->id, $passwordChangeUser->id, $legacyHistoryTarget->id, $uiRoleUser->id, $inactiveTarget->id, $unverifiedTarget->id, $inactiveAccessTarget->id, $redirectTargetId, $selfEmailAdmin->id, $profileUser->id])->doesntExist()
    && DB::table('branches')->whereIn('code', ['RBAC-A-'.$suffix, 'RBAC-B-'.$suffix])->doesntExist(), 'all RBAC fixtures removed by rollback');

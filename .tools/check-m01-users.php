<?php

use App\Models\User;
use App\Modules\Organization\Application\ChangeUserStatus;
use App\Modules\Organization\Application\ResetManagedUserPassword;
use App\Modules\Organization\Application\SaveUser;
use App\Modules\Organization\Application\UpdateOwnGender;
use App\Modules\Organization\Application\UserAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Bounded disposable smoke check; always rolls back business data in the isolated PostgreSQL test database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Smoke check requires the separate PostgreSQL test database.');
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
        verify(in_array($exception->getStatusCode(), [403, 404], true), 'unauthorized action rejected');

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
    $outsider = User::factory()->create();
    DB::table('companies')->insertOrIgnore(['id' => 1, 'code' => 'M01-SMOKE', 'name' => 'M01 Smoke', 'currency' => 'EGP', 'setup_complete' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    $branchA = DB::table('branches')->insertGetId(['company_id' => 1, 'code' => 'M01-A-'.$suffix, 'name' => 'Smoke A', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchB = DB::table('branches')->insertGetId(['company_id' => 1, 'code' => 'M01-B-'.$suffix, 'name' => 'Smoke B', 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('users')->where('id', $admin->id)->update(['branch_id' => $branchB]);
    DB::table('users')->where('id', $outsider->id)->update(['branch_id' => $branchA]);
    foreach (['M01.user_admin', 'M01.user_create'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $admin->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    verify(! app(UserAccess::class)->can($admin, 'M14.payroll_view'), 'user admin cannot authorize another module permission');

    $disabled = User::factory()->create(['active' => false, 'password' => 'Secret!DisabledLogin2026']);
    $ownGender = app(UpdateOwnGender::class);
    $adminBranch = DB::table('users')->where('id', $admin->id)->value('branch_id');
    $adminGrants = DB::table('access_grants')->where('user_id', $admin->id)->orderBy('id')->get(['permission_code', 'branch_id'])->toArray();
    $adminVersion = (int) DB::table('users')->where('id', $admin->id)->value('version');
    $ownGender->execute($admin, 'male');
    verify(DB::table('users')->where('id', $admin->id)->value('gender') === 'male'
        && (int) DB::table('users')->where('id', $admin->id)->value('version') === $adminVersion + 1
        && DB::table('users')->where('id', $admin->id)->value('branch_id') === $adminBranch
        && DB::table('access_grants')->where('user_id', $admin->id)->orderBy('id')->get(['permission_code', 'branch_id'])->toArray() == $adminGrants,
        'self-service gender update changes gender and version only');
    invalid(fn () => $ownGender->execute($admin, 'other'), 'self-service gender rejects invalid value');
    forbidden(fn () => $ownGender->execute($disabled, 'female'));
    $admin->refresh();
    Livewire::actingAs($admin)->test('pages::settings.profile')->assertSet('gender', 'male')->assertSee('حفظ النوع والصورة الافتراضية')
        ->set('gender', 'female')->call('updateGender')->assertRedirect(route('profile.edit'));
    verify(DB::table('users')->where('id', $admin->id)->value('gender') === 'female', 'profile settings displays and updates own gender');

    $save = app(SaveUser::class);
    $user = $save->execute($admin, ['name' => 'Smoke User', 'email' => 'SMOKE.USER@example.test', 'gender' => 'male', 'password' => 'Secret!SmokeUser2026', 'password_confirmation' => 'Secret!SmokeUser2026', 'branch_id' => $branchA]);
    verify($user->email === 'smoke.user@example.test' && DB::table('users')->where('id', $user->id)->value('gender') === 'male' && password_verify('Secret!SmokeUser2026', DB::table('users')->where('id', $user->id)->value('password')), 'managed user created with normalized email, male gender, and hashed password');
    verify(! DB::table('access_grants')->where('user_id', $user->id)->exists(), 'user creation does not grant permissions');
    $user = $save->execute($admin, ['name' => 'Smoke User Edited', 'email' => 'smoke.user@example.test', 'gender' => 'female', 'branch_id' => $branchA], $user->id, $user->version);
    verify($user->name === 'Smoke User Edited' && DB::table('users')->where('id', $user->id)->value('gender') === 'female', 'managed user edit persisted female gender');

    invalid(fn () => $save->execute($admin, ['name' => 'Duplicate', 'email' => 'SMOKE.USER@EXAMPLE.TEST', 'gender' => 'male', 'password' => 'Secret!Duplicate2026', 'password_confirmation' => 'Secret!Duplicate2026']), 'case-insensitive duplicate email rejected');
    invalid(fn () => $save->execute($admin, ['name' => 'Invalid Gender', 'email' => 'invalid-gender@example.test', 'gender' => 'other', 'password' => 'Secret!InvalidGender2026', 'password_confirmation' => 'Secret!InvalidGender2026']), 'invalid gender rejected');
    invalid(fn () => $save->execute($admin, ['name' => 'Stale', 'email' => $user->email, 'gender' => 'female', 'branch_id' => $branchA], $user->id, 1), 'stale user edit rejected');
    forbidden(fn () => $save->execute(User::findOrFail($user->id), ['name' => 'No authority', 'email' => 'unauthorized@example.test', 'password' => 'Secret!Unauthorized2026', 'password_confirmation' => 'Secret!Unauthorized2026']));

    foreach (['M01.user_view', 'M01.user_update', 'M01.user_status', 'M01.user_password_reset'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $outsider->id, 'permission_code' => $permission, 'branch_id' => $branchA, 'created_at' => now(), 'updated_at' => now()]);
    }
    $access = app(UserAccess::class);
    verify($access->query($outsider)->where('u.id', $user->id)->exists() && ! $access->query($outsider)->where('u.id', $admin->id)->exists(), 'scoped user query hides records outside granted branch');
    forbidden(fn () => $save->execute($outsider, ['name' => 'Cross branch', 'email' => 'cross@example.test'], $admin->id, $admin->version));

    DB::table('sessions')->insert(['id' => 'm01-smoke-user-session', 'user_id' => $user->id, 'payload' => 'smoke', 'last_activity' => now()->timestamp]);
    $status = app(ChangeUserStatus::class);
    $status->execute($admin, $user->id, false, $user->version);
    $user = DB::table('users')->find($user->id);
    verify(! $user->active && ! DB::table('sessions')->where('user_id', $user->id)->exists() && $user->session_version > 0, 'deactivation revokes sessions and advances session version');
    forbidden(fn () => $status->execute($outsider, $outsider->id, false, DB::table('users')->find($outsider->id)->version));
    forbidden(fn () => $status->execute($admin, $admin->id, false, (int) DB::table('users')->where('id', $admin->id)->value('version')));
    forbidden(fn () => $save->execute(User::findOrFail($user->id), ['name' => 'Disabled actor', 'email' => 'disabled@example.test']));

    $status->execute($admin, $user->id, true, $user->version);
    $user = DB::table('users')->find($user->id);
    $passwordReset = app(ResetManagedUserPassword::class);
    DB::table('sessions')->insert(['id' => 'm01-smoke-reset-session', 'user_id' => $user->id, 'payload' => 'smoke', 'last_activity' => now()->timestamp]);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'smoke-reset-token', 'created_at' => now()]);
    $passwordReset->execute($admin, $user->id, ['password' => 'Secret!ResetUser2026', 'password_confirmation' => 'Secret!ResetUser2026'], $user->version);
    verify(password_verify('Secret!ResetUser2026', DB::table('users')->find($user->id)->password) && ! DB::table('sessions')->where('user_id', $user->id)->exists() && ! DB::table('password_reset_tokens')->where('email', $user->email)->exists(), 'managed password reset persists and revokes credentials');
    verify(! DB::table('access_audits')->where('subject_user_id', $user->id)->get()->contains(fn ($audit) => str_contains(json_encode([$audit->before_snapshot, $audit->after_snapshot]), 'Secret!')), 'access audits contain no password secret');
    forbidden(fn () => $passwordReset->execute($admin, $admin->id, ['password' => 'Secret!SelfResetAdmin2026', 'password_confirmation' => 'Secret!SelfResetAdmin2026'], (int) DB::table('users')->where('id', $admin->id)->value('version')));

    $uiEmail = strtolower('ui-smoke-'.$suffix.'@example.test');
    Livewire::actingAs($admin)->test(App\Modules\Organization\Presentation\UsersPage::class)
        ->set('search', 'SMOKE.USER@example.test')->assertSee('Smoke User Edited')->call('create')->set('form.name', 'UI Smoke User')->set('form.email', $uiEmail)
        ->set('form.branch_id', (string) $branchA)->set('form.gender', 'female')->set('form.password', 'Secret!UISmokeUser2026')->set('form.password_confirmation', 'Secret!UISmokeUser2026')
        ->call('save')->assertHasNoErrors()->set('search', $uiEmail)->assertSee('UI Smoke User');
    $uiUserId = (int) DB::table('users')->where('email', $uiEmail)->value('id');
    verify($uiUserId > 0, 'users list renders and creates a managed account');
    Livewire::actingAs($admin)->test(App\Modules\Organization\Presentation\UsersPage::class)
        ->set('search', $uiEmail)->call('edit', $uiUserId)->set('form.name', 'UI Smoke Edited')->call('save')->assertHasNoErrors()->set('search', $uiEmail)->assertSee('UI Smoke Edited');
    Livewire::actingAs($admin)->test(App\Modules\Organization\Presentation\UserDetailsPage::class, ['user' => $uiUserId])
        ->assertSee('UI Smoke Edited')->call('openPasswordReset')->set('password', 'Secret!UIDetailsReset2026')
        ->set('password_confirmation', 'Secret!UIDetailsReset2026')->call('resetPassword')->assertHasNoErrors();
    Livewire::actingAs($admin)->test(App\Modules\Organization\Presentation\UsersPage::class)
        ->call('confirmStatus', $uiUserId)->assertSet('confirmingStatus', true)->call('changeStatus')->assertHasNoErrors();
    verify(! DB::table('users')->where('id', $uiUserId)->value('active'), 'user details reset and list status actions render and persist');

    $loginRequest = Request::create('/login', 'POST', ['email' => strtoupper($disabled->email), 'password' => 'Secret!DisabledLogin2026']);
    $authenticate = Fortify::$authenticateUsingCallback;
    verify(is_callable($authenticate) && $authenticate($loginRequest) === null, 'Fortify credential callback rejects inactive users');
    $session = app('session')->driver();
    $session->start();
    $inactiveRequest = Request::create('/dashboard');
    $inactiveRequest->setLaravelSession($session);
    $inactiveRequest->setUserResolver(fn () => $disabled);
    Auth::setUser($disabled);
    $inactiveResponse = app(App\Modules\Organization\Presentation\EnsureActiveAccount::class)->handle($inactiveRequest, fn () => response('unexpected access'));
    verify($inactiveResponse->isRedirect(route('login')) && Auth::guest(), 'active-account middleware ends an inactive session');
    Auth::setUser($admin);
} finally {
    DB::rollBack();
    echo "Disposable test transaction rolled back.\n";
}
verify(! DB::table('users')->whereIn('email', [$admin->email, $outsider->email, $uiEmail, $disabled->email])->exists(), 'user fixtures removed by rollback');

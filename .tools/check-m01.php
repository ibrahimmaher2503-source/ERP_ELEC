<?php

use App\Models\User;
use App\Modules\Organization\Application\ChangeBranchStatus;
use App\Modules\Organization\Application\OrganizationAccess;
use App\Modules\Organization\Application\SaveBranch;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Presentation\BranchDetailsPage;
use App\Modules\Organization\Presentation\BranchesPage;
use App\Modules\Organization\Presentation\CompanyPage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        verify($exception->getStatusCode() === 403, 'unauthorized action rejected');

        return;
    }
    throw new RuntimeException('Unauthorized action was allowed.');
}
DB::beginTransaction();
$smokeLogoPath = null;
$smokeImage = null;
try {
    $actor = User::factory()->create();
    $outsider = User::factory()->create();
    foreach (['M01.company_view', 'M01.company_update', 'M01.branch_create', 'M01.branch_admin'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'created_at' => now(), 'updated_at' => now()]);
    }
    $companyAction = app(SaveCompany::class);
    $companyInput = ['code' => 'SMOKE', 'name' => 'شركة اختبار مؤقتة', 'legal_name' => null, 'tax_registration' => null, 'address' => 'عنوان مؤقت'];
    $existingCompany = DB::table('companies')->where('id', 1)->first();
    forbidden(fn () => $companyAction->execute($outsider, $companyInput, null));
    $company = $companyAction->execute($actor, $companyInput, $existingCompany?->version);
    $company = $companyAction->execute($actor, array_replace($companyInput, ['name' => 'شركة اختبار معدلة']), $company->version);
    verify($company->name === 'شركة اختبار معدلة' && $company->currency === 'EGP', 'company save and update persisted');
    $branchAction = app(SaveBranch::class);
    $branch = $branchAction->execute($actor, ['code' => 'a', 'name' => 'فرع اختبار', 'address' => 'القاهرة']);
    $other = $branchAction->execute($actor, ['code' => 'b', 'name' => 'فرع آخر']);
    $branch = $branchAction->execute($actor, ['code' => 'A', 'name' => 'فرع معدل', 'address' => 'عنوان معدل'], $branch->id, $branch->version);
    verify($branch->name === 'فرع معدل', 'branch create/edit persisted');
    try {
        $branchAction->execute($actor, ['code' => 'a', 'name' => 'مكرر']);
        throw new RuntimeException('Duplicate accepted');
    } catch (ValidationException) {
        verify(true, 'duplicate code rejected');
    }
    try {
        $branchAction->execute($actor, ['code' => 'A', 'name' => 'قديم'], $branch->id, 1);
        throw new RuntimeException('Stale edit accepted');
    } catch (ValidationException) {
        verify(true, 'stale edit rejected');
    }
    $statusAction = app(ChangeBranchStatus::class);
    $statusAction->execute($actor, $branch->id, false, $branch->version);
    $branch = DB::table('branches')->find($branch->id);
    verify(! $branch->active, 'branch deactivation persisted');
    $statusAction->execute($actor, $branch->id, true, $branch->version);
    $branch = DB::table('branches')->find($branch->id);
    DB::table('users')->where('id', $outsider->id)->update(['branch_id' => $branch->id]);
    try {
        $statusAction->execute($actor, $branch->id, false, $branch->version);
        throw new RuntimeException('Active assignment ignored');
    } catch (ValidationException) {
        verify(true, 'active user assignment blocks deactivation');
    }
    forbidden(fn () => $branchAction->execute($outsider, ['code' => 'C', 'name' => 'ممنوع']));
    forbidden(fn () => $statusAction->execute($outsider, $branch->id, false, $branch->version));
    DB::table('access_grants')->insert(['user_id' => $outsider->id, 'permission_code' => 'M01.branch_view', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    verify(app(OrganizationAccess::class)->branches($outsider)->pluck('id')->all() === [$branch->id], 'branch A reader cannot query branch B');
    Livewire::actingAs($actor)->test(CompanyPage::class)->assertSee('شركة اختبار معدلة')->call('edit')->set('form.name', 'شركة عبر الواجهة')->call('save')->assertHasNoErrors()->assertSee('شركة عبر الواجهة');
    verify(true, 'company Livewire render and save');
    Livewire::actingAs($actor)->test(BranchesPage::class)->assertSee('فرع معدل')->call('create')->set('form.code', 'UI')->set('form.name', 'فرع عبر الواجهة')->call('save')->assertHasNoErrors()->assertSee('فرع عبر الواجهة');
    verify(true, 'branches Livewire render and create');
    Livewire::actingAs($actor)->test(BranchesPage::class)->call('edit', $other->id)->set('form.name', 'فرع معدل عبر الواجهة')->call('save')->assertHasNoErrors()->assertSee('فرع معدل عبر الواجهة')->call('confirmStatus', $other->id)->assertSet('confirmingStatus', true)->call('changeStatus')->assertHasNoErrors();
    verify(! DB::table('branches')->find($other->id)->active, 'Livewire edit and confirmed status persisted');
    Livewire::actingAs($actor)->test(BranchDetailsPage::class, ['branch' => $branch->id])->assertSee('عنوان معدل');
    Livewire::actingAs($outsider)->test(BranchDetailsPage::class, ['branch' => $other->id])->assertNotFound();
    verify(true, 'details render and direct hidden branch denied');
    Illuminate\Support\Facades\Auth::setUser($actor);
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    foreach (['/organization/company', '/organization/branches', '/organization/branches/'.$branch->id] as $path) {
        $response = $kernel->handle(Illuminate\Http\Request::create($path));
        verify($response->getStatusCode() === 200, 'HTTP page resolves '.$path);
        verify(substr_count($response->getContent(), 'id="main-content"') === 1, 'one shared main region');
        verify(str_contains($response->getContent(), 'شركة عبر الواجهة'), 'shell resolves authorized company identity');
    }
    $smokeImage = tempnam(sys_get_temp_dir(), 'erp_logo_');
    file_put_contents($smokeImage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aBv8AAAAASUVORK5CYII='));
    $image = new Illuminate\Http\UploadedFile($smokeImage, 'logo.png', 'image/png', null, true);
    $latest = DB::table('companies')->find(1);
    $withLogo = $companyAction->execute($actor, array_replace($companyInput, ['commercial_registration' => 'LOCAL-ONLY', 'phone' => '+20 1000000000', 'email' => 'smoke@example.test']), $latest->version, $image);
    $smokeLogoPath = $withLogo->logo_path;
    verify($withLogo->email === 'smoke@example.test' && Illuminate\Support\Facades\Storage::disk('local')->exists($smokeLogoPath), 'approved contact fields and private logo saved');
    $logoController = app(App\Modules\Organization\Presentation\CompanyLogoController::class);
    verify($logoController()->getStatusCode() === 200, 'authorized private logo response');
    Illuminate\Support\Facades\Auth::setUser($outsider);
    forbidden(fn () => $logoController());
    Illuminate\Support\Facades\Auth::setUser($actor);
    try {
        $companyAction->execute($actor, $companyInput, $withLogo->version, Illuminate\Http\UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'));
        throw new RuntimeException('Unsafe SVG accepted');
    } catch (ValidationException) {
        verify(true, 'SVG logo rejected');
    }
    DB::table('users')->where('id', $actor->id)->update(['active' => false]);
    forbidden(fn () => $companyAction->execute($actor, $companyInput, $company->version));
    verify(DB::table('audit_entries')->count() >= 8, 'master change audit persisted atomically');
} finally {
    DB::rollBack();
    if ($smokeLogoPath) {
        Illuminate\Support\Facades\Storage::disk('local')->delete($smokeLogoPath);
    }
    if ($smokeImage && is_file($smokeImage)) {
        unlink($smokeImage);
    }
    echo "Disposable test transaction rolled back.\n";
}

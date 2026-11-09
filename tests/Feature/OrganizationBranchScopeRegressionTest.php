<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\SetUserBranchScope;
use App\Modules\Organization\Application\SetUserWarehouseScope;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationBranchScopeRegressionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regranting_branch_does_not_restore_revoked_warehouse_scope(): void
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }

        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $target = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $branch = Branch::factory()->create();
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        DB::table('access_grants')->insert([
            ['user_id' => $actor->id, 'permission_code' => 'M01.security_admin', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $target->id, 'permission_code' => 'M06.movement_view', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $branchScope = app(SetUserBranchScope::class);
        $branchScope->execute($actor, $target->id, $branch->id, true);
        app(SetUserWarehouseScope::class)->execute($actor, $target->id, $warehouse->id, true);
        $this->assertTrue(app(PermissionAccess::class)->allows($target, 'M06.movement_view', $branch->id, $warehouse->id));

        $branchScope->execute($actor, $target->id, $branch->id, false);
        $this->assertDatabaseMissing('user_warehouse_scopes', ['user_id' => $target->id, 'warehouse_id' => $warehouse->id]);
        $audit = DB::table('access_audits')->where('subject_user_id', $target->id)->where('action', 'scope.branch_revoked')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertContains($warehouse->id, json_decode($audit->before_snapshot, true)['warehouses']);
        $this->assertSame([], json_decode($audit->after_snapshot, true)['warehouses']);

        $branchScope->execute($actor, $target->id, $branch->id, true);
        $this->assertFalse(app(PermissionAccess::class)->allows($target, 'M06.movement_view', $branch->id, $warehouse->id));
    }
}

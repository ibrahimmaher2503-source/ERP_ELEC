<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->value('id');
        $user = DB::table('users')->where('active', false)->value('id');
        $customer = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->first();
        if (! $branch || ! $user || ! $customer) {
            throw new \RuntimeException('Egypt demo branch, disabled fixture user and customer required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'code' => 'DEMO-EG-PRJ-'.Str::upper(Str::random(10)), 'name' => 'تجهيز شبكة إنارة تجريبية بالقاهرة', 'branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'manager_id' => $user, 'created_by' => $user, 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'description' => 'DEMO-EG — تخطيط مشروع اصطناعي، دون اعتماد أو مخزون أو تكاليف فعلية', 'status' => 'draft'];
    }
}

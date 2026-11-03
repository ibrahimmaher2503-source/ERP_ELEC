<?php

namespace Database\Factories;

use App\Modules\FieldMaintenance\Domain\MaintenanceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenanceRequestFactory extends Factory
{
    protected $model = MaintenanceRequest::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->value('id');
        $customer = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->first();
        $user = DB::table('users')->where('active', false)->value('id');
        if (! $branch || ! $customer || ! $user) {
            throw new \RuntimeException('Existing Egyptian demo branch, customer and disabled fixture user required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'number' => 'DEMO-EG-MR-'.Str::upper(Str::random(10)), 'branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'responsible_id' => $user, 'description' => 'DEMO-EG: بلاغ تجريبي عن عطل متقطع في لوحة إنارة المحل بالقاهرة، دون تنفيذ فعلي.', 'priority' => 'normal', 'reported_at' => now()->subDay(), 'status' => 'draft', 'created_by' => $user];
    }
}

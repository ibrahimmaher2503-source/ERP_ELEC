<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseRequisition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseRequisitionFactory extends Factory
{
    protected $model = PurchaseRequisition::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->value('id');
        $user = DB::table('users')->where('active', false)->orderBy('id')->value('id');
        if (! $branch || ! $user) {
            throw new \RuntimeException('Existing Egyptian demo branch and disabled synthetic user required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch, 'created_by' => $user, 'status' => 'draft', 'request_date' => now('Africa/Cairo')->toDateString(), 'department' => 'تجهيزات المواقع', 'purpose' => $this->faker->randomElement(['احتياجات تجهيز لوحة توزيع تجريبية', 'استكمال أدوات تركيب بموقع تجريبي', 'تجهيز كابلات لإنارة موقع تجريبي']), 'notes' => 'DEMO-EG — احتياج تجريبي فقط، لا يمثل التزام شراء', 'version' => 1];
    }
}

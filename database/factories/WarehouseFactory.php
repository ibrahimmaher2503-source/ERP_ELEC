<?php

namespace Database\Factories;

use App\Modules\Inventory\Domain\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('company_id', 1)->where('active', true)->orderBy('id')->value('id');
        if (! $branch) {
            throw new \RuntimeException('Warehouse fixtures require an explicit existing Organization branch.');
        }

        return ['code' => 'DEMO-EG-WH-'.strtoupper(fake()->unique()->bothify('??###')), 'name' => fake()->randomElement(['مستودع كابلات القاهرة', 'مستودع معدات الإسكندرية', 'مخزن التوزيع بالجيزة']), 'branch_id' => $branch, 'address' => 'منطقة صناعية — بيانات تجريبية مصرية', 'notes' => 'مستودع تجريبي؛ لا يتضمن أرصدة أو صلاحيات تلقائية.', 'active' => true, 'version' => 1];
    }
}

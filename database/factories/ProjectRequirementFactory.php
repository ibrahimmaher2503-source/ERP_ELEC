<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class ProjectRequirementFactory extends Factory
{
    protected $model = ProjectRequirement::class;

    public function definition(): array
    {
        $product = DB::table('products')->where('sku', 'like', 'DEMO-EG%')->where('active', true)->first();
        if (! $product) {
            throw new \RuntimeException('Egypt demo product required.');
        }

return ['project_id' => Project::factory(), 'product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'sku' => $product->sku, 'product_name' => $product->name, 'quantity' => '25.00000000', 'notes' => 'DEMO-EG — احتياج تخطيطي دون حجز'];
    }
}

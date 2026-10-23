<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\System\Domain\DataOperationItem;
use App\Modules\System\Domain\DataOperationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataOperationItem> */
class DataOperationItemFactory extends Factory
{
    protected $model = DataOperationItem::class;

    public function definition(): array
    {
        return [
            'run_id' => DataOperationRun::factory()->state(['actor_id' => User::factory()->disabledDemo()]),
            'row_number' => 1,
            'identifier' => 'DEMO-EG-PROD-FACTORY-ONLY',
            'status' => 'valid',
            'error' => null,
            'plan' => [
                'fixture_only' => true,
                'operation' => 'preview_only',
                'changes' => ['short_description' => 'Synthetic proposed description for a factory persistence check.'],
            ],
            'before_values' => ['short_description' => 'Synthetic existing description.'],
            'after_values' => ['short_description' => 'Synthetic proposed description for a factory persistence check.'],
            'result' => null,
        ];
    }
}

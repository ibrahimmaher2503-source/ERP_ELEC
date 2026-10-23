<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\System\Domain\DataOperationRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DataOperationRun> */
class DataOperationRunFactory extends Factory
{
    protected $model = DataOperationRun::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'actor_id' => User::factory(),
            'entity' => 'products',
            'kind' => 'bulk',
            'mode' => 'update',
            'template_version' => 'M02-products-v1',
            'status' => 'preview',
            'filename' => null,
            'source_path' => null,
            'summary' => ['total' => 0, 'valid' => 0, 'invalid' => 0, 'pending' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
            'started_at' => null,
            'completed_at' => null,
            'expires_at' => now()->addHour(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectPhaseFactory extends Factory
{
    protected $model = ProjectPhase::class;

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => 'تخطيط وتجهيز الأعمال الكهربائية', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'cancelled' => false];
    }
}

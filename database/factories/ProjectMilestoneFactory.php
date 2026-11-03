<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectMilestoneFactory extends Factory
{
    protected $model = ProjectMilestone::class;

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'title' => 'مراجعة مخططات الإنارة التجريبية', 'target_date' => '2026-12-15',
            'responsible_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->manager_id,
            'required' => true, 'status' => 'pending'];
    }
}

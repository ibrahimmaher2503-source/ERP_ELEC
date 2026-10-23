<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectPhase;
use App\Modules\Projects\Domain\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectTaskFactory extends Factory
{
    protected $model = ProjectTask::class;

    public function definition(): array
    {
        return ['phase_id' => ProjectPhase::factory(), 'project_id' => fn (array $a) => ProjectPhase::findOrFail($a['phase_id'])->project_id, 'title' => 'حصر نقاط الإنارة ومراجعة المخطط', 'assigned_to' => fn (array $a) => Project::findOrFail($a['project_id'])->manager_id, 'start_date' => fn (array $a) => ProjectPhase::findOrFail($a['phase_id'])->start_date, 'end_date' => fn (array $a) => ProjectPhase::findOrFail($a['phase_id'])->end_date, 'status' => 'pending', 'notes' => 'DEMO-EG — مهمة تخطيط غير منفذة'];
    }
}

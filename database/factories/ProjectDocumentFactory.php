<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectDocumentFactory extends Factory
{
    protected $model = ProjectDocument::class;

    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'number' => 'DEMO-EG-DOC-'.Str::upper(Str::random(8)), 'title' => 'مخطط إنارة تجريبي', 'classification' => 'رسومات تخطيطية', 'required' => false];
    }
}

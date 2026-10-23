<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectChange;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectChangeFactory extends Factory
{
    protected $model = ProjectChange::class;

    public function definition(): array
    {
        $p = Project::factory()->create();

        return ['project_id' => $p->id, 'creation_key' => (string) Str::uuid(), 'base_revision' => 0, 'reason' => 'DEMO-EG — اقتراح ميزانية تخطيطي غير معتمد', 'proposal' => json_encode(['currency' => 'EGP', 'total' => '100000.00000000', 'scope' => $p->description, 'categories' => [['name' => 'تجهيزات كهربائية تجريبية', 'amount' => '100000.00000000']], 'phases' => [], 'structure' => []], JSON_THROW_ON_ERROR), 'status' => 'pending', 'created_by' => $p->created_by, 'created_at' => now()];
    }
}

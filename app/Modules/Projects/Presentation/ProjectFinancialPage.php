<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Domain\Project;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ProjectFinancialPage extends Component
{
    #[Locked]
    public int $projectId;

    public function mount(int $project): void
    {
        $this->projectId = $project;
    }

    public function render()
    {
        return view('projects.financial', ['project' => Project::findOrFail($this->projectId), 'summary' => app(ProjectFinancials::class)->summary(auth()->id(), $this->projectId)])->layout('layouts::app', ['title' => 'الملخص المالي للمشروع']);
    }
}

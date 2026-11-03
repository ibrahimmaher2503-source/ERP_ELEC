<?php

namespace App\Livewire;

use App\Application\ExecutiveDashboard;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Livewire\Component;

final class ExecutiveDashboardPage extends Component
{
    public string $branch = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->to = now('Africa/Cairo')->toDateString();
        $this->from = now('Africa/Cairo')->startOfMonth()->toDateString();
    }

    public function render()
    {
        $analytics = app(ExecutiveDashboard::class)->read(auth()->id(), $this->branch === '' ? null : (int) $this->branch, $this->from, $this->to);
        $branchIds = app(OrganizationApi::class)->projectScopedBranches(auth()->id());

        return view('dashboard', compact('analytics', 'branchIds'));
    }
}

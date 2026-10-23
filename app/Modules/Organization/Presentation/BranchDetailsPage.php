<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\OrganizationAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BranchDetailsPage extends Component
{
    #[Locked]
    public int $branch;

    public function mount(int $branch): void
    {
        $this->branch = $branch;
    }

    public function render()
    {
        $branch = app(OrganizationAccess::class)->branches(Auth::user())->where('id', $this->branch)->first();
        abort_unless($branch, 404);

        return view('organization.branch-details', ['record' => $branch])->layout('layouts::app', ['title' => 'تفاصيل الفرع']);
    }
}

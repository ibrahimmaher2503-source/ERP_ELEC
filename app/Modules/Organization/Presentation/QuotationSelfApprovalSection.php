<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SaveQuotationSelfApproval;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class QuotationSelfApprovalSection extends Component
{
    #[Locked]
    public string $targetType;

    #[Locked]
    public int $targetId;

    #[Locked]
    public ?int $version = null;

    public bool $enabled = false;

    public function boot(): void
    {
        app(RbacGuard::class)->authorize(auth()->user());
    }

    public function mount(string $targetType, int $targetId): void
    {
        abort_unless(in_array($targetType, ['user', 'role'], true), 422);
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->reload();
    }

    private function reload(): void
    {
        $r = DB::table('quotation_self_approval_policies')->where('target_type', $this->targetType)->where('target_id', $this->targetId)->first();
        $this->version = $r?->version;
        $this->enabled = (bool) ($r?->enabled ?? false);
    }

    public function save(): void
    {
        app(SaveQuotationSelfApproval::class)->execute(auth()->user(), $this->targetType, $this->targetId, $this->enabled, $this->version);
        $this->reload();
        session()->flash('selfApprovalStatus', 'تم حفظ إعداد الموافقة الذاتية.');
    }

    public function render()
    {
        return view('organization.quotation-self-approval');
    }
}

<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\RbacGuard;
use App\Modules\Organization\Application\SelfApprovalPolicy;
use Livewire\Attributes\Locked;
use Livewire\Component;

class QuotationSelfApprovalSection extends Component
{
    #[Locked]
    public string $targetType;

    #[Locked]
    public int $targetId;

    /** @var array<string, bool> */
    public array $scopes = [];

    /** @var array<string, ?int> */
    #[Locked]
    public array $versions = [];

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
        $state = app(SelfApprovalPolicy::class)->forTarget($this->targetType, $this->targetId);
        $this->scopes = [];
        $this->versions = [];
        foreach ($state as $scope => $item) {
            $this->scopes[$scope] = $item['enabled'];
            $this->versions[$scope] = $item['version'];
        }
    }

    public function enableAll(): void
    {
        foreach (array_keys(SelfApprovalPolicy::SCOPES) as $scope) {
            $this->scopes[$scope] = true;
        }
    }

    public function disableAll(): void
    {
        foreach (array_keys(SelfApprovalPolicy::SCOPES) as $scope) {
            $this->scopes[$scope] = false;
        }
    }

    public function save(): void
    {
        app(SelfApprovalPolicy::class)->save(auth()->user(), $this->targetType, $this->targetId, $this->scopes, $this->versions);
        $this->reload();
        session()->flash('selfApprovalStatus', 'تم حفظ سياسة الاعتماد الذاتي وتسجيل التغيير في سجل التدقيق.');
    }

    public function render()
    {
        return view('organization.quotation-self-approval', [
            'definitions' => SelfApprovalPolicy::SCOPES,
        ]);
    }
}

<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Sales\Application\SalesOrderApprovalPolicy;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SalesOrderPolicyPage extends Component
{
    public string $role = '';

    #[Locked]
    public ?int $version = null;

    public function mount(): void
    {
        $p = app(SalesOrderApprovalPolicy::class)->configuration(auth()->id());
        $this->version = $p?->version;
        $this->role = (string) ($p?->exception_role_id ?? '');
    }

    public function save(): void
    {
        $this->validate(['role' => ['required', 'integer', 'min:1']]);
        app(SalesOrderApprovalPolicy::class)->save(auth()->id(), (int) $this->role, $this->version);
        $this->version = app(SalesOrderApprovalPolicy::class)->configuration(auth()->id())->version;
        session()->flash('status', 'تم حفظ مسؤول الاستثناءات دون منح صلاحيات تلقائية.');
    }

    public function render()
    {
        return view('sales.orders.policy', ['roles' => app(OrganizationApi::class)->securityRoles(auth()->id())])->layout('layouts::app', ['title' => 'إعدادات اعتماد أوامر البيع']);
    }
}

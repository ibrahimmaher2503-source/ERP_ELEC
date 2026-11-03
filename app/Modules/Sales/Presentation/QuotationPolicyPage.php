<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Domain\QuotationPolicy;
use App\Modules\Sales\Domain\QuotationTerm;
use Livewire\Attributes\Locked;
use Livewire\Component;

class QuotationPolicyPage extends Component
{
    public array $settings = [];

    public array $term = ['code' => '', 'kind' => 'payment', 'name' => '', 'body' => ''];

    #[Locked]
    public ?int $version = null;

    public function boot(): void
    {
        app(QuotationAccess::class)->authorize(auth()->id(), 'policy_manage');
    }

    public function mount(): void
    {
        $p = QuotationPolicy::find(1);
        $this->settings = $p?->settings ?? array_fill_keys(['validity_default', 'validity_min', 'validity_max', ...QuotationCommercialPolicy::DISCOUNTS], '');
        $this->version = $p?->version;
    }

    public function prepareStandardPolicy(): void
    {
        $this->settings = ['validity_default' => '30', 'validity_min' => '1', 'validity_max' => '90',
            'line_percent' => '0', 'line_fixed' => '0', 'quotation_percent' => '0', 'quotation_fixed' => '0'];
        $this->resetValidation();
    }

    public function save(): void
    {
        app(QuotationCommercialPolicy::class)->save(auth()->id(), $this->settings, $this->version);
        $this->version = QuotationPolicy::findOrFail(1)->version;
        session()->flash('status', 'تم حفظ السياسة التجارية.');
    }

    public function saveTerm(): void
    {
        app(QuotationCommercialPolicy::class)->term(auth()->id(), $this->term);
        $this->term = ['code' => '', 'kind' => 'payment', 'name' => '', 'body' => ''];
        session()->flash('status', 'تم حفظ إصدار جديد من القالب.');
    }

    public function render()
    {
        return view('sales.quotations.policy', ['terms' => QuotationTerm::latest('id')->get()])->layout('layouts::app', ['title' => 'سياسات عروض الأسعار']);
    }
}

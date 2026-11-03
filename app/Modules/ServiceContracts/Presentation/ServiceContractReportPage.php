<?php

namespace App\Modules\ServiceContracts\Presentation;

use App\Modules\ServiceContracts\Application\ServiceContractReport;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ServiceContractReportPage extends Component
{
    #[Locked]
    public int $contractId;

    public string $to = '';

    public function mount(int $contract): void
    {
        $this->contractId = $contract;
        $this->to = now('Africa/Cairo')->toDateString();
    }

    public function render()
    {
        $this->resetValidation('to');
        try {
            $report = app(ServiceContractReport::class)->show(auth()->id(), $this->contractId, $this->to);
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $report = null;
        }

        return view('service-contracts.report', compact('report'));
    }
}

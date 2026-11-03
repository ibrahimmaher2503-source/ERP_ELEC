<?php

namespace App\Modules\ServiceContracts\Presentation;

use App\Modules\ServiceContracts\Application\ServiceContractAccess;
use App\Modules\ServiceContracts\Application\ServiceContractBilling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ServiceContractBillingPage extends Component
{
    #[Locked]
    public int $contractId;

    #[Locked]
    public string $creationKey;

    public array $form = ['code' => '', 'amount' => '', 'invoice_date' => '', 'due_date' => '', 'tax_mode' => '', 'reference' => '',
        'work_id' => '', 'quantity' => '', 'period_from' => '', 'period_to' => '', 'recognition_date' => '', 'work_ids' => [], 'claim_quantities' => [],
        'source_id' => '', 'recognition_id' => '', 'correction_date' => ''];

    public function mount(int $contract): void
    {
        $this->contractId = $contract;
        $this->creationKey = (string) Str::uuid();
        $this->context();
    }

    private function context(): array
    {
        $c = DB::table('service_contracts')->find($this->contractId);
        abort_unless($c, 404);
        $access = app(ServiceContractAccess::class);
        $manage = $access->allows(auth()->id(), (int) $c->branch_id, 'billing_manage');
        $approve = $access->allows(auth()->id(), (int) $c->branch_id, 'billing_approve');
        abort_unless($manage || $approve, 403);
        abort_unless($c->active_revision_id, 409);
        $terms = json_decode(DB::table('service_contract_revisions')->where('id', $c->active_revision_id)->value('data'), true, 512, JSON_THROW_ON_ERROR);

        return compact('c', 'terms', 'manage', 'approve');
    }

    public function propose(string $kind): void
    {
        $context = $this->context();
        abort_unless($context['manage'], 403);
        $a = app(ServiceContractBilling::class);
        $f = $this->form;
        match ($kind) {
            'installment' => $a->proposeInstallment(auth()->id(), $this->contractId, $f['code'], $this->creationKey, $f),
            'measurement' => $a->createMeasurement(auth()->id(), $this->contractId, (int) $f['work_id'], $f['code'], $f['quantity'], $this->creationKey, $f['reference']),
            'claim' => $a->createClaim(auth()->id(), $this->contractId, $this->creationKey, $f + ['lines' => collect($f['claim_quantities'])->filter(fn ($v) => $v !== '')->map(fn ($v, $id) => ['measurement_id' => (int) $id, 'quantity' => $v])->values()->all()]),
            'recognition' => $a->proposeRecognition(auth()->id(), $this->contractId, array_map('intval', $f['work_ids']), $f['amount'], $f['recognition_date'], $this->creationKey, $f['reference']),
            'credit' => $a->correctContractBilling(auth()->id(), $this->ownedId('service_contract_billing_sources', (int) $f['source_id']), $this->creationKey, $f['amount'], $f['correction_date'], $f['reference']),
            'reversal' => $a->proposeRecognitionReversal(auth()->id(), $this->ownedId('service_contract_recognitions', (int) $f['recognition_id']), $f['correction_date'], $this->creationKey, $f['reference']),
            default => abort(422),
        };
        $this->creationKey = (string) Str::uuid();
        session()->flash('status', 'تم حفظ الطلب. الاعتماد والترحيل حسب الصلاحيات المستقلة.');
    }

    private function ownedId(string $table, int $id): int
    {
        abort_unless(DB::table($table)->where('contract_id', $this->contractId)->where('id', $id)->exists(), 404);

        return $id;
    }

    public function act(string $kind, int $id, int $version): void
    {
        $this->context();
        $table = match ($kind) {
            'source', 'invoice' => 'service_contract_billing_sources',
            'measurement' => 'service_contract_measurements',
            'submit', 'claim' => 'service_contract_claims',
            'recognition' => 'service_contract_recognitions',
            'reversal' => 'service_contract_recognition_reversals',
            default => abort(422),
        };
        $this->ownedId($table, $id);
        $a = app(ServiceContractBilling::class);
        match ($kind) {
            'source' => $a->approveSource(auth()->id(), $id, $version),
            'invoice' => $a->invoice(auth()->id(), $id, $this->creationKey),
            'measurement' => $a->approveMeasurement(auth()->id(), $id, $version),
            'submit' => $a->submitClaim(auth()->id(), $id, $version),
            'claim' => $a->approveClaim(auth()->id(), $id, $version),
            'recognition' => $a->approveRecognition(auth()->id(), $id, $version),
            'reversal' => $a->approveRecognitionReversal(auth()->id(), $id, $version),
        };
        $this->creationKey = (string) Str::uuid();
        session()->flash('status', 'تم حفظ الإجراء المالي المرتبط بالمصدر.');
    }

    public function render()
    {
        $context = $this->context();
        $rows = [];
        foreach (['sources' => 'billing_sources', 'measurements' => 'measurements', 'claims' => 'claims', 'recognitions' => 'recognitions', 'reversals' => 'recognition_reversals', 'work' => 'work'] as $key => $table) {
            $rows[$key] = DB::table('service_contract_'.$table)->where('contract_id', $this->contractId)->orderByDesc('id')->limit(100)->get();
        }
        $allocations = DB::table('service_contract_billing_allocations')->whereIn('source_id', $rows['sources']->pluck('id'))->pluck('invoice_id', 'source_id');

        return view('service-contracts.billing', $context + $rows + compact('allocations'));
    }
}

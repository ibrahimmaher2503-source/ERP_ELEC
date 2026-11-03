<?php

namespace App\Modules\ServiceContracts\Presentation;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\ServiceContracts\Application\ServiceContractAccess;
use App\Modules\ServiceContracts\Application\ServiceContractActions;
use App\Modules\ServiceContracts\Application\ServiceContractDocuments;
use App\Modules\ServiceContracts\Application\ServiceContractPreventive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class ServiceContractPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $contractId = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey = '';

    #[Locked]
    public string $mode = 'draft';

    public string $customerSearch = '';

    public string $employeeSearch = '';

    public string $productSearch = '';

    public array $form = ['branch_id' => '', 'customer_id' => '', 'responsible_employee_id' => '', 'title' => '', 'starts_on' => '', 'ends_on' => '', 'value' => '',
        'service_product_id' => '', 'billing_mode' => '', 'terms' => '', 'sla_response_minutes' => '', 'sla_resolution_minutes' => '', 'covered_labor_minutes' => '',
        'pricing_tax_mode' => '', 'coverage' => [], 'preventive_schedule' => [], 'parts' => [], 'excluded_parts' => [], 'schedule' => [], 'boq' => []];

    public string $preventiveCode = '';

    public string $preventiveResponsible = '';

    #[Locked]
    public string $preventiveKey = '';

    public $file;

    public string $classification = '';

    public string $documentReference = '';

    public string $signatureDocument = '';

    public string $signedOn = '';

    public string $nextState = '';

    public string $stateReason = '';

    public function mount(?int $contract = null): void
    {
        $this->contractId = $contract;
        $this->creationKey = (string) Str::uuid();
        $this->preventiveKey = (string) Str::uuid();
        if ($contract) {
            $this->load();
        } else {
            abort_unless(app(ServiceContractAccess::class)->branches(auth()->id(), 'contract_create'), 403);
        }
    }

    private function load(): void
    {
        $c = DB::table('service_contracts')->find($this->contractId);
        abort_unless($c, 404);
        app(ServiceContractAccess::class)->authorize(auth()->id(), (int) $c->branch_id);
        $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
        $this->form = array_replace($this->form, array_intersect_key(json_decode($r->data, true, 512, JSON_THROW_ON_ERROR), $this->form));
        $this->version = (int) $c->version;
        $this->mode = 'draft';
    }

    public function addRow(string $group): void
    {
        $row = match ($group) {
            'coverage' => ['equipment_ref' => '', 'site_id' => null, 'preventive_visits' => '', 'corrective_visits' => ''],
            'preventive_schedule' => ['code' => '', 'equipment_ref' => '', 'site_id' => null, 'due_date' => '', 'description' => ''],
            'parts' => ['product_id' => '', 'quantity' => ''],
            'schedule' => ['code' => '', 'due_date' => '', 'amount' => '', 'description' => ''],
            'boq' => ['code' => '', 'description' => '', 'product_id' => '', 'quantity' => '', 'rate' => ''],
            default => abort(422),
        };
        abort_unless(count($this->form[$group]) < 500, 422);
        $this->form[$group][] = $row;
    }

    public function removeRow(string $group, int $index): void
    {
        abort_unless(in_array($group, ['coverage', 'preventive_schedule', 'parts', 'schedule', 'boq']), 422);
        unset($this->form[$group][$index]);
        $this->form[$group] = array_values($this->form[$group]);
    }

    public function revise(string $mode): void
    {
        abort_unless(in_array($mode, ['amend', 'renew']), 422);
        $this->load();
        app(ServiceContractAccess::class)->authorize(auth()->id(), (int) $this->form['branch_id'], 'contract_edit');
        $this->mode = $mode;
        $this->creationKey = (string) Str::uuid();
    }

    public function save(): void
    {
        $a = app(ServiceContractActions::class);
        $id = match ($this->mode) {
            'amend' => tap($this->contractId, fn () => $a->amend(auth()->id(), $this->contractId, $this->version, $this->creationKey, $this->form)),
            'renew' => $a->renew(auth()->id(), $this->contractId, $this->version, $this->creationKey, $this->form),
            default => $a->save(auth()->id(), $this->contractId, $this->version, $this->creationKey, $this->form),
        };
        $this->redirectRoute('service-contracts.show', ['contract' => $id], navigate: true);
    }

    public function performAction(string $action): void
    {
        $a = app(ServiceContractActions::class);
        match ($action) {
            'submit' => $a->submit(auth()->id(), $this->contractId, $this->version),
            'approve' => $a->approve(auth()->id(), $this->contractId, $this->version),
            'activate' => $a->activate(auth()->id(), $this->contractId, $this->version),
            'sign' => $a->sign(auth()->id(), $this->contractId, $this->version, (int) $this->signatureDocument, $this->signedOn),
            'state' => $a->state(auth()->id(), $this->contractId, $this->version, $this->nextState, $this->stateReason),
            default => abort(422),
        };
        $this->load();
        session()->flash('status', 'تم حفظ إجراء العقد.');
    }

    public function upload(): void
    {
        $this->validate(['file' => 'required|file|max:10240']);
        app(ServiceContractDocuments::class)->store(auth()->id(), $this->contractId, $this->file, $this->classification, $this->documentReference);
        $this->reset('file', 'documentReference');
        session()->flash('status', 'تم حفظ دليل العقد الخاص.');
    }

    public function issue(): void
    {
        app(ServiceContractDocuments::class)->issue(auth()->id(), $this->contractId);
        session()->flash('status', 'تم إصدار وثيقة العقد المعتمدة.');
    }

    public function dispatchPreventive(): void
    {
        $work = app(ServiceContractPreventive::class)->dispatch(auth()->id(), $this->contractId, $this->preventiveCode, (int) $this->preventiveResponsible, $this->preventiveKey);
        $this->redirectRoute('maintenance.work-orders.show', ['workOrder' => $work], navigate: true);
    }

    public function render()
    {
        $access = app(ServiceContractAccess::class);
        $c = $this->contractId ? DB::table('service_contracts')->find($this->contractId) : null;
        if ($c) {
            $access->authorize(auth()->id(), (int) $c->branch_id);
        } else {
            abort_unless($access->branches(auth()->id(), 'contract_create'), 403);
        }
        $branches = $access->branches(auth()->id(), $c ? 'contract_edit' : 'contract_create');
        $validBranch = in_array((int) $this->form['branch_id'], array_column($branches, 'id'), true);
        $editable = (! $c || $this->mode !== 'draft' || DB::table('service_contract_revisions')->where('id', $c->current_revision_id)->value('status') === 'draft')
            && (! $c || $access->allows(auth()->id(), (int) $c->branch_id, 'contract_edit'));
        $employees = $validBranch && $editable ? app(OrganizationApi::class)->employees(auth()->id(), (int) $this->form['branch_id'], mb_substr($this->employeeSearch, 0, 200)) : [];
        if ($validBranch && $editable && ! empty($this->form['responsible_employee_id']) && ! collect($employees)->contains('id', (int) $this->form['responsible_employee_id'])) {
            $selectedEmployee = app(OrganizationApi::class)->employeeIdentity(auth()->id(), (int) $this->form['responsible_employee_id']);
            if ($selectedEmployee['branch_id'] === (int) $this->form['branch_id'] && $selectedEmployee['active']) {
                $employees[] = $selectedEmployee;
            }
        }
        $customers = $editable ? app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest(auth()->id(), mb_substr($this->customerSearch, 0, 200), 50)) : [];
        $products = $editable ? app(ProductsApi::class)->listMasters(new ListMastersRequest(auth()->id(), 'products', mb_substr($this->productSearch, 0, 200), true, 1, 100))->items : [];
        if ($editable) {
            $selectedProducts = [$this->form['service_product_id'], ...array_column($this->form['parts'], 'product_id'), ...array_column($this->form['boq'], 'product_id'), ...$this->form['excluded_parts']];
            foreach (array_unique(array_filter($selectedProducts)) as $id) {
                if (! collect($products)->contains('id', (int) $id)) {
                    $products[] = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $id))->product;
                }
            }
        }
        $sites = $editable && (int) $this->form['customer_id'] > 0 ? app(CustomersApi::class)->maintenanceContext(auth()->id(), (int) $this->form['customer_id'])['sites'] : [];
        $revisions = $c ? DB::table('service_contract_revisions')->where('contract_id', $c->id)->orderByDesc('revision')->get() : collect();
        $documents = $c ? DB::table('service_contract_documents')->where('contract_id', $c->id)->orderByDesc('id')->get() : collect();
        $activities = $c ? DB::table('service_contract_activities')->where('contract_id', $c->id)->orderByDesc('id')->limit(100)->get() : collect();
        $work = $c ? DB::table('service_contract_work')->where('contract_id', $c->id)->orderByDesc('id')->limit(100)->get() : collect();
        $officialRevisions = $c ? DB::table('service_contract_official_documents')->whereIn('revision_id', $revisions->pluck('id'))->pluck('revision_id')->all() : [];
        $activeTerms = $c?->active_revision_id ? json_decode(DB::table('service_contract_revisions')->where('id', $c->active_revision_id)->value('data'), true, 512, JSON_THROW_ON_ERROR) : [];
        $assignees = $c && $access->allows(auth()->id(), $c->branch_id, 'contract_edit') ? app(OrganizationApi::class)->projectAssignees(auth()->id(), (int) $c->branch_id) : [];
        $dispatches = $c ? DB::table('service_contract_preventive_dispatches')->where('contract_id', $c->id)->get() : collect();

        if ($editable && ! empty($this->form['customer_id']) && ! collect($customers)->contains('id', (int) $this->form['customer_id'])) {
            $customers[] = app(CustomersApi::class)->getCustomer(new GetCustomerRequest(auth()->id(), (int) $this->form['customer_id'], false));
        }

        return view('service-contracts.show', compact('c', 'branches', 'editable', 'employees', 'customers', 'products', 'sites', 'revisions', 'documents', 'activities', 'work', 'access', 'officialRevisions', 'activeTerms', 'assignees', 'dispatches'));
    }
}

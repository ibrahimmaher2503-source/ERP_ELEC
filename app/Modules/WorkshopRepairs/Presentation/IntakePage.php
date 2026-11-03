<?php

namespace App\Modules\WorkshopRepairs\Presentation;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\WorkshopRepairs\Application\WorkshopAccess;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopDocuments;
use App\Modules\WorkshopRepairs\Application\WorkshopReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IntakePage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $intakeId = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey;

    #[Locked]
    public string $receiveKey;

    #[Locked]
    public string $jobKey;

    public string $customerSearch = '';

    public string $productSearch = '';

    public string $companyEquipment = '';

    public string $receivedAt = '';

    public string $acknowledgment = '';

    public string $workshopId = '';

    public string $priority = 'normal';

    public string $billingMode = 'billable';

    public string $contractId = '';

    public string $serviceDate = '';

    public $attachment;

    public array $form = ['branch_id' => '', 'customer_id' => '', 'company_serial_id' => '', 'company_movement_id' => '', 'site_id' => '', 'contact_id' => '', 'product_id' => '', 'project_id' => '', 'maintenance_work_order_id' => '', 'maintenance_visit_id' => '', 'equipment_description' => '', 'model' => '', 'serial_number' => '', 'accessories' => '', 'reported_fault' => '', 'arrival_condition' => '', 'customer_owned' => true, 'location' => ''];

    public function mount(?int $intake = null): void
    {
        $this->intakeId = $intake;
        foreach (['creationKey', 'receiveKey', 'jobKey'] as $key) {
            $this->$key = (string) Str::uuid();
        }
        if ($intake) {
            $i = DB::table('workshop_intakes')->find($intake);
            abort_unless($i, 404);
            app(WorkshopAccess::class)->authorize(auth()->id(), $i->branch_id);
            $this->version = $i->version;
            foreach (array_keys($this->form) as $key) {
                $this->form[$key] = $i->$key ?? '';
            }
        } else {
            abort_unless(app(WorkshopAccess::class)->branches(auth()->id(), 'intake_manage'), 403);
        }
    }

    public function save(): void
    {
        $d = $this->form;
        foreach (['customer_id', 'company_serial_id', 'company_movement_id', 'site_id', 'contact_id', 'product_id', 'project_id', 'maintenance_work_order_id', 'maintenance_visit_id'] as $key) {
            $d[$key] = $d[$key] ?: null;
        }
        $id = app(WorkshopActions::class)->saveIntake(auth()->id(), $this->intakeId, $this->version, $this->creationKey, $d);
        $this->redirectRoute('workshops.intakes.show', ['intake' => $id], navigate: true);
    }

    public function updatedFormCustomerOwned(bool $customerOwned): void
    {
        if (! $customerOwned) {
            foreach (['customer_id', 'site_id', 'contact_id', 'project_id', 'maintenance_work_order_id', 'maintenance_visit_id'] as $field) {
                $this->form[$field] = '';
            }
        } else {
            $this->form['company_serial_id'] = '';
            $this->form['company_movement_id'] = '';
        }
    }

    public function updatedCompanyEquipment(): void
    {
        $this->form['company_serial_id'] = '';
        $this->form['company_movement_id'] = '';
        if (preg_match('/^(serial|receipt):([1-9][0-9]*)$/D', $this->companyEquipment, $matches)) {
            $this->form[$matches[1] === 'serial' ? 'company_serial_id' : 'company_movement_id'] = $matches[2];
        }
    }

    public function receive(): void
    {
        abort_unless($this->intakeId, 422);
        $this->validate(['receivedAt' => 'required|date', 'acknowledgment' => 'required|string|min:5']);
        app(WorkshopActions::class)->receive(auth()->id(), $this->intakeId, $this->version, $this->receiveKey, CarbonImmutable::parse($this->receivedAt, 'Africa/Cairo')->toIso8601String(), $this->acknowledgment);
        $this->refreshVersion();
    }

    public function createJob(): void
    {
        abort_unless($this->intakeId, 422);
        $mode = $this->form['customer_owned'] ? $this->billingMode : 'nonbillable';
        $id = app(WorkshopActions::class)->createJob(auth()->id(), $this->intakeId, $this->version, $this->jobKey, (int) $this->workshopId, $this->priority, $mode,
            $mode === 'contract' ? (int) $this->contractId : null, $mode === 'contract' ? $this->serviceDate : null);
        $this->redirectRoute('workshops.jobs.show', ['job' => $id], navigate: true);
    }

    public function saveAttachment(): void
    {
        abort_unless($this->intakeId, 422);
        $this->validate(['attachment' => 'required|file|max:10240']);
        app(WorkshopDocuments::class)->upload(auth()->id(), $this->intakeId, (string) Str::uuid(), $this->attachment);
        $this->reset('attachment');
    }

    private function refreshVersion(): void
    {
        $this->version = DB::table('workshop_intakes')->where('id', $this->intakeId)->value('version');
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof HttpException && in_array($e->getStatusCode(), [409, 422])) {
            $this->addError('operation', $e->getMessage() ?: 'تغير السجل أو العملية غير صالحة.');
            $stopPropagation();
        }
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(WorkshopAccess::class);
        $i = $this->intakeId ? DB::table('workshop_intakes')->find($this->intakeId) : null;
        if ($i) {
            $access->authorize($actor, $i->branch_id);
        }
        $editing = ! $i || ($i->status === 'draft' && $access->allows($actor, $i->branch_id, 'intake_manage'));
        $customer = (int) $this->form['customer_id'];
        $branch = (int) $this->form['branch_id'];
        $equipmentOptions = [];
        $equipmentAvailable = true;
        if ($editing && ! $this->form['customer_owned'] && $branch) {
            try {
                $equipmentOptions = app(InventoryApi::class)->repairEquipmentOptions($actor, $branch, $this->productSearch, $this->companyEquipment);
            } catch (ContractException) {
                $equipmentAvailable = false;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
                $equipmentAvailable = false;
            }
        }
        $contracts = [];
        $contractsAvailable = true;
        if ($i?->status === 'received' && $i->customer_owned && $this->billingMode === 'contract') {
            try {
                $contracts = app(ServiceContractsApi::class)->workshopOptions($actor, $customer, $branch);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
                $contractsAvailable = false;
            }
        }
        $customers = $editing && $this->form['customer_owned'] ? app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actor, mb_substr($this->customerSearch, 0, 200))) : [];
        if ($editing && $this->form['customer_owned'] && $customer && ! collect($customers)->contains(fn ($c) => $c->id === $customer)) {
            $customers[] = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $customer));
        }

        $products = [];
        if ($editing && $this->form['customer_owned']) {
            try {
                $products = app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'products', mb_substr($this->productSearch, 0, 200), true, 1, 100))->items;
                $selected = (int) $this->form['product_id'];
                if ($selected && ! collect($products)->contains('id', $selected)) {
                    $products[] = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $selected))->product;
                }
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
            }
        }

        return view('workshops.intake', compact('i', 'access', 'editing', 'customers', 'contracts', 'contractsAvailable', 'equipmentOptions', 'equipmentAvailable', 'products') + ['equipmentHistory' => $i ? app(WorkshopReports::class)->equipmentHistory($actor, $i->id) : [], 'branches' => $access->branches($actor, 'intake_manage'), 'context' => $editing && $customer ? app(CustomersApi::class)->maintenanceContext($actor, $customer) : ['sites' => [], 'contacts' => []], 'projects' => $editing && $branch && $customer ? app(ProjectsApi::class)->maintenanceProjects($actor, $branch, $customer) : [], 'workshops' => $access->query($actor, 'workshops')->where('branch_id', $branch)->where('active', true)->get(), 'custody' => $i ? DB::table('workshop_custody_events')->where('intake_id', $i->id)->orderBy('id')->get() : collect(), 'jobs' => $i ? DB::table('workshop_jobs')->where('intake_id', $i->id)->get() : collect(), 'documents' => $i ? DB::table('workshop_documents')->where('intake_id', $i->id)->get() : collect()]);
    }
}

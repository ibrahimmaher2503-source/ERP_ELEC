<?php

namespace App\Modules\FieldMaintenance\Presentation;

use App\Modules\FieldMaintenance\Application\MaintenanceAccess;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceDocuments;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MaintenanceRequestPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $requestId = null;

    #[Locked]
    public bool $standalone = false;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey;

    public array $form = ['branch_id' => '', 'customer_id' => '', 'site_id' => '', 'contact_id' => '', 'product_id' => '', 'project_id' => '', 'project_task_id' => '', 'project_milestone_id' => '', 'responsible_id' => '', 'description' => '', 'priority' => 'normal', 'reported_at' => '', 'preferred_date' => ''];

    public string $reason = '';

    public string $customerSearch = '';

    public string $productSearch = '';

    public $attachment;

    private string $errorPrefix = '';

    public function updatedForm($value, string $key): void
    {
        if (in_array($key, ['branch_id', 'customer_id'])) {
            foreach (['project_id', 'project_task_id', 'project_milestone_id'] as $field) {
                $this->form[$field] = '';
            }
        }
        if ($key === 'branch_id') {
            $this->form['responsible_id'] = '';
        }
        if ($key === 'customer_id') {
            $this->form['site_id'] = $this->form['contact_id'] = '';
        }
        if ($key === 'project_id') {
            $this->form['project_task_id'] = $this->form['project_milestone_id'] = '';
        }
    }

    public function mount(?int $request = null, bool $standalone = false): void
    {
        $this->standalone = $standalone;
        $this->creationKey = (string) Str::uuid();
        $this->requestId = $request;
        if ($request) {
            $this->reload();
        } else {
            abort_unless(app(MaintenanceAccess::class)->branches(auth()->id(), $this->standalone ? 'standalone_create' : 'request_create'), 403);
            $this->form['reported_at'] = now('Africa/Cairo')->format('Y-m-d\TH:i');
        }
    }

    private function reload(): void
    {
        $r = app(MaintenanceAccess::class)->query(auth()->id(), 'maintenance_requests')->find($this->requestId);
        abort_unless($r, 404);
        app(MaintenanceAccess::class)->authorize(auth()->id(), $r->branch_id);
        $this->version = $r->version;
        foreach (array_keys($this->form) as $key) {
            $this->form[$key] = $r->$key ?? '';
        }
        $this->form['reported_at'] = CarbonImmutable::parse($r->reported_at)->setTimezone('Africa/Cairo')->format('Y-m-d\TH:i');
    }

    public function save(bool $submit = false): void
    {
        $this->resetValidation();
        $this->errorPrefix = 'form';
        $this->validate(['form.reported_at' => 'required|date'], [], ['form.reported_at' => 'وقت البلاغ']);
        $d = $this->form;
        foreach (['site_id', 'contact_id', 'product_id', 'project_id', 'project_task_id', 'project_milestone_id', 'preferred_date'] as $f) {
            $d[$f] = $d[$f] ?: null;
        }
        $d['reported_at'] = CarbonImmutable::parse($d['reported_at'], 'Africa/Cairo')->toIso8601String();
        if ($this->standalone) {
            $id = app(MaintenanceActions::class)->createWorkOrder(auth()->id(), null, 0, $this->creationKey, $d);
            $this->redirectRoute('maintenance.work-orders.show', ['workOrder' => $id], navigate: true);

            return;
        }
        $id = app(MaintenanceActions::class)->saveRequest(auth()->id(), $this->requestId, $this->version, $this->creationKey, $d);
        $this->requestId = $id;
        $this->reload();
        if ($submit) {
            app(MaintenanceActions::class)->requestState(auth()->id(), $id, $this->version, 'submit');
        }
        session()->flash('maintenance_notice', $submit ? 'تم حفظ الطلب وتقديمه للمراجعة.' : 'تم حفظ مسودة طلب الصيانة. الخطوة التالية: تقديم الطلب للمراجعة.');
        $this->redirectRoute('maintenance.requests.show', ['request' => $id], navigate: true);
    }

    public function changeState(string $action): void
    {
        app(MaintenanceActions::class)->requestState(auth()->id(), $this->requestId, $this->version, $action, $this->reason);
        $this->reload();
        $this->reset('reason');
        $this->resetValidation();
        session()->flash('maintenance_notice', 'تم تحديث حالة طلب الصيانة.');
    }

    public function createWorkOrder(): void
    {
        $id = app(MaintenanceActions::class)->createWorkOrder(auth()->id(), $this->requestId, $this->version, $this->creationKey);
        $this->redirectRoute('maintenance.work-orders.show', ['workOrder' => $id], navigate: true);
    }

    public function saveAttachment(): void
    {
        $this->validate(['attachment' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240']);
        app(MaintenanceDocuments::class)->upload(auth()->id(), $this->requestId, null, null, (string) Str::uuid(), $this->attachment);
        $this->reset('attachment');
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof ValidationException) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [($this->errorPrefix && ! str_starts_with($key, 'form.') ? 'form.'.$key : $key) => $messages])->all());
            $this->dispatch('maintenance-invalid');
            $stopPropagation();
        }
        if ($e instanceof HttpException && in_array($e->getStatusCode(), [409, 422])) {
            $this->addError('operation', $e->getMessage() ?: 'تغير الطلب. أعد فتح الصفحة.');
            $stopPropagation();
        }
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(MaintenanceAccess::class);
        $r = $this->requestId ? DB::table('maintenance_requests')->find($this->requestId) : null;
        if ($r) {
            $access->authorize($actor, $r->branch_id);
        }
        $branch = (int) $this->form['branch_id'];
        $customer = (int) $this->form['customer_id'];
        $editing = ! $r || ($r->status === 'draft' && $access->allows($actor, $r->branch_id, 'request_edit'));
        $customers = $editing ? app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actor, mb_substr($this->customerSearch, 0, 200))) : [];
        if ($editing && $customer && ! collect($customers)->contains(fn ($c) => $c->id === $customer)) {
            $customers[] = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $customer, false));
        }
        $context = $customer ? app(CustomersApi::class)->maintenanceContext($actor, $customer) : ['sites' => [], 'contacts' => []];

        $planning = $editing && ! empty($this->form['project_id']) && $branch && $customer ? app(ProjectsApi::class)->maintenancePlanning($actor, (int) $this->form['project_id'], $branch, $customer) : ['tasks' => [], 'milestones' => []];
        $products = [];
        try {
            $products = ! $editing ? [] : app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'products', mb_substr($this->productSearch, 0, 200), true, 1, 100))->items;
            if (! empty($this->form['product_id']) && ! collect($products)->contains(fn ($p) => (int) $p['id'] === (int) $this->form['product_id'])) {
                $products[] = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $this->form['product_id']))->product;
            }
        } catch (HttpException) {
        }

        $linkedWorkOrder = $r ? $access->query($actor)->where('request_id', $r->id)->first(['id', 'number']) : null;

        return view('maintenance.request', compact('r', 'customers', 'context', 'access', 'products', 'planning', 'editing', 'linkedWorkOrder') + ['branches' => $access->branches($actor, $this->standalone ? 'standalone_create' : 'request_create'), 'users' => $branch ? app(OrganizationApi::class)->projectAssignees($actor, $branch) : [], 'projects' => $branch && $customer ? app(ProjectsApi::class)->maintenanceProjects($actor, $branch, $customer) : [], 'documents' => $r ? DB::table('maintenance_documents')->where('request_id', $r->id)->get() : collect()]);
    }
}

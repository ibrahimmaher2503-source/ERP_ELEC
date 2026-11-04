<?php

namespace App\Modules\FieldMaintenance\Presentation;

use App\Modules\FieldMaintenance\Application\MaintenanceAccess;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MaintenancePage extends Component
{
    use WithPagination;

    #[Locked]
    public string $screen = 'dashboard';

    public string $search = '';

    public string $status = '';

    #[Locked]
    public ?int $templateId = null;

    #[Locked]
    public int $templateVersion = 0;

    public function editTemplate(int $id): void
    {
        $t = DB::table('maintenance_checklist_templates')->find($id);
        abort_unless($t, 404);
        app(MaintenanceAccess::class)->authorize(auth()->id(), $t->branch_id, 'checklist_manage');
        $this->templateId = $id;
        $this->templateVersion = $t->version;
        $this->template = ['branch_id' => $t->branch_id, 'code' => $t->code, 'title' => $t->title, 'items' => json_decode($t->items, true, 512, JSON_THROW_ON_ERROR), 'active' => $t->active];
    }

    public array $template = ['branch_id' => '', 'code' => '', 'title' => '', 'items' => [['key' => 'safety', 'label' => '', 'required' => true]], 'active' => true];

    public function mount(string $screen = 'dashboard'): void
    {
        abort_unless(in_array($screen, ['dashboard', 'requests', 'work-orders', 'assignments', 'schedule', 'checklists', 'reports']), 404);
        $this->screen = $screen;
        abort_unless(app(MaintenanceAccess::class)->branches(auth()->id(), $screen === 'reports' ? 'reports_view' : 'view'), 403);
    }

    public function addItem(): void
    {
        $this->template['items'][] = ['key' => 'item_'.str_replace('-', '_', (string) Str::uuid()), 'label' => '', 'required' => true];
    }

    public function saveTemplate(): void
    {
        $this->template['branch_id'] = (int) $this->template['branch_id'];
        app(MaintenanceExecution::class)->saveTemplate(auth()->id(), $this->templateId, $this->templateVersion, $this->template);
        $this->templateId = null;
        $this->templateVersion = 0;
        $this->template = ['branch_id' => '', 'code' => '', 'title' => '', 'items' => [['key' => 'safety', 'label' => '', 'required' => true]], 'active' => true];
        session()->flash('maintenance_notice', 'تم حفظ قالب الفحص.');
    }

    public function removeItem(int $index): void
    {
        abort_unless(isset($this->template['items'][$index]) && count($this->template['items']) > 1, 422);
        unset($this->template['items'][$index]);
        $this->template['items'] = array_values($this->template['items']);
    }

    public function cancelTemplate(): void
    {
        $this->templateId = null;
        $this->templateVersion = 0;
        $this->template = ['branch_id' => '', 'code' => '', 'title' => '', 'items' => [['key' => 'safety', 'label' => '', 'required' => true]], 'active' => true];
        $this->resetValidation();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof ValidationException) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ['template.'.$key => $messages])->all());
            $this->dispatch('maintenance-invalid');
            $stopPropagation();
        }
        if ($e instanceof HttpException && in_array($e->getStatusCode(), [409, 422])) {
            $this->addError('operation', $e->getMessage() ?: 'تعذر حفظ القالب. أعد فتحه للمراجعة.');
            $stopPropagation();
        }
    }

    public function render()
    {
        $access = app(MaintenanceAccess::class);
        $actor = auth()->id();
        $workQuery = $access->query($actor);
        if ($this->screen === 'reports') {
            $workQuery->whereIn('branch_id', array_column($access->branches($actor, 'reports_view'), 'id'));
        }
        $query = $this->screen === 'requests' ? $access->query($actor, 'maintenance_requests') : clone $workQuery;
        $query->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'ilike', '%'.$this->search.'%')->orWhere('customer_name', 'ilike', '%'.$this->search.'%')->orWhere('description', 'ilike', '%'.$this->search.'%')))
            ->when($this->status, fn ($q) => $q->where('status', $this->status));
        if ($this->screen === 'reports') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('maintenance_completions')->whereColumn('work_order_id', 'maintenance_work_orders.id'))
                ->select('maintenance_work_orders.*')
                ->selectSub(DB::table('maintenance_completions')->whereColumn('work_order_id', 'maintenance_work_orders.id')->orderByDesc('revision')->limit(1)->select('id'), 'completion_id')
                ->selectSub(DB::table('maintenance_completions')->whereColumn('work_order_id', 'maintenance_work_orders.id')->orderByDesc('revision')->limit(1)->select('revision'), 'report_revision');
        }
        if (in_array($this->screen, ['dashboard', 'work-orders'])) {
            $query->select('maintenance_work_orders.*')->selectSub(DB::table('maintenance_visits')->whereColumn('work_order_id', 'maintenance_work_orders.id')->where('status', 'planned')->orderBy('scheduled_at')->limit(1)->select('scheduled_at'), 'next_visit_at');
        }
        $rows = $query->orderByDesc('id')->paginate(25);
        $workIds = (clone $workQuery)->select('id');
        $stats = $this->screen === 'dashboard' ? (clone $workQuery)->selectRaw('status,count(*) total')->groupBy('status')->pluck('total', 'status')->all() : [];
        $assignments = $this->screen === 'assignments' ? DB::table('maintenance_assignments as a')->join('maintenance_work_orders as w', 'w.id', '=', 'a.work_order_id')->whereIn('a.work_order_id', clone $workIds)->where('a.active', true)->orderBy('a.starts_at')->get(['a.*', 'w.number', 'w.customer_name', 'w.branch_id']) : collect();
        $visits = $this->screen === 'schedule' ? DB::table('maintenance_visits as v')->join('maintenance_work_orders as w', 'w.id', '=', 'v.work_order_id')->whereIn('v.work_order_id', clone $workIds)->orderBy('v.scheduled_at')->get(['v.*', 'w.number', 'w.customer_name', 'w.branch_id']) : collect();
        $templates = $this->screen === 'checklists' ? DB::table('maintenance_checklist_templates')->whereIn('branch_id', array_column($access->branches($actor), 'id'))->orderBy('code')->get() : collect();
        $orderAssignments = in_array($this->screen, ['dashboard', 'work-orders']) ? DB::table('maintenance_assignments')->whereIn('work_order_id', $rows->pluck('id'))->where('active', true)->get() : collect();
        $people = [];
        foreach (collect($rows->items())->merge($assignments)->merge($visits)->groupBy('branch_id') as $branch => $records) {
            $ids = $records->pluck('technician_id')->merge($records->pluck('responsible_id'))->merge($orderAssignments->whereIn('work_order_id', $records->pluck('id'))->pluck('technician_id'))->filter()->unique()->all();
            if ($ids) {
                $people += app(OrganizationApi::class)->projectParticipantNames($actor, $branch, $ids);
            }
        }
        $siteNames = [];
        if (in_array($this->screen, ['dashboard', 'requests', 'work-orders'])) {
            foreach ($rows->pluck('customer_id')->unique() as $customer) {
                try {
                    foreach (app(CustomersApi::class)->maintenanceContext($actor, $customer)['sites'] as $site) {
                        $siteNames[$site['id']] = $site['label'];
                    }
                } catch (HttpException|CustomerContractException|ContractException) {
                }
            }
        }
        $today = CarbonImmutable::now('Africa/Cairo');
        $todayVisits = $this->screen === 'dashboard' ? DB::table('maintenance_visits')->whereIn('work_order_id', clone $workIds)->where('status', 'planned')->whereBetween('scheduled_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])->count() : 0;
        $submittedRequests = $this->screen === 'dashboard' ? $access->query($actor, 'maintenance_requests')->where('status', 'submitted')->count() : 0;

        return view('maintenance.index', compact('stats', 'assignments', 'visits', 'templates', 'access', 'rows', 'people', 'siteNames', 'orderAssignments', 'todayVisits', 'submittedRequests') + ['branches' => $access->branches($actor), 'createBranches' => $access->branches($actor, 'request_create'), 'templateBranches' => $access->branches($actor, 'checklist_manage')]);
    }
}

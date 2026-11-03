<?php

namespace App\Modules\WorkshopRepairs\Presentation;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\WorkshopRepairs\Application\WorkshopAccess;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopReports;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WorkshopsPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $screen = 'dashboard';

    #[Locked]
    public ?int $workshopId = null;

    #[Locked]
    public int $version = 0;

    #[Url]
    public string $search = '';

    public string $workshopFilter = '';

    #[Url]
    public string $statusFilter = '';

    public string $billingFilter = '';

    public bool $overdueOnly = false;

    public string $technicianId = '';

    public bool $memberActive = true;

    public array $form = ['code' => '', 'name' => '', 'specialty' => '', 'branch_id' => '', 'supervisor_id' => '', 'warehouse_id' => '', 'capacity' => '1', 'schedule' => [['day' => 0, 'start' => '', 'end' => '']], 'active' => true];

    public function mount(string $screen = 'dashboard'): void
    {
        abort_unless(in_array($screen, ['dashboard', 'settings', 'intakes', 'jobs', 'reports']), 404);
        $this->screen = $screen;
        abort_unless(app(WorkshopAccess::class)->branches(auth()->id(), $screen === 'reports' ? 'reports_view' : 'view'), 403);
    }

    public function editWorkshop(int $id): void
    {
        $w = DB::table('workshops')->find($id);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize(auth()->id(), $w->branch_id, 'manage');
        $this->workshopId = $id;
        $this->version = $w->version;
        foreach (array_keys($this->form) as $key) {
            $this->form[$key] = $key === 'schedule' ? json_decode($w->schedule, true) : $w->$key;
        }
    }

    public function newWorkshop(): void
    {
        abort_unless(app(WorkshopAccess::class)->branches(auth()->id(), 'manage'), 403);
        $this->reset('workshopId', 'version', 'form', 'technicianId');
        $this->resetValidation();
    }

    public function addDay(): void
    {
        $this->form['schedule'][] = ['day' => 0, 'start' => '', 'end' => ''];
    }

    public function saveWorkshop(): void
    {
        $input = $this->form;
        $input['warehouse_id'] = $input['warehouse_id'] ?: null;
        $id = app(WorkshopActions::class)->saveWorkshop(auth()->id(), $this->workshopId, $this->version, $input);
        $this->editWorkshop($id);
        session()->flash('workshop_notice', 'تم حفظ إعداد الورشة.');
    }

    public function saveMember(): void
    {
        abort_unless($this->workshopId, 422);
        app(WorkshopActions::class)->member(auth()->id(), $this->workshopId, $this->version, (int) $this->technicianId, $this->memberActive);
        $this->editWorkshop($this->workshopId);
        session()->flash('workshop_notice', 'تم حفظ عضوية الفني.');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'workshopFilter', 'billingFilter', 'overdueOnly'])) {
            $this->resetPage();
        }
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof HttpException && in_array($e->getStatusCode(), [409, 422])) {
            $this->addError('operation', $e->getMessage() ?: 'تعذر تنفيذ العملية. أعد فتح السجل.');
            $stopPropagation();
        }
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(WorkshopAccess::class);
        $branch = (int) $this->form['branch_id'];
        $intakes = $access->query($actor);
        $jobs = app(WorkshopReports::class)->query($actor, ['search' => $this->search, 'workshop_id' => $this->workshopFilter ?: null, 'status' => $this->statusFilter ?: null, 'billing_mode' => $this->billingFilter ?: null, 'overdue' => $this->overdueOnly]);
        $rowQuery = in_array($this->screen, ['dashboard', 'jobs', 'reports']) ? clone $jobs : clone $intakes;
        if ($this->screen === 'dashboard') {
            $rowQuery->whereNotIn('status', ['delivered', 'returned_unrepaired']);
        }
        $rows = $rowQuery->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'ilike', '%'.$this->search.'%')->orWhere('customer_name', 'ilike', '%'.$this->search.'%')))->orderByDesc('id')->paginate($this->screen === 'dashboard' ? 8 : 25);
        $equipment = DB::table('workshop_intakes')->whereIn('id', in_array($this->screen, ['dashboard', 'jobs', 'reports']) ? $rows->pluck('intake_id') : $rows->pluck('id'))->get(['id', 'equipment_description', 'serial_number', 'reported_fault'])->keyBy('id');
        $openJobs = (clone $jobs)->whereNotIn('status', ['delivered', 'returned_unrepaired'])
            ->selectRaw('workshop_id, count(*) total')->groupBy('workshop_id')->pluck('total', 'workshop_id');
        $workshops = $access->query($actor, 'workshops')->orderBy('code')->get()->map(function ($w) use ($openJobs) {
            $w->open_jobs = (int) ($openJobs[$w->id] ?? 0);

            return $w;
        });
        $stats = (clone $jobs)->selectRaw('status,count(*) total')->groupBy('status')->pluck('total', 'status')->all();
        $workload = (clone $jobs)->whereNotIn('status', ['delivered', 'returned_unrepaired'])->whereNotNull('technician_id')->selectRaw('technician_id,workshop_id,count(*) total,coalesce(sum(estimated_minutes),0) planned_minutes')->groupBy('technician_id', 'workshop_id')->get();
        $turnaround = DB::table('workshop_deliveries as d')->join('workshop_intakes as i', 'i.id', '=', 'd.intake_id')->whereIn('i.id', (clone $intakes)->select('id'))->selectRaw('round(avg(extract(epoch from (d.delivered_at-i.received_at))/3600)::numeric,2) average_hours')->value('average_hours');

        return view('workshops.index', compact('rows', 'workshops', 'stats', 'access', 'turnaround', 'workload', 'equipment') + ['branches' => $access->branches($actor, 'manage'), 'users' => $branch ? app(OrganizationApi::class)->projectAssignees($actor, $branch) : [], 'warehouses' => $branch ? app(InventoryApi::class)->reservationWarehouses($actor, $branch) : [], 'members' => $this->workshopId ? DB::table('workshop_members')->where('workshop_id', $this->workshopId)->get() : collect(), 'undelivered' => (clone $intakes)->whereNotIn('status', ['draft', 'delivered', 'returned_unrepaired'])->count()]);
    }
}

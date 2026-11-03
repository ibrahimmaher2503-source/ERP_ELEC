<?php

namespace App\Modules\FieldMaintenance\Presentation;

use App\Modules\FieldMaintenance\Application\MaintenanceAccess;
use App\Modules\FieldMaintenance\Application\MaintenanceActions;
use App\Modules\FieldMaintenance\Application\MaintenanceBilling;
use App\Modules\FieldMaintenance\Application\MaintenanceCostProposals;
use App\Modules\FieldMaintenance\Application\MaintenanceDocuments;
use App\Modules\FieldMaintenance\Application\MaintenanceExecution;
use App\Modules\FieldMaintenance\Application\MaintenanceParts;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MaintenanceWorkOrderPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $workOrderId;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $visitKey;

    #[Locked]
    public string $laborKey;

    #[Locked]
    public string $reserveKey;

    #[Locked]
    public string $issueKey;

    #[Locked]
    public string $returnKey;

    #[Locked]
    public string $invoiceKey;

    #[Url(except: 'details')]
    public string $tab = 'details';

    public string $reason = '';

    public array $draft = ['instructions' => '', 'priority' => 'normal', 'billable' => false, 'acknowledgment_required' => false, 'estimated_cost' => '', 'estimated_duration_minutes' => ''];

    public string $expenseJournal = '';

    public array $assignment = ['technician_id' => '', 'starts_at' => '', 'ends_at' => '', 'override_reason' => '', 'replace_id' => ''];

    public array $visit = ['assignment_id' => '', 'scheduled_at' => '', 'template_id' => '', 'mandatory' => true];

    public string $visitId = '';

    #[Locked]
    public int $visitVersion = 0;

    public array $record = ['arrival_at' => '', 'departure_at' => '', 'work_performed' => '', 'diagnosis' => '', 'observations' => '', 'follow_up' => '', 'acknowledgment' => '', 'outcome' => 'completed', 'cancellation_reason' => ''];

    public array $results = [];

    public array $labor = ['visit_id' => '', 'starts_at' => '', 'ends_at' => '', 'work_type' => 'repair', 'notes' => ''];

    public array $part = ['product_id' => '', 'warehouse_id' => '', 'quantity' => ''];

    public string $requirementId = '';

    public string $reservationId = '';

    public string $quantity = '';

    public string $productSearch = '';

    public string $issueId = '';

    public string $usageVisit = '';

    public string $partSerials = '';

    public array $commercial = ['product_id' => '', 'amount' => '', 'date' => '', 'price_reference' => ''];

    public string $proposerId = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public array $rate = ['branch_id' => '', 'technician_id' => '', 'hourly_cost' => '', 'effective_from' => '', 'effective_to' => '', 'reference' => ''];

    public $attachment;

    public string $attachmentVisit = '';

    public string $notice = '';

    private string $errorPrefix = '';

    public function mount(int $workOrder): void
    {
        abort_unless(in_array($this->tab, ['details', 'assignments', 'visits', 'parts', 'labor', 'costs', 'completion', 'billing', 'documents', 'history']), 404);
        $this->workOrderId = $workOrder;
        $this->reload();
        foreach (['visitKey', 'laborKey', 'reserveKey', 'issueKey', 'returnKey', 'invoiceKey'] as $key) {
            $this->$key = (string) Str::uuid();
        }
        $this->commercial['date'] = $this->invoiceDate = $this->dueDate = now('Africa/Cairo')->toDateString();
    }

    private function row(): object
    {
        $w = DB::table('maintenance_work_orders')->find($this->workOrderId);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize(auth()->id(), $w->branch_id);
        abort_unless(app(MaintenanceAccess::class)->query(auth()->id())->where('id', $w->id)->exists(), 403);

        return $w;
    }

    private function reload(): void
    {
        $w = $this->row();
        $this->version = $w->version;
        foreach (array_keys($this->draft) as $key) {
            $this->draft[$key] = $w->$key ?? '';
        }
    }

    public function saveDraft(): void
    {
        $this->errorPrefix = 'draft';
        $d = $this->draft;
        foreach (['estimated_cost', 'estimated_duration_minutes'] as $f) {
            $d[$f] = $d[$f] ?: null;
        }
        app(MaintenanceActions::class)->draft(auth()->id(), $this->workOrderId, $this->version, $d);
        $this->done();
    }

    public function proposeRate(): void
    {
        $this->errorPrefix = 'rate';
        $d = $this->rate;
        $d['branch_id'] = $this->row()->branch_id;
        app(MaintenanceCostProposals::class)->propose(auth()->id(), 'labor_rate', null, $d);
        $this->done('تم تقديم مرجع التكلفة باسمك للاعتماد المستقل.');
    }

    public function proposeCommercial(): void
    {
        $this->errorPrefix = 'commercial';
        app(MaintenanceCostProposals::class)->propose(auth()->id(), 'service', $this->workOrderId, $this->commercial);
        $this->done('تم تقديم السعر باسمك للاعتماد المستقل.');
    }

    public function loadProposal(int $proposal): void
    {
        $w = $this->row();
        $p = DB::table('maintenance_cost_proposals')->where('branch_id', $w->branch_id)->find($proposal);
        abort_unless($p, 404);
        if ($p->kind === 'service') {
            abort_unless($p->work_order_id === $w->id, 404);
            app(MaintenanceAccess::class)->authorize(auth()->id(), $w->branch_id, 'billing_approve');
            $this->commercial = json_decode($p->payload, true, 512, JSON_THROW_ON_ERROR);
        } else {
            app(MaintenanceAccess::class)->authorize(auth()->id(), $w->branch_id, 'rate_approve');
            $this->rate = json_decode($p->payload, true, 512, JSON_THROW_ON_ERROR);
        }
        $this->proposerId = (string) $p->created_by;
    }

    public function recognizeLabor(int $entry): void
    {
        app(MaintenanceBilling::class)->recognizeLabor(auth()->id(), $this->workOrderId, $entry);
        $this->done();
    }

    public function linkExpense(): void
    {
        app(MaintenanceBilling::class)->linkExpense(auth()->id(), $this->workOrderId, (int) $this->expenseJournal, $this->reason);
        $this->done();
    }

    private function done(string $notice = 'تم حفظ الإجراء.'): void
    {
        $this->reload();
        $this->resetValidation();
        $this->notice = $notice;
    }

    private function times(array $data, array $fields): array
    {
        foreach ($fields as $f) {
            if (! empty($data[$f])) {
                $data[$f] = CarbonImmutable::parse($data[$f], 'Africa/Cairo')->toIso8601String();
            } else {
                $data[$f] = null;
            }
        }

        return $data;
    }

    public function selectTab(string $tab): void
    {
        abort_unless(in_array($tab, ['details', 'assignments', 'visits', 'parts', 'labor', 'costs', 'completion', 'billing', 'documents', 'history']), 404);
        $this->row();
        $this->tab = $tab;
    }

    public function changeState(string $action): void
    {
        app(MaintenanceActions::class)->state(auth()->id(), $this->workOrderId, $this->version, $action, $this->reason);
        $this->reset('reason');
        $this->done('تم تحديث حالة أمر العمل.');
    }

    public function assign(): void
    {
        $this->errorPrefix = 'assignment';
        $d = $this->times($this->assignment, ['starts_at', 'ends_at']);
        $d['replace_id'] = $d['replace_id'] ?: null;
        $d['override_reason'] = $d['override_reason'] ?: null;
        app(MaintenanceExecution::class)->assign(auth()->id(), $this->workOrderId, $this->version, $d);
        $this->done();
    }

    public function schedule(): void
    {
        $this->errorPrefix = 'visit';
        app(MaintenanceExecution::class)->schedule(auth()->id(), $this->workOrderId, $this->version, $this->visitKey, $this->times($this->visit, ['scheduled_at']));
        $this->visitKey = (string) Str::uuid();
        $this->done();
    }

    public function reschedule(): void
    {
        $this->errorPrefix = 'visit';
        $this->row();
        $v = DB::table('maintenance_visits')->where('work_order_id', $this->workOrderId)->find((int) $this->visitId);
        abort_unless($v, 404);
        $d = $this->times($this->visit, ['scheduled_at']);
        app(MaintenanceExecution::class)->reschedule(auth()->id(), $this->workOrderId, $v->id, $v->version, (int) $d['assignment_id'], $d['scheduled_at'], $this->reason);
        $this->done();
    }

    public function endAssignment(int $assignment): void
    {
        app(MaintenanceExecution::class)->endAssignment(auth()->id(), $this->workOrderId, $this->version, $assignment, $this->reason);
        $this->done();
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof ValidationException) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [($this->errorPrefix && ! str_contains($key, '.') && ! property_exists($this, $key) ? $this->errorPrefix.'.'.$key : $key) => $messages])->all());
            $this->dispatch('maintenance-invalid');
            $stopPropagation();
        }
        if ($e instanceof HttpException && in_array($e->getStatusCode(), [409, 422])) {
            $this->addError('operation', $e->getMessage() ?: 'تعذر حفظ الإجراء. أعد تحميل البيانات.');
            $stopPropagation();
        }
    }

    public function updatedVisitId(): void
    {
        $this->row();
        $v = DB::table('maintenance_visits')->where('work_order_id', $this->workOrderId)->find((int) $this->visitId);
        $this->results = [];
        $this->attachmentVisit = $v && $v->status === 'planned' ? (string) $v->id : '';
        if ($v) {
            $this->visitVersion = $v->version;
            foreach (json_decode($v->template_snapshot, true) as $item) {
                $this->results[$item['key']] = ['result' => '', 'notes' => ''];
            }
        }
    }

    public function recordVisit(): void
    {
        $this->errorPrefix = 'record';
        $this->validate(['visitId' => 'required|integer|min:1'], [], ['visitId' => 'الزيارة']);
        $v = DB::table('maintenance_visits')->where('work_order_id', $this->workOrderId)->find((int) $this->visitId);
        abort_unless($v, 404);
        app(MaintenanceExecution::class)->recordVisit(auth()->id(), $this->workOrderId, $v->id, $this->visitVersion, $this->times($this->record, ['arrival_at', 'departure_at']), $this->results);
        if ($this->record['outcome'] !== 'cancelled') {
            $this->labor['visit_id'] = $this->usageVisit = (string) $v->id;
        }
        $this->attachmentVisit = '';
        $this->done('تم تثبيت تقرير الزيارة. سجل وقت العمل الفعلي وراجع استخدام القطع.');
    }

    public function recordLabor(): void
    {
        $this->errorPrefix = 'labor';
        app(MaintenanceExecution::class)->labor(auth()->id(), $this->workOrderId, $this->laborKey, $this->times($this->labor, ['starts_at', 'ends_at']));
        $this->laborKey = (string) Str::uuid();
        $this->done();
    }

    public function copyVisitTimes(): void
    {
        $w = $this->row();
        app(MaintenanceAccess::class)->authorize(auth()->id(), $w->branch_id, 'labor_record');
        $v = DB::table('maintenance_visits')->where('work_order_id', $w->id)->where('technician_id', auth()->id())->where('status', 'recorded')->where('outcome', '<>', 'cancelled')->find((int) $this->labor['visit_id']);
        abort_unless($v, 422, 'اختر زيارتك المنفذة أولًا.');
        $this->labor['starts_at'] = CarbonImmutable::parse($v->arrival_at)->setTimezone('Africa/Cairo')->format('Y-m-d\\TH:i');
        $this->labor['ends_at'] = CarbonImmutable::parse($v->departure_at)->setTimezone('Africa/Cairo')->format('Y-m-d\\TH:i');
        $this->notice = 'نُقلت أوقات الزيارة إلى النموذج. راجع وقت العمل الفعلي قبل الحفظ؛ لم تسجل ساعات بعد.';
    }

    public function approveRate(): void
    {
        $this->errorPrefix = 'rate';
        $d = $this->rate;
        $d['branch_id'] = $this->row()->branch_id;
        app(MaintenanceExecution::class)->approveRate(auth()->id(), (int) $this->proposerId, $d);
        $this->done();
    }

    public function addPart(): void
    {
        $this->errorPrefix = 'part';
        app(MaintenanceParts::class)->requirement(auth()->id(), $this->workOrderId, $this->version, $this->part);
        $this->done();
    }

    public function reserve(): void
    {
        app(MaintenanceParts::class)->reserve(auth()->id(), $this->workOrderId, (int) $this->requirementId, $this->quantity, $this->reserveKey);
        $this->reserveKey = (string) Str::uuid();
        $this->done();
    }

    public function issue(): void
    {
        app(MaintenanceParts::class)->issue(auth()->id(), $this->workOrderId, (int) $this->reservationId, $this->quantity, $this->issueKey);
        $this->issueKey = (string) Str::uuid();
        $this->done();
    }

    public function release(): void
    {
        app(MaintenanceParts::class)->release(auth()->id(), $this->workOrderId, (int) $this->reservationId, (string) Str::uuid());
        $this->done();
    }

    public function returnPart(): void
    {
        app(MaintenanceParts::class)->returnUnused(auth()->id(), $this->workOrderId, (int) $this->issueId, $this->quantity, $this->returnKey, $this->reason, preg_split('/\R/u', $this->partSerials, -1, PREG_SPLIT_NO_EMPTY));
        $this->returnKey = (string) Str::uuid();
        $this->done();
    }

    public function usePart(): void
    {
        app(MaintenanceParts::class)->usage(auth()->id(), $this->workOrderId, (int) $this->usageVisit, (int) $this->requirementId, $this->quantity, preg_split('/\R/u', $this->partSerials, -1, PREG_SPLIT_NO_EMPTY));
        $this->done();
    }

    public function approveCommercial(): void
    {
        $this->errorPrefix = 'commercial';
        app(MaintenanceBilling::class)->approve(auth()->id(), (int) $this->proposerId, $this->workOrderId, $this->version, $this->commercial);
        $this->done();
    }

    public function invoice(): void
    {
        app(MaintenanceBilling::class)->request(auth()->id(), $this->workOrderId, $this->invoiceKey, $this->invoiceDate, $this->dueDate);
        $this->done('تم إنشاء إحالة فاتورة الخدمة الرسمية.');
    }

    public function saveAttachment(): void
    {
        $this->validate(['attachment' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240']);
        app(MaintenanceDocuments::class)->upload(auth()->id(), null, $this->workOrderId, $this->attachmentVisit ? (int) $this->attachmentVisit : null, (string) Str::uuid(), $this->attachment);
        $this->reset('attachment');
        $this->done();
    }

    public function render()
    {
        $w = $this->row();
        $actor = auth()->id();
        $access = app(MaintenanceAccess::class);
        $branch = $w->branch_id;
        $can = [];
        foreach (['workorder_manage', 'assign', 'visit_record', 'parts_request', 'parts_issue', 'parts_return', 'labor_record', 'cost_view', 'billing_approve', 'billing_request', 'verify', 'close', 'reopen', 'document_manage', 'document_view', 'rate_approve'] as $p) {
            $can[$p] = $access->allows($actor, $branch, $p);
        }
        $assignments = DB::table('maintenance_assignments')->where('work_order_id', $w->id)->orderByDesc('id')->get();
        $visits = DB::table('maintenance_visits')->where('work_order_id', $w->id)->orderBy('scheduled_at')->get();
        $requirements = DB::table('maintenance_part_requirements')->where('work_order_id', $w->id)->get();
        $reservations = [];
        foreach (DB::table('maintenance_part_reservations')->whereIn('requirement_id', $requirements->pluck('id'))->get() as $l) {
            try {
                $reservations[] = app(InventoryApi::class)->reservationStatus($actor, $l->reservation_id) + ['requirement_id' => $l->requirement_id];
            } catch (\Throwable $e) {
                if (! ($e instanceof HttpException) && ! ($e instanceof ContractException)) {
                    throw $e;
                }
            }
        }
        $issues = DB::table('maintenance_part_issues')->whereIn('requirement_id', $requirements->pluck('id'))->get();
        $laborEntries = DB::table('maintenance_labor_entries')->where('work_order_id', $w->id)->get($can['cost_view'] ? ['*'] : ['id', 'visit_id', 'technician_id', 'starts_at', 'ends_at', 'duration_minutes', 'work_type', 'notes']);
        $products = [];
        $warehouses = [];
        $partAvailability = null;
        if (($this->tab === 'parts' && $can['parts_request']) || ($this->tab === 'billing' && ($can['billing_approve'] || $can['billing_request']))) {
            try {
                $products = $this->tab === 'billing' ? app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'products', mb_substr($this->productSearch, 0, 200), true, 1, 100))->items : app(ProductsApi::class)->inventoryProducts($actor, $this->productSearch);
                $selected = $this->tab === 'billing' ? (int) $this->commercial['product_id'] : (int) $this->part['product_id'];
                if ($selected > 0 && ! collect($products)->contains(fn ($p) => (int) $p['id'] === $selected)) {
                    $products[] = $this->tab === 'billing' ? app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $selected))->product : app(ProductsApi::class)->inventoryProduct($actor, $selected);
                }
            } catch (HttpException|ContractException) {
            }
        }
        if ($this->tab === 'parts' && $can['parts_request']) {
            try {
                $warehouses = app(InventoryApi::class)->reservationWarehouses($actor, $branch);
            } catch (HttpException|ContractException) {
            }
            if ((int) $this->part['product_id'] > 0 && (int) $this->part['warehouse_id'] > 0) {
                try {
                    $partAvailability = app(InventoryApi::class)->getAvailability($actor, (int) $this->part['warehouse_id'], (int) $this->part['product_id']);
                } catch (HttpException|ContractException) {
                }
            }
        }
        $invoice = null;
        $invoiceUnavailable = false;
        try {
            $invoice = app(MaintenanceBilling::class)->invoice($actor, $w->id);
        } catch (HttpException) {
            try {
                $invoice = app(MaintenanceBilling::class)->handoff($actor, $w->id);
            } catch (HttpException) {
                $invoiceUnavailable = true;
            }
        }
        $users = app(OrganizationApi::class)->projectAssignees($actor, $branch);

        $history = DB::table('maintenance_activities')->where('entity_type', 'maintenance_work_order')->where('entity_id', $w->id)->orderByDesc('id')->get();
        $completions = DB::table('maintenance_completions')->where('work_order_id', $w->id)->orderByDesc('revision')->get();
        $people = app(OrganizationApi::class)->projectParticipantNames($actor, $branch, $assignments->pluck('technician_id')->merge($visits->pluck('technician_id'))->merge($laborEntries->pluck('technician_id'))->merge($history->pluck('actor_id'))->merge($completions->pluck('verified_by'))->push($w->responsible_id)->unique()->all());
        $readiness = app(MaintenanceExecution::class)->completionReadiness($actor, $w->id);
        $completionReady = collect($readiness)->every(fn ($check) => $check['ready'] === true);
        $commercialApproval = DB::table('maintenance_commercial_approvals')->where('work_order_id', $w->id)->first();
        $nextStep = match ($w->status) {
            'draft', 'scheduled' => $assignments->where('active', true)->isEmpty()
                ? ['tab' => 'assignments', 'label' => 'تكليف فني', 'description' => 'حدد الفني وفترة تواجده، ثم جدول الزيارة وقائمة الفحص.']
                : ['tab' => 'visits', 'label' => 'جدولة الزيارة', 'description' => 'اختر التكليف النشط وموعد الزيارة وقالب الفحص.'],
            'assigned' => ['tab' => 'visits', 'action' => $can['workorder_manage'] ? 'start' : null, 'label' => $can['workorder_manage'] ? 'بدء التنفيذ' : 'فتح تنفيذ الزيارة', 'description' => 'الفني المكلف يسجل تقرير الزيارة والوقت الفعلي من مساحة التنفيذ.'],
            'in_progress' => ['tab' => 'completion', 'action' => $completionReady && $can['workorder_manage'] ? 'complete' : null, 'label' => $completionReady && $can['workorder_manage'] ? 'تأكيد إتمام العمل' : 'مراجعة نواقص الإتمام', 'description' => 'راجع الزيارات والفحص والوقت وإقرار العميل وتسوية قطع الغيار.'],
            'work_completed' => ['tab' => 'completion', 'action' => $can['verify'] && $completionReady ? 'verify' : null, 'label' => $can['verify'] ? 'اعتماد التحقق الفني' : 'متابعة التحقق الفني', 'description' => 'التحقق الفني يصدر تقرير الخدمة ويحتفظ بأدلة التنفيذ.'],
            'verified' => $w->billable && ! $invoice
                ? ['tab' => 'billing', 'label' => $commercialApproval ? 'إحالة فاتورة الخدمة' : 'مراجعة سعر الخدمة', 'description' => 'الخدمة المدفوعة تحتاج سعرًا معتمدًا بصورة مستقلة وإحالة فاتورة قبل الإغلاق.']
                : ['tab' => 'completion', 'action' => $can['close'] && $completionReady ? 'close' : null, 'label' => $can['close'] ? 'إغلاق أمر العمل' : 'متابعة إغلاق الأمر', 'description' => 'الإغلاق يحتفظ بالتاريخ ولا يعني تحصيل الفاتورة.'],
            'on_hold' => ['tab' => 'completion', 'action' => $can['workorder_manage'] ? 'resume' : null, 'label' => 'استئناف التنفيذ', 'description' => 'سجل سبب الاستئناف للعودة إلى المرحلة السابقة.'],
            default => ['tab' => 'history', 'label' => 'عرض سجل الأمر', 'description' => 'السجل والأدلة محفوظة. التصحيح يحتاج إعادة فتح مصرحًا بها.'],
        };
        if ($w->status === 'draft' && trim($w->instructions ?? '') === '') {
            $nextStep = ['tab' => 'details', 'label' => 'إعداد تعليمات التنفيذ', 'description' => 'وضح العمل المطلوب وقابلية الفوترة ومتطلبات إقرار العميل قبل التكليف.'];
        }
        $productNames = [];
        foreach ($requirements->pluck('product_id')->push($w->product_id)->filter()->unique() as $id) {
            try {
                $productNames[$id] = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $id))->product['name'];
            } catch (HttpException|ContractException) {
            }
        }
        $warehouseNames = [];
        if ($requirements->isNotEmpty()) {
            try {
                $warehouseNames = collect(app(InventoryApi::class)->warehouseDirectory($actor))->pluck('name', 'id')->all();
            } catch (HttpException|ContractException) {
            }
        }
        $proposals = collect();
        if ((in_array($this->tab, ['labor', 'costs']) && $can['rate_approve']) || ($this->tab === 'billing' && $can['billing_approve'])) {
            $proposals = DB::table('maintenance_cost_proposals')->where('branch_id', $branch)->where('kind', $this->tab === 'billing' ? 'service' : 'labor_rate')->when($this->tab === 'billing', fn ($q) => $q->where('work_order_id', $w->id))->orderByDesc('id')->limit(30)->get(['id', 'kind', 'created_by', 'created_at']);
        }

        return view('maintenance.work-order', compact('w', 'can', 'assignments', 'visits', 'requirements', 'reservations', 'issues', 'laborEntries', 'products', 'warehouses', 'invoice', 'invoiceUnavailable', 'users', 'partAvailability', 'people', 'readiness', 'completionReady', 'nextStep', 'commercialApproval', 'productNames', 'warehouseNames', 'completions', 'history', 'proposals') + ['technicians' => $can['assign'] ? $access->technicians($actor, $branch) : [], 'templates' => DB::table('maintenance_checklist_templates')->where('branch_id', $branch)->where('active', true)->get(), 'costs' => $can['cost_view'] ? app(MaintenanceBilling::class)->costs($actor, $w->id) : null, 'documents' => DB::table('maintenance_documents')->where('work_order_id', $w->id)->get()]);
    }
}

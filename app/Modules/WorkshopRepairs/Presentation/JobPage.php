<?php

namespace App\Modules\WorkshopRepairs\Presentation;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\WorkshopRepairs\Application\RepairOffers;
use App\Modules\WorkshopRepairs\Application\WorkshopAccess;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use App\Modules\WorkshopRepairs\Application\WorkshopCustody;
use App\Modules\WorkshopRepairs\Application\WorkshopEstimates;
use App\Modules\WorkshopRepairs\Application\WorkshopExecution;
use App\Modules\WorkshopRepairs\Application\WorkshopFinance;
use App\Modules\WorkshopRepairs\Application\WorkshopParts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class JobPage extends Component
{
    #[Locked]
    public int $jobId;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $recordKey;

    #[Locked]
    public string $reserveKey;

    #[Locked]
    public string $issueKey;

    #[Locked]
    public string $returnKey;

    public string $partSerials = '';

    #[Locked]
    public string $invoiceKey;

    #[Locked]
    public string $deliveryKey;

    #[Url]
    public string $tab = 'details';

    public string $reason = '';

    public string $workshopId = '';

    public string $destinationWorkshopId = '';

    public string $technicianId = '';

    public string $estimateId = '';

    public string $approvalEvidence = '';

    public string $nonbillableKind = 'goodwill';

    public string $warrantySource = '';

    public string $transferId = '';

    public string $transferCondition = '';

    public string $alternativeProduct = '';

    #[Locked]
    public string $transferKey;

    public bool $accepted = true;

    public string $productSearch = '';

    public string $requirementId = '';

    public string $partOperation = 'reserve';

    public string $reservationId = '';

    public string $issueId = '';

    public string $recordId = '';

    public string $quantity = '';

    public string $finalCondition = '';

    public string $remainingFaults = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    #[Locked]
    public string $collectionKey;

    #[Locked]
    public ?int $collectionMovementId = null;

    public array $collection = ['account_id' => '', 'amount' => '', 'date' => '', 'reference' => ''];

    public string $expenseJournal = '';

    public string $laborEntryId = '';

    public string $rateId = '';

    public array $diagnosis = ['fault_classification' => '', 'diagnosis' => '', 'planned_work' => '', 'estimated_minutes' => '', 'estimated_completion' => '', 'required_tests' => [['name' => '', 'required' => true]]];

    public array $estimate = ['product_id' => '', 'date' => '', 'valid_until' => '', 'warranty_days' => '0', 'warranty_conditions' => '', 'parts_amount' => '', 'labor_amount' => '', 'other_amount' => '', 'parts_selling' => '', 'labor_selling' => '', 'other_selling' => '', 'discount_amount' => '0.00', 'billable_amount' => '', 'pricing_reference' => ''];

    public array $record = ['started_at' => '', 'ended_at' => '', 'work_performed' => '', 'observations' => '', 'work_type' => 'repair'];

    public array $test = ['name' => '', 'outcome' => 'passed', 'results' => ''];

    public array $part = ['product_id' => '', 'warehouse_id' => '', 'quantity' => ''];

    public array $customerPart = ['source_id' => '', 'description' => '', 'quantity' => '', 'condition' => '', 'disposition' => 'received', 'reference' => ''];

    public function saveCustomerPart(): void
    {
        $input = $this->customerPart;
        $input['source_id'] = $input['source_id'] ?: null;
        app(WorkshopParts::class)->customerPart(auth()->id(), $this->jobId, $this->version, $input);
        $this->refreshVersion();
    }

    public array $delivery = ['recipient' => '', 'accessories' => '', 'final_condition' => '', 'acknowledgment' => '', 'delivered_at' => '', 'unrepaired' => false];

    public array $rate = ['technician_id' => '', 'hourly_cost' => '', 'effective_from' => '', 'effective_to' => '', 'reference' => ''];

    public function mount(int $job): void
    {
        $this->jobId = $job;
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize(auth()->id(), $w->branch_id);
        $this->version = $w->version;
        $this->transferId = (string) ($w->pending_transfer_id ?? '');
        $this->workshopId = (string) $w->workshop_id;
        $this->technicianId = (string) ($w->technician_id ?? '');
        $this->estimateId = (string) (DB::table('workshop_estimates')->where('job_id', $job)->orderByDesc('revision')->value('id') ?? '');
        $this->nonbillableKind = $w->billing_mode === 'warranty' ? 'warranty' : ($w->customer_id ? 'goodwill' : 'internal');
        foreach (array_keys($this->diagnosis) as $key) {
            $this->diagnosis[$key] = $key === 'required_tests' ? (json_decode($w->required_tests, true) ?: [['name' => '', 'required' => true]]) : ($w->$key ?? '');
        }
        if ($w->estimated_completion) {
            $this->diagnosis['estimated_completion'] = CarbonImmutable::parse($w->estimated_completion)->setTimezone('Africa/Cairo')->format('Y-m-d\TH:i');
        }
        foreach (['recordKey', 'reserveKey', 'issueKey', 'returnKey', 'invoiceKey', 'deliveryKey', 'transferKey', 'collectionKey'] as $key) {
            $this->$key = (string) Str::uuid();
        }
    }

    private function refreshVersion(): void
    {
        $this->version = DB::table('workshop_jobs')->where('id', $this->jobId)->value('version');
        session()->flash('workshop_notice', 'تم حفظ العملية.');
    }

    private function local(string $time): string
    {
        abort_unless(trim($time) !== '', 422, 'أدخل التاريخ والوقت.');

        return CarbonImmutable::parse($time, 'Africa/Cairo')->toIso8601String();
    }

    public function addTest(): void
    {
        $this->diagnosis['required_tests'][] = ['name' => '', 'required' => true];
    }

    public function assign(): void
    {
        app(WorkshopActions::class)->assign(auth()->id(), $this->jobId, $this->version, (int) $this->workshopId, (int) $this->technicianId, $this->reason);
        $this->refreshVersion();
    }

    public function diagnose(): void
    {
        $d = $this->diagnosis;
        $d['estimated_minutes'] = $d['estimated_minutes'] ?: null;
        $d['estimated_completion'] = $d['estimated_completion'] ? $this->local($d['estimated_completion']) : null;
        app(WorkshopActions::class)->diagnose(auth()->id(), $this->jobId, $this->version, $d);
        $this->refreshVersion();
    }

    public function proposeEstimate(): void
    {
        $this->estimate['billable_amount'] = $this->sellingNet() ?? '';
        $this->estimateId = (string) app(WorkshopEstimates::class)->propose(auth()->id(), $this->jobId, $this->version, $this->estimate);
        $this->refreshVersion();
    }

    private function sellingNet(): ?string
    {
        foreach (['parts_selling', 'labor_selling', 'other_selling', 'discount_amount'] as $key) {
            if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/D', (string) $this->estimate[$key])) {
                return null;
            }
        }

        return bcsub(bcadd(bcadd($this->estimate['parts_selling'], $this->estimate['labor_selling'], 2), $this->estimate['other_selling'], 2), $this->estimate['discount_amount'], 2);
    }

    public function decideEstimate(): void
    {
        app(RepairOffers::class)->approve(auth()->id(), $this->jobId, $this->version, (int) $this->estimateId, $this->approvalEvidence);
        $this->refreshVersion();
    }

    public function issueQuote(): void
    {
        app(RepairOffers::class)->issue(auth()->id(), $this->jobId, $this->version, (int) $this->estimateId);
        $this->refreshVersion();
    }

    public function acceptQuote(): void
    {
        app(RepairOffers::class)->accept(auth()->id(), $this->jobId, $this->version, (int) $this->estimateId, $this->accepted, $this->approvalEvidence);
        $this->refreshVersion();
    }

    public function authorizeNonbillable(): void
    {
        app(RepairOffers::class)->nonbillable(auth()->id(), $this->jobId, $this->version, $this->nonbillableKind, $this->warrantySource ? (int) $this->warrantySource : null, $this->approvalEvidence);
        $this->refreshVersion();
    }

    public function authorizeContract(): void
    {
        app(RepairOffers::class)->contract(auth()->id(), $this->jobId, $this->version, $this->approvalEvidence);
        $this->refreshVersion();
    }

    public function updatedRequirementId(): void
    {
        $this->reset('reservationId', 'issueId', 'quantity', 'partSerials');
    }

    public function selectRequirement(int $requirement): void
    {
        $w = DB::table('workshop_jobs')->find($this->jobId);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize(auth()->id(), $w->branch_id);
        abort_unless(DB::table('workshop_part_requirements')->where('job_id', $w->id)->where('id', $requirement)->exists(), 404);
        $this->requirementId = (string) $requirement;
        $this->updatedRequirementId();
    }

    public function dispatchTransfer(): void
    {
        $this->transferId = (string) app(WorkshopCustody::class)->dispatch(auth()->id(), $this->jobId, $this->version, (int) $this->destinationWorkshopId, $this->transferKey, $this->transferCondition, $this->reason);
        $this->transferKey = (string) Str::uuid();
        $this->refreshVersion();
    }

    public function receiveTransfer(): void
    {
        app(WorkshopCustody::class)->receive(auth()->id(), $this->jobId, $this->version, (int) $this->transferId, $this->transferKey, $this->transferCondition);
        $this->transferKey = (string) Str::uuid();
        $this->refreshVersion();
    }

    public function state(string $action): void
    {
        app(WorkshopExecution::class)->state(auth()->id(), $this->jobId, $this->version, $action, $this->reason);
        $this->refreshVersion();
    }

    public function recordWork(): void
    {
        $d = $this->record;
        $d['started_at'] = $this->local($d['started_at']);
        $d['ended_at'] = $this->local($d['ended_at']);
        $this->recordId = (string) app(WorkshopExecution::class)->record(auth()->id(), $this->jobId, $this->version, $this->recordKey, $d);
        $this->recordKey = (string) Str::uuid();
        $this->refreshVersion();
    }

    public function recordTest(): void
    {
        app(WorkshopExecution::class)->test(auth()->id(), $this->jobId, $this->version, $this->test);
        $this->refreshVersion();
    }

    public function verify(): void
    {
        app(WorkshopExecution::class)->verify(auth()->id(), $this->jobId, $this->version, $this->finalCondition, $this->remainingFaults);
        $this->refreshVersion();
    }

    public function unrepairable(): void
    {
        app(WorkshopExecution::class)->unrepairable(auth()->id(), $this->jobId, $this->version, $this->reason);
        $this->refreshVersion();
    }

    public function deliver(): void
    {
        $d = $this->delivery;
        $d['delivered_at'] = $this->local($d['delivered_at']);
        app(WorkshopExecution::class)->deliver(auth()->id(), $this->jobId, $this->version, $this->deliveryKey, $d);
        $this->refreshVersion();
    }

    public function requestPart(): void
    {
        $this->requirementId = (string) app(WorkshopParts::class)->requirement(auth()->id(), $this->jobId, $this->version, $this->part);
        $this->refreshVersion();
    }

    public function approveAlternative(): void
    {
        $this->requirementId = (string) app(WorkshopParts::class)->alternative(auth()->id(), $this->jobId, $this->version, (int) $this->requirementId, (int) $this->alternativeProduct, $this->reason);
        $this->refreshVersion();
    }

    public function reservePart(): void
    {
        $r = app(WorkshopParts::class)->reserve(auth()->id(), $this->jobId, (int) $this->requirementId, $this->quantity, $this->reserveKey);
        $this->reservationId = (string) $r['id'];
        $this->reserveKey = (string) Str::uuid();
        $this->refreshVersion();
    }

    public function issuePart(): void
    {
        app(WorkshopParts::class)->issue(auth()->id(), $this->jobId, (int) $this->reservationId, $this->quantity, $this->issueKey);
        $this->issueKey = (string) Str::uuid();
        $this->refreshVersion();
    }

    public function releasePart(): void
    {
        app(WorkshopParts::class)->release(auth()->id(), $this->jobId, (int) $this->reservationId, (string) Str::uuid());
        $this->refreshVersion();
    }

    public function returnPart(): void
    {
        app(WorkshopParts::class)->returnUnused(auth()->id(), $this->jobId, (int) $this->issueId, $this->quantity, $this->returnKey, $this->reason, preg_split('/\R/u', $this->partSerials, -1, PREG_SPLIT_NO_EMPTY));
        $this->returnKey = (string) Str::uuid();
        $this->refreshVersion();
    }

    public function usePart(): void
    {
        app(WorkshopParts::class)->usage(auth()->id(), $this->jobId, (int) $this->recordId, (int) $this->requirementId, $this->quantity, preg_split('/\R/u', $this->partSerials, -1, PREG_SPLIT_NO_EMPTY));
        $this->refreshVersion();
    }

    public function requestInvoice(): void
    {
        app(WorkshopFinance::class)->requestInvoice(auth()->id(), $this->jobId, $this->invoiceKey, $this->invoiceDate, $this->dueDate);
        $this->refreshVersion();
    }

    public function invoiceStep(int $version, string $step): void
    {
        $invoice = app(WorkshopFinance::class)->invoice(auth()->id(), $this->jobId);
        abort_unless($invoice, 409, 'أحل الإصلاح للفوترة أولاً.');
        $api = app(ReceivablesPayablesApi::class);
        match ($step) {
            'validate' => $api->validateCustomerInvoice(auth()->id(), $invoice['id'], $version),
            'post' => $api->postCustomerInvoice(auth()->id(), $invoice['id'], $version),
            default => abort(404),
        };
        session()->flash('workshop_notice', 'تم تحديث الفاتورة من الإجراء المالي المعتمد.');
    }

    public function collectInvoice(): void
    {
        $w = DB::table('workshop_jobs')->find($this->jobId);
        abort_unless($w, 404);
        $invoice = app(WorkshopFinance::class)->invoice(auth()->id(), $w->id);
        abort_unless($invoice && $invoice['balance_available'] && ! empty($invoice['open_item_id']), 409, 'التحصيل يحتاج فاتورة مرحلة وذمة متاحة.');
        $result = app(TreasuryApi::class)->executeCustomerCollection(auth()->id(), [
            'idempotency_key' => $this->collectionKey, 'branch_id' => $w->branch_id, 'customer_id' => $w->customer_id,
            'account_id' => $this->collection['account_id'], 'date' => $this->collection['date'],
            'amount' => $this->collection['amount'], 'reference' => $this->collection['reference'], 'allow_advance' => false,
        ], [['open_item_id' => $invoice['open_item_id'], 'amount' => $this->collection['amount']]]);
        $this->collectionMovementId = $result['movement_id'];
        $this->collectionKey = (string) Str::uuid();
        $this->reset('collection');
        session()->flash('workshop_notice', 'تم تحصيل المبلغ وتخصيصه على فاتورة الإصلاح وحفظ سند القبض.');
    }

    public function recognizeLabor(): void
    {
        app(WorkshopFinance::class)->recognizeLabor(auth()->id(), $this->jobId, (int) $this->laborEntryId);
        $this->refreshVersion();
    }

    public function linkExpense(): void
    {
        app(WorkshopFinance::class)->linkExpense(auth()->id(), $this->jobId, (int) $this->expenseJournal, $this->reason);
        $this->refreshVersion();
    }

    public function proposeRate(): void
    {
        $w = DB::table('workshop_jobs')->find($this->jobId);
        $this->rateId = (string) app(WorkshopEstimates::class)->proposeRate(auth()->id(), $this->rate + ['branch_id' => $w->branch_id]);
        $this->refreshVersion();
    }

    public function approveRate(): void
    {
        app(WorkshopEstimates::class)->approveRate(auth()->id(), (int) $this->rateId);
        $this->refreshVersion();
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
        $w = DB::table('workshop_jobs')->find($this->jobId);
        abort_unless($w, 404);
        $access->authorize($actor, $w->branch_id);
        abort_unless(in_array($this->tab, ['details', 'estimate', 'work', 'parts', 'costs', 'testing', 'delivery', 'billing', 'history']), 404);
        if ($this->tab === 'costs') {
            $access->authorize($actor, $w->branch_id, 'cost_view');
        }
        $requirements = DB::table('workshop_part_requirements')->where('job_id', $w->id)->get();
        $ids = $requirements->pluck('id');
        $stockControls = ['reserve' => false, 'issue' => false, 'return' => false];
        if ($this->tab === 'parts') {
            $selectedRequirement = $requirements->firstWhere('id', (int) $this->requirementId);
            foreach ($selectedRequirement ? [$selectedRequirement->warehouse_id] : [] as $warehouse) {
                foreach (['reserve' => 'reservation_manage', 'issue' => 'issue_post', 'return' => 'correction_execute'] as $control => $permission) {
                    try {
                        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M06.'.$permission, $w->branch_id, $warehouse));
                        if ($control !== 'reserve') {
                            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.journal_post', $w->branch_id));
                        }
                        $stockControls[$control] = true;
                    } catch (ContractException) {
                        // Workshop permissions do not grant stock or journal authority.
                    }
                }
            }
        }
        $canCosts = $access->allows($actor, $w->branch_id, 'cost_view');
        $canBillingHandoff = $access->billingHandoff($actor, $w->branch_id);
        $needsCatalog = ($this->tab === 'estimate' && $canCosts && $access->allows($actor, $w->branch_id, 'estimate_manage'))
            || ($this->tab === 'parts' && ($access->allows($actor, $w->branch_id, 'parts_request') || $access->allows($actor, $w->branch_id, 'parts_alternative_approve')));
        $catalogAvailable = false;
        if ($needsCatalog) {
            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M02.product_view'));
                $catalogAvailable = true;
            } catch (ContractException) {
                // A technician can read and consume the assigned job's recorded parts
                // without receiving global catalog or warehouse authority.
            }
        }
        $products = $catalogAvailable ? app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'products', mb_substr($this->productSearch, 0, 200), true, 1, 100))->items : [];
        if ($catalogAvailable) {
            foreach (array_unique(array_filter([$this->part['product_id'], $this->estimate['product_id'], $this->alternativeProduct])) as $id) {
                if (! collect($products)->contains('id', (int) $id)) {
                    $products[] = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $id))->product;
                }
            }
        }
        $estimates = $access->allows($actor, $w->branch_id, 'quote_issue') || $access->allows($actor, $w->branch_id, 'customer_acceptance') || $canCosts || $access->allows($actor, $w->branch_id, 'estimate_manage') || $access->allows($actor, $w->branch_id, 'estimate_approve') ? DB::table('workshop_estimates')->where('job_id', $w->id)->get() : collect();

        $users = app(OrganizationApi::class)->projectAssignees($actor, $w->branch_id);
        $memberIds = DB::table('workshop_members')->where('workshop_id', $w->workshop_id)->where('active', true)->pluck('technician_id');
        $technicians = array_values(array_filter($users, fn ($user) => $memberIds->contains($user['id']) && $access->allows((int) $user['id'], $w->branch_id, 'execute')));
        $partLabels = [];
        $collectionAccounts = [];
        $canValidateInvoice = false;
        $canPostInvoice = false;
        if (in_array($this->tab, ['delivery', 'billing'])) {
            foreach (['canValidateInvoice' => 'M12.customer_invoice_manage', 'canPostInvoice' => 'M12.customer_invoice_post'] as $flag => $permission) {
                try {
                    app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $permission, $w->branch_id));
                    $$flag = true;
                } catch (ContractException) {
                }
            }
            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M12.ar_view', $w->branch_id));
                $collectionAccounts = app(TreasuryApi::class)->collectionAccounts($actor, $w->branch_id);
            } catch (ContractException $e) {
                // Keep operational delivery available when financial authority is absent.
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
            }
        }
        if ($this->tab === 'parts') {
            foreach ($requirements->pluck('product_id')->unique() as $productId) {
                try {
                    $product = app(ProductsApi::class)->inventoryProduct($actor, (int) $productId);
                    $partLabels[$productId] = $product['sku'].' · '.$product['name'];
                } catch (ContractException) {
                    // Recorded source references remain readable without granting global catalog access.
                }
            }
        }

        return view('workshops.job', compact('w', 'access', 'requirements', 'estimates', 'canCosts', 'canBillingHandoff', 'catalogAvailable', 'stockControls', 'users', 'technicians', 'partLabels', 'collectionAccounts', 'canValidateInvoice', 'canPostInvoice') + ['offerNet' => $this->sellingNet(), 'quotes' => DB::table('workshop_quotes')->where('job_id', $w->id)->get(), 'quoteApprovals' => DB::table('workshop_quote_approvals')->whereIn('estimate_id', $estimates->pluck('id'))->get(), 'deliveries' => DB::table('workshop_deliveries')->where('job_id', $w->id)->get(), 'availability' => $this->tab === 'parts' && ! empty($this->part['product_id']) && ! empty($this->part['warehouse_id']) ? app(InventoryApi::class)->getAvailability($actor, (int) $this->part['warehouse_id'], (int) $this->part['product_id']) : null, 'intake' => DB::table('workshop_intakes')->find($w->intake_id), 'workshops' => $access->query($actor, 'workshops')->where('branch_id', $w->branch_id)->where('active', true)->get(), 'products' => $products, 'warehouses' => $this->tab === 'parts' ? app(InventoryApi::class)->reservationWarehouses($actor, $w->branch_id) : [], 'records' => DB::table('workshop_work_records')->where('job_id', $w->id)->get(), 'laborEntries' => $canCosts ? DB::table('workshop_labor_entries')->where('job_id', $w->id)->get() : collect(), 'customerParts' => DB::table('workshop_customer_parts')->where('job_id', $w->id)->get(), 'rates' => $canCosts ? DB::table('workshop_rates')->where('branch_id', $w->branch_id)->get() : collect(), 'tests' => DB::table('workshop_tests')->where('job_id', $w->id)->get(), 'reservations' => DB::table('workshop_part_reservations')->whereIn('requirement_id', $ids)->get(), 'issues' => DB::table('workshop_part_issues')->whereIn('requirement_id', $ids)->get($canCosts ? ['*'] : ['id', 'requirement_id', 'reservation_id', 'quantity', 'movement_id', 'serials']), 'costs' => $this->tab === 'costs' ? app(WorkshopFinance::class)->costs($actor, $w->id) : null, 'invoice' => in_array($w->status, ['verified', 'delivered']) && $canBillingHandoff ? app(WorkshopFinance::class)->invoice($actor, $w->id) : null, 'history' => DB::table('workshop_activities')->where('job_id', $w->id)->orderBy('id')->get(), 'verifications' => DB::table('workshop_verifications')->where('job_id', $w->id)->get()]);
    }
}

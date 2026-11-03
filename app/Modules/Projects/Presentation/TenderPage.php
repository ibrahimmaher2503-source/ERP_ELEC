<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Projects\Application\TenderAccess;
use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Application\TenderAwards;
use App\Modules\Projects\Application\TenderBids;
use App\Modules\Projects\Application\TenderBoq;
use App\Modules\Projects\Application\TenderDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TenderPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $tenderId = null;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public string $creationKey;

    #[Locked]
    public string $uploadKey;

    #[Locked]
    public string $submissionKey;

    #[Url]
    public string $tab = 'details';

    public array $form = ['external_reference' => '', 'title' => '', 'description' => '', 'issuer' => '', 'customer_id' => '', 'category' => 'supply', 'branch_id' => '', 'responsible_id' => '', 'published_on' => '', 'requirements' => '', 'deadlines' => []];

    public string $customerSearch = '';

    public array $technical = ['requirements' => '', 'documents' => [], 'lines' => []];

    public array $financial = ['date' => '', 'mode' => 'exclusive', 'terms' => '', 'reference' => '', 'labor' => '0', 'transport' => '0', 'other' => '0', 'contingency' => '0', 'discount' => '0', 'lines' => []];

    public array $submission = ['method' => 'physical', 'reference' => '', 'submitted_at' => '', 'evidence_id' => ''];

    public array $result = ['outcome' => 'awarded', 'decided_on' => '', 'reference' => '', 'evidence_id' => '', 'award_amount' => ''];

    public array $guarantee = ['reference' => '', 'kind' => 'bid_bond', 'guarantee_type' => '', 'bank' => '', 'amount' => '', 'issued_on' => '', 'expires_on' => '', 'evidence_id' => ''];

    public string $guaranteeId = '';

    public string $guaranteeStatus = 'returned';

    public string $planId = '';

    public string $planReference = '';

    public bool $toProject = true;

    public bool $toQuotation = false;

    public array $project = ['code' => '', 'name' => '', 'manager_id' => '', 'start_date' => '', 'end_date' => '', 'members' => []];

    public array $sales = ['date' => '', 'valid_until' => '', 'reference' => '', 'lines' => []];

    public string $state = 'under_review';

    public string $reason = '';

    public string $eventKind = 'clarification';

    public string $eventDocument = '';

    public string $uploadTitle = '';

    public string $uploadClassification = 'general';

    public $file;

    public $boq;

    public function mount(?int $tender = null): void
    {
        $this->creationKey = (string) Str::uuid();
        $this->uploadKey = (string) Str::uuid();
        $this->submissionKey = (string) Str::uuid();
        $today = now('Africa/Cairo')->toDateString();
        $this->form['published_on'] = $today;
        $this->financial['date'] = $today;
        $this->result['decided_on'] = $today;
        $this->guarantee['issued_on'] = $today;
        $this->sales['date'] = $today;
        $this->form['deadlines'] = array_fill_keys(TenderActions::DATES, '');
        $this->tenderId = $tender;
        if ($tender) {
            $t = $this->row();
            $this->version = $t->version;
            foreach (array_keys($this->form) as $key) {
                $this->form[$key] = $t->$key ?? '';
            }$this->form['deadlines'] = json_decode($t->deadlines, true);
            foreach (TenderActions::DATES as $key) {
                $v = $this->form['deadlines'][$key];
                $this->form['deadlines'][$key] = $v ? CarbonImmutable::parse($v)->tz('Africa/Cairo')->format('Y-m-d\TH:i') : '';
            }
        } else {
            $branches = app(TenderAccess::class)->branches(auth()->id(), 'manage');
            abort_unless($branches, 403);
            $this->form['branch_id'] = (string) $branches[0]['id'];
        }
        $this->technical['lines'] = [$this->line()];
    }

    private function row(): object
    {
        $t = DB::table('project_tenders')->find($this->tenderId);
        abort_unless($t, 404);
        app(TenderAccess::class)->authorize(auth()->id(), $t->branch_id);

        return $t;
    }

    private function reload(): void
    {
        $this->version = $this->row()->version;
    }

    private function local(string $value): string
    {
        return $value === '' ? '' : CarbonImmutable::parse($value, 'Africa/Cairo')->toIso8601String();
    }

    private function line(): array
    {
        return ['description' => '', 'quantity' => '1', 'unit' => '', 'specification' => '', 'compliance' => 'compliant', 'deviation' => '', 'alternative' => '', 'product_id' => '', 'unit_id' => '', 'catalog_reference' => ''];
    }

    public function addLine(): void
    {
        $this->technical['lines'][] = $this->line();
    }

    public function removeLine(int $index): void
    {
        unset($this->technical['lines'][$index]);
        $this->technical['lines'] = array_values($this->technical['lines']);
    }

    public function save(): void
    {
        $d = $this->form;
        foreach (TenderActions::DATES as $key) {
            $d['deadlines'][$key] = $this->local($d['deadlines'][$key] ?? '');
        }
        $id = app(TenderActions::class)->save(auth()->id(), $this->tenderId, $this->version, $this->creationKey, $d);
        $this->redirectRoute('tenders.show', ['tender' => $id], navigate: true);
    }

    public function advanceState(): void
    {
        app(TenderActions::class)->transition(auth()->id(), $this->tenderId, $this->version, $this->state, $this->reason);
        $this->reload();
    }

    public function saveEvent(): void
    {
        $dates = $this->form['deadlines'];
        foreach ($dates as &$v) {
            $v = $this->local($v);
        }app(TenderActions::class)->event(auth()->id(), $this->tenderId, $this->version, $this->eventKind, $this->reason, $this->eventDocument ? (int) $this->eventDocument : null, $dates);
        $this->reload();
    }

    public function saveAttachment(): void
    {
        app(TenderDocuments::class)->upload(auth()->id(), $this->tenderId, $this->version, $this->uploadKey, $this->uploadTitle, $this->uploadClassification, $this->file);
        $this->uploadKey = (string) Str::uuid();
        $this->reset('file');
        $this->reload();
    }

    public function loadTechnical(): void
    {
        $t = $this->row();
        app(TenderAccess::class)->authorize(auth()->id(), $t->branch_id, 'technical_manage');
        if ($t->technical_id) {
            $this->technical = json_decode(DB::table('project_tender_technical')->find($t->technical_id)->snapshot, true);
            $this->technical['documents'] = array_column($this->technical['documents'], 'id');
        }
    }

    public function saveTechnical(): void
    {
        app(TenderBids::class)->technical(auth()->id(), $this->tenderId, $this->version, $this->technical);
        $this->reload();
    }

    public function prepareFinancial(): void
    {
        $t = $this->row();
        app(TenderAccess::class)->authorize(auth()->id(), $t->branch_id, 'financial_manage');
        app(TenderAccess::class)->authorize(auth()->id(), $t->branch_id, 'financial_view');
        abort_unless($t->technical_id, 409);
        $snapshot = json_decode(DB::table('project_tender_technical')->find($t->technical_id)->snapshot, true);
        $this->financial['lines'] = array_map(fn ($r) => ['unit_cost' => '0', 'selling_price' => '0', 'cost_reference' => '', 'price_reference' => ''], $snapshot['lines']);
        if ($t->financial_id) {
            $s = json_decode(DB::table('project_tender_financial')->find($t->financial_id)->snapshot, true);
            $this->financial = array_intersect_key($s, $this->financial);
        }
    }

    public function saveFinancial(): void
    {
        app(TenderBids::class)->financial(auth()->id(), $this->tenderId, $this->version, $this->financial);
        $this->reload();
    }

    public function approveTechnical(): void
    {
        app(TenderBids::class)->approve(auth()->id(), $this->tenderId, $this->version, 'technical', $this->reason);
        $this->reload();
    }

    public function approveFinancial(): void
    {
        app(TenderBids::class)->approve(auth()->id(), $this->tenderId, $this->version, 'financial', $this->reason);
        $this->reload();
    }

    public function issue(): void
    {
        app(TenderBids::class)->issue(auth()->id(), $this->tenderId, $this->version);
        $this->reload();
    }

    public function submitBid(): void
    {
        $d = $this->submission;
        $d['submitted_at'] = $this->local($d['submitted_at']);
        app(TenderBids::class)->submit(auth()->id(), $this->tenderId, $this->version, $this->submissionKey, $d);
        $this->reload();
    }

    public function saveResult(): void
    {
        app(TenderAwards::class)->result(auth()->id(), $this->tenderId, $this->version, $this->result);
        $this->reload();
    }

    public function saveGuarantee(): void
    {
        app(TenderAwards::class)->guarantee(auth()->id(), $this->tenderId, $this->version, $this->guarantee);
        $this->reload();
    }

    public function settleGuarantee(): void
    {
        app(TenderAwards::class)->settleGuarantee(auth()->id(), $this->tenderId, $this->version, (int) $this->guaranteeId, $this->guaranteeStatus, $this->reason, (int) $this->eventDocument);
        $this->reload();
    }

    public function savePlan(): void
    {
        $t = $this->row();
        $dest = [];
        if ($this->toProject) {
            $dest['project'] = $this->project;
        }if ($this->toQuotation) {
            $dest['quotation'] = $this->sales;
        }$r = DB::table('project_tender_results')->where('tender_id', $t->id)->orderByDesc('id')->first();
        abort_unless($r, 409);
        $this->planId = (string) app(TenderAwards::class)->plan(auth()->id(), $t->id, $this->version, ['result_id' => $r->id, 'reference' => $this->planReference, 'destinations' => $dest]);
        $this->reload();
    }

    public function approvePlan(): void
    {
        app(TenderAwards::class)->approve(auth()->id(), $this->tenderId, $this->version, (int) $this->planId, $this->reason);
        $this->reload();
    }

    public function convert(): void
    {
        app(TenderAwards::class)->convert(auth()->id(), $this->tenderId, $this->version, (int) $this->planId);
        $this->reload();
    }

    public function prepareSales(): void
    {
        $t = $this->row();
        app(TenderAccess::class)->authorize(auth()->id(), $t->branch_id, 'handoff');
        app(TenderAccess::class)->authorize(auth()->id(), $t->branch_id, 'financial_view');
        abort_unless($t->financial_id, 409);
        $s = json_decode(DB::table('project_tender_financial')->find($t->financial_id)->snapshot, true);
        $this->sales['lines'] = array_map(fn ($r) => ['product_id' => $r['product_id'] ?? '', 'unit_id' => $r['unit_id'] ?? ''], $s['lines']);
    }

    public function template()
    {
        return response()->download(app(TenderBoq::class)->template(auth()->id(), $this->tenderId), 'tender-boq-template.xlsx', ['Cache-Control' => 'private, no-store'])->setPrivate()->deleteFileAfterSend();
    }

    public function importBoq(): void
    {
        $this->technical['lines'] = app(TenderBoq::class)->preview(auth()->id(), $this->tenderId, $this->boq);
    }

    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof HttpException && in_array($e->getStatusCode(), [409, 422])) {
            $this->addError('operation', $e->getMessage() ?: 'العملية غير صالحة للحالة الحالية.');
            $stopPropagation();
        }
    }

    public function render()
    {
        $actor = auth()->id();
        $a = app(TenderAccess::class);
        $t = $this->tenderId ? $this->row() : null;
        $branch = $t?->branch_id ?: (int) $this->form['branch_id'];
        abort_unless(in_array($this->tab, ['details', 'technical', 'financial', 'approvals', 'submission', 'award', 'guarantees', 'documents', 'history']), 404);
        if (in_array($this->tab, ['financial', 'submission', 'award', 'guarantees'])) {
            $a->authorize($actor, $branch, 'financial_view');
        }
        $canMoney = $branch && $a->allows($actor, $branch, 'financial_view');
        $documents = $t ? DB::table('project_tender_documents')->where('tender_id', $t->id)->when(! $canMoney, fn ($q) => $q->whereNotIn('classification', TenderDocuments::FINANCIAL))->get() : collect();
        $technicalRevision = $t?->technical_id ? DB::table('project_tender_technical')->find($t->technical_id) : null;
        $financialRevision = $canMoney && $t?->financial_id ? DB::table('project_tender_financial')->find($t->financial_id) : null;
        $customers = [];
        if (! $t || $this->tab === 'details') {
            try {
                $customers = app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actor, mb_substr($this->customerSearch, 0, 150), 50));
                if (! empty($this->form['customer_id']) && ! collect($customers)->contains('id', (int) $this->form['customer_id'])) {
                    $customers[] = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $this->form['customer_id'], false));
                }
            } catch (CustomerContractException) {
            }
        }
        $users = $branch ? app(OrganizationApi::class)->projectAssignees($actor, $branch) : [];

        return view('tenders.show', compact('t', 'a', 'branch', 'canMoney', 'documents', 'technicalRevision', 'financialRevision', 'customers', 'users') + ['branches' => $a->branches($actor, 'manage'), 'approvals' => $t ? DB::table('project_tender_approvals')->where('tender_id', $t->id)->get() : collect(), 'bidDocument' => $financialRevision ? DB::table('project_tender_bid_documents')->where('financial_id', $financialRevision->id)->first() : null, 'submissions' => $t && $canMoney ? DB::table('project_tender_submissions')->where('tender_id', $t->id)->get() : collect(), 'results' => $t && $canMoney ? DB::table('project_tender_results')->where('tender_id', $t->id)->get() : collect(), 'plans' => $t && $canMoney ? DB::table('project_tender_handoff_plans')->where('tender_id', $t->id)->get() : collect(), 'handoffs' => $t && $canMoney ? DB::table('project_tender_handoffs')->where('tender_id', $t->id)->get() : collect(), 'guarantees' => $t && $canMoney ? DB::table('project_tender_guarantees as g')->where('g.tender_id', $t->id)->select('g.*')->selectSub(DB::table('project_tender_guarantee_events')->select('status')->whereColumn('guarantee_id', 'g.id')->orderByDesc('id')->limit(1), 'settled_status')->get() : collect(), 'events' => $t ? DB::table('project_tender_events')->where('tender_id', $t->id)->orderBy('id')->get() : collect()])->layout('layouts::app', ['title' => $t?->number ?? 'تسجيل مناقصة']);
    }
}

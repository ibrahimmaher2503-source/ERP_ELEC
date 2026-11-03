<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\ConvertAcceptedQuotation;
use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\QuotationActions;
use App\Modules\Sales\Application\QuotationApprovals;
use App\Modules\Sales\Application\QuotationCommercialPolicy;
use App\Modules\Sales\Application\QuotationDocuments;
use App\Modules\Sales\Application\QuotationExportDefinition;
use App\Modules\Sales\Application\QuotationIssuance;
use App\Modules\Sales\Application\QuotationReadiness;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrderCatalog;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationHistoryReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class QuotationDetailsPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $recordId;

    #[Locked]
    public int $version;

    public string $tab = 'main';

    public string $cancelReason = '';

    public string $decisionReason = '';

    public $document;

    public array $acceptance = ['accepted_on' => '', 'evidence_type' => 'purchase_order', 'reference' => ''];

    public $acceptanceFile;

    public string $conversionBranch = '';

    #[Locked]
    public string $conversionKey;

    protected const SCREEN = 'details';

    public function boot(): void
    {
        app(QuotationAccess::class)->authorize(auth()->id());
    }

    public function mount(int $quotation): void
    {
        app(QuotationAccess::class)->authorizeRecord(auth()->id(), $quotation);
        $r = Quotation::findOrFail($quotation);
        $this->recordId = $r->id;
        $this->version = $r->version;
        $this->conversionKey = (string) Str::uuid();
    }

    public function operation(string $operation): void
    {
        abort_unless(in_array($operation, ['submit', 'withdraw', 'approve', 'issue', 'accept', 'deliver', 'revise', 'convert'], true), 422);
        if ($operation === 'submit') {
            app(QuotationApprovals::class)->submit(auth()->id(), $this->recordId, $this->version);
            $this->refreshVersion();

            return;
        }
        if ($operation === 'withdraw') {
            app(QuotationApprovals::class)->withdraw(auth()->id(), $this->recordId, $this->version);
            $this->refreshVersion();

            return;
        }
        $r = Quotation::with('lines')->findOrFail($this->recordId);
        if ($operation === 'issue') {
            app(QuotationIssuance::class)->issue(auth()->id(), $r->id, $this->version);
            $this->refreshVersion();
            session()->flash('status', 'تم حفظ الإصدار الرسمي وملف PDF الثابت.');

            return;
        }
        app(QuotationReadiness::class)->guardOperation(auth()->id(), $r, $operation, $this->version);
    }

    public function accept(): void
    {
        app(QuotationIssuance::class)->accept(auth()->id(), $this->recordId, $this->version, $this->acceptance, $this->acceptanceFile);
        $this->acceptanceFile = null;
        $this->refreshVersion();
        session()->flash('status', 'تم تسجيل قبول الإصدار وإثباته المكتوب.');
    }

    public function convert()
    {
        $this->validate(['conversionBranch' => 'required|integer|min:1']);
        $order = app(ConvertAcceptedQuotation::class)->execute(auth()->id(), $this->recordId, $this->version, (int) $this->conversionBranch, $this->conversionKey);

        return $this->redirectRoute('sales-orders.show', ['order' => $order->id], navigate: true);
    }

    private function refreshVersion(): void
    {
        $this->version = Quotation::findOrFail($this->recordId)->version;
    }

    public function decide(int $requestId, bool $approved): void
    {
        app(QuotationApprovals::class)->decide(auth()->id(), $this->recordId, $requestId, $this->version, $approved, $this->decisionReason);
        $this->refreshVersion();
        $this->decisionReason = '';
        session()->flash('status', 'تم تسجيل القرار على جميع الاستثناءات.');
    }

    public function cancel(): void
    {
        app(QuotationActions::class)->cancel(auth()->id(), $this->recordId, $this->version, $this->cancelReason);
        $this->version = Quotation::findOrFail($this->recordId)->version;
        session()->flash('status', 'تم إلغاء عرض السعر نهائيًا.');
    }

    public function duplicate()
    {
        $r = app(QuotationActions::class)->duplicate(auth()->id(), $this->recordId);

        return $this->redirectRoute(app(QuotationAccess::class)->allows(auth()->id(), 'edit') ? 'quotations.edit' : 'quotations.show', ['quotation' => $r->id], navigate: true);
    }

    public function uploadDocument(): void
    {
        app(QuotationDocuments::class)->upload(auth()->id(), $this->recordId, $this->version, $this->document);
        $this->document = null;
        $this->version = Quotation::findOrFail($this->recordId)->version;
        session()->flash('status', 'تم حفظ المرفق الداخلي.');
    }

    public function export()
    {
        $path = app(QuotationExportDefinition::class)->export(auth()->id(), [], $this->recordId);

        return response()->download($path, 'quotation-'.$this->recordId.'.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
    }

    public function render()
    {
        app(QuotationAccess::class)->authorizeRecord(auth()->id(), $this->recordId);
        $r = Quotation::with('lines')->findOrFail($this->recordId);
        $access = app(QuotationAccess::class);
        $actor = auth()->id();
        $documents = $access->allows($actor, 'document_view') ? $r->attachments()->latest()->get() : collect();
        $history = $access->allows($actor, 'history') ? app(QuotationHistoryReader::class)->read($actor, $r->id) : [];
        $officialIssue = DB::table('sales_quotation_issues')->where('quotation_id', $r->id)->first();
        $acceptanceRecord = DB::table('sales_quotation_acceptances')->where('quotation_id', $r->id)->first(['accepted_on', 'evidence_type', 'reference', 'sha256']);
        $conversion = DB::table('sales_quotation_conversions')->where('quotation_id', $r->id)->first();
        $conversionBranches = app(SalesOrderAccess::class)->allows($actor)
            ? app(SalesOrderCatalog::class)->branches($actor, 'convert') : [];

        return view('sales.quotations.'.static::SCREEN, ['record' => $r, 'officialIssue' => $officialIssue, 'acceptanceRecord' => $acceptanceRecord, 'conversion' => $conversion, 'conversionBranches' => $conversionBranches, 'documents' => $documents, 'history' => $history, 'approvalRequests' => $r->approvals()->get(), 'evaluation' => app(QuotationCommercialPolicy::class)->evaluate($r), 'blockers' => app(QuotationReadiness::class)->blockers($r), 'can' => fn ($a) => $access->allows($actor, $a)])->layout('layouts::app', ['title' => 'تفاصيل عرض السعر']);
    }
}

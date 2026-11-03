<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Application\DraftAmounts;
use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\QuotationActions;
use App\Modules\Sales\Application\QuotationCatalog;
use App\Modules\Sales\Application\QuotationLineImportDefinition;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationPolicy;
use App\Modules\Sales\Domain\QuotationTerm;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class QuotationFormPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $version = null;

    public array $form = [];

    public array $lines = [];

    public string $customerSearch = '';

    public string $productSearch = '';

    public $importFile;

    #[Locked]
    public array $importPreview = [];

    public bool $importOpen = false;

    public function boot(): void
    {
        app(QuotationAccess::class)->authorize(auth()->id());
    }

    public function mount(?int $quotation = null): void
    {
        $this->importOpen = request()->boolean('import');
        $this->recordId = $quotation;
        app(QuotationAccess::class)->authorize(auth()->id(), $quotation === null ? 'create' : 'edit');
        if ($quotation !== null) {
            app(QuotationAccess::class)->authorizeRecord(auth()->id(), $quotation);
            $r = Quotation::with('lines')->findOrFail($quotation);
            abort_unless($r->editable(), 422, 'هذا العرض غير قابل للتعديل.');
            $this->version = $r->version;
            $this->form = $r->only(['customer_id', 'project_name', 'rfq_reference', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'payment_term_id', 'delivery_term_id', 'notes', 'exception_reason']);
            $this->form['quotation_date'] = $r->quotation_date->toDateString();
            $this->form['valid_until'] = $r->valid_until?->toDateString() ?? '';
            $this->lines = $r->lines->map(fn ($l) => $l->only(['product_id', 'unit_id', 'description', 'quantity', 'suggested_price', 'requested_price', 'discount_mode', 'discount_value', 'override_reason']) + ['sku' => $l->product_snapshot['sku']])->all();
        } else {
            $this->form = ['customer_id' => '', 'quotation_date' => now('Africa/Cairo')->toDateString(), 'valid_until' => '', 'project_name' => '', 'rfq_reference' => '', 'discount_mode' => 'percent', 'discount_value' => '0', 'payment_term_id' => '', 'delivery_term_id' => '', 'payment_terms' => '', 'delivery_terms' => '', 'notes' => '', 'exception_reason' => ''];
            $policy = QuotationPolicy::find(1);
            if ($policy) {
                $this->form['valid_until'] = now('Africa/Cairo')->addDays((int) $policy->settings['validity_default'])->toDateString();
            }
            $this->lines = [$this->emptyLine()];
        }
    }

    public function updatedForm($value, $key): void
    {
        if ($key === 'quotation_date') {
            foreach (array_keys($this->lines) as $index) {
                $this->refreshSuggestedPrice($index);
            }
        }
        if (in_array($key, ['payment_term_id', 'delivery_term_id'], true)) {
            $kind = str_starts_with($key, 'payment') ? 'payment' : 'delivery';
            $term = QuotationTerm::where('kind', $kind)->where('active', true)->find($value);
            $this->form[$kind.'_terms'] = $term?->body ?? '';
        }
    }

    public function addLine(): void
    {
        abort_if(count($this->lines) >= 500, 422);
        $this->lines[] = $this->emptyLine();
        $this->dispatch('quotation-dirty');
    }

    private function emptyLine(): array
    {
        return ['product_id' => '', 'unit_id' => '', 'quantity' => '1', 'description' => '', 'sku' => '', 'suggested_price' => null, 'requested_price' => '', 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => ''];
    }

    public function removeLine(int $index): void
    {
        if (array_key_exists($index, $this->lines)) {
            array_splice($this->lines, $index, 1);
            $this->dispatch('quotation-dirty');
        }
    }

    public function moveLine(int $index, int $delta): void
    {
        abort_unless(in_array($delta, [-1, 1], true), 422);
        $target = $index + $delta;
        if (isset($this->lines[$index],$this->lines[$target])) {
            [$this->lines[$index],$this->lines[$target]] = [$this->lines[$target], $this->lines[$index]];
            $this->dispatch('quotation-dirty');
        }
    }

    public function updatedLines($value, $key): void
    {
        if (preg_match('/^(\d+)\.unit_id$/D', $key, $match)) {
            $this->refreshSuggestedPrice((int) $match[1]);

            return;
        }
        if (! preg_match('/^(\d+)\.product_id$/D', $key, $match)) {
            return;
        }
        $i = (int) $match[1];
        if (! isset($this->lines[$i])) {
            return;
        }
        $p = empty($value) ? null : app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $value))->product;
        if ($p !== null && ! $p['active']) {
            throw ValidationException::withMessages(["lines.$i.product_id" => 'الصنف غير نشط.']);
        }
        // Selected products remain selectable outside the current search/page.
        $this->lines[$i]['unit_id'] = $p['sales_unit_id'] ?? $p['base_unit_id'] ?? '';
        $this->lines[$i]['sku'] = $p['sku'] ?? '';
        $this->lines[$i]['description'] = $p['name'] ?? '';
        $this->lines[$i]['suggested_price'] = null;
        $this->lines[$i]['requested_price'] = '';
        $this->lines[$i]['discount_mode'] = 'percent';
        $this->lines[$i]['discount_value'] = '0';
        $this->lines[$i]['override_reason'] = '';
        $this->refreshSuggestedPrice($i);
    }

    private function refreshSuggestedPrice(int $index): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }
        $line = $this->lines[$index];
        $date = $this->form['quotation_date'] ?? '';
        $valid = validator(['product' => $line['product_id'], 'unit' => $line['unit_id'], 'date' => $date],
            ['product' => 'required|integer|min:1', 'unit' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d'])->passes();
        $price = $valid ? app(QuotationPricing::class)->suggested(auth()->id(), (int) $line['product_id'], (int) $line['unit_id'], $date) : null;
        $this->lines[$index]['suggested_price'] = $price['amount'] ?? null;
    }

    public function save()
    {
        $r = app(QuotationActions::class)->save(auth()->id(), $this->form + ['lines' => $this->lines], $this->recordId, $this->version);
        $this->recordId = $r->id;
        $this->version = $r->version;
        $this->dispatch('quotation-saved');
        session()->flash('status', 'تم حفظ مسودة عرض السعر.');

        return $this->redirectRoute('quotations.show', ['quotation' => $r->id], navigate: true);
    }

    public function downloadTemplate()
    {
        $path = app(QuotationLineImportDefinition::class)->template(auth()->id());

        return response()->download($path, 'quotation-lines-template.xlsx', ['Cache-Control' => 'private, no-store'])->setPrivate()->deleteFileAfterSend();
    }

    public function previewImport(): void
    {
        $this->importPreview = app(QuotationLineImportDefinition::class)->preview(auth()->id(), $this->importFile, $this->form['quotation_date']);
    }

    public function applyImport(): void
    {
        app(QuotationAccess::class)->authorize(auth()->id(), $this->recordId === null ? 'create' : 'edit');
        $definition = app(QuotationLineImportDefinition::class);
        $definition->authorize(auth()->id());
        if (! $this->importPreview || collect($this->importPreview)->contains(fn ($r) => $r['error'] !== null)) {
            throw ValidationException::withMessages(['importFile' => 'صحح الصفوف غير الصالحة قبل إضافة البنود.']);
        }
        $new = [];
        foreach ($this->importPreview as $row) {
            $new[] = $definition->resolve(auth()->id(), $row['input'], $this->form['quotation_date']);
        }
        $existing = array_values(array_filter($this->lines, fn ($l) => ! empty($l['product_id'])));
        if (count($existing) + count($new) > 500) {
            throw ValidationException::withMessages(['importFile' => 'الحد الأقصى 500 بند.']);
        }
        $this->lines = array_merge($existing, $new);
        $this->importPreview = [];
        $this->importFile = null;
        $this->importOpen = false;
        $this->dispatch('quotation-dirty');
    }

    public function render()
    {
        if ($this->recordId !== null) {
            app(QuotationAccess::class)->authorizeRecord(auth()->id(), $this->recordId);
        }
        app(QuotationAccess::class)->authorize(auth()->id(), $this->recordId === null ? 'create' : 'edit');
        $catalog = app(QuotationCatalog::class);
        $customerOptions = $catalog->customers(auth()->id(), $this->customerSearch);
        if (! empty($this->form['customer_id']) && ! collect($customerOptions)->contains('id', (int) $this->form['customer_id'])) {
            $customerOptions[] = $catalog->customer(auth()->id(), (int) $this->form['customer_id']);
        }
        $products = $catalog->products(auth()->id(), mb_substr($this->productSearch, 0, 200));
        // Keep selected values available even when searching another product.
        foreach ($this->lines as $l) {
            if (! empty($l['product_id']) && ! collect($products)->contains('id', (int) $l['product_id'])) {
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $l['product_id']))->product;
                $products[] = $p;
            }
        }
        try {
            $amounts = app(DraftAmounts::class)->calculate($this->lines, $this->form['discount_mode'], $this->form['discount_value']);
            $amountErrors = [];
        } catch (ValidationException $e) {
            $amounts = ['lines' => [], 'gross' => null, 'line_discounts' => null, 'quotation_discount' => null, 'net' => null, 'tax' => null, 'total' => null];
            $amountErrors = $e->validator->errors()->all();
        }

        return view('sales.quotations.form', ['terms' => QuotationTerm::where('active', true)->orderBy('name')->get(), 'hasPolicy' => QuotationPolicy::whereKey(1)->exists(), 'customers' => $customerOptions, 'products' => $products, 'units' => $catalog->units(auth()->id()), 'amounts' => $amounts, 'amountErrors' => $amountErrors, 'can' => fn ($a) => app(QuotationAccess::class)->allows(auth()->id(), $a)])->layout('layouts::app', ['title' => $this->recordId ? 'تعديل عرض السعر' : 'إنشاء عرض سعر جديد']);
    }
}

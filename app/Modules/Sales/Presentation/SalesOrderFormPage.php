<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Application\DraftAmounts;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesOrderCatalog;
use App\Modules\Sales\Application\SalesOrderQuery;
use App\Modules\Sales\Domain\QuotationTerm;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SalesOrderFormPage extends Component
{
    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public string $creationKey = '';

    public array $form = [];

    public array $lines = [];

    public string $customerSearch = '';

    public string $productSearch = '';

    public string $paymentTermId = '';

    public string $deliveryTermId = '';

    public function boot(): void
    {
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'view');
    }

    public function mount(?int $order = null): void
    {
        $this->orderId = $order;
        $this->creationKey = (string) Str::uuid();

        if ($order === null) {
            app(SalesOrderAccess::class)->authorize(auth()->id(), 'create');
            $this->form = [
                'branch_id' => '',
                'customer_id' => '',
                'project_name' => '',
                'customer_reference' => '',
                'order_date' => now('Africa/Cairo')->toDateString(),
                'requested_delivery_date' => '',
                'discount_mode' => 'percent',
                'discount_value' => '0',
                'tax_treatment' => 'vat_14',
                'tax_exempt_reason' => '',
                'payment_terms' => '',
                'delivery_terms' => '',
                'notes' => '',
            ];
            $this->lines = [$this->emptyLine()];

            return;
        }

        $record = app(SalesOrderQuery::class)->query(auth()->id())->with('lines')->findOrFail($order);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'edit', (int) $record->branch_id);
        abort_unless($record->editable() && $record->source_type === 'direct', 422, 'هذا الأمر غير قابل للتعديل في حالته الحالية.');

        $this->version = (int) $record->version;
        $this->creationKey = (string) $record->draft_key;
        $this->form = $record->only([
            'branch_id', 'customer_id', 'project_name', 'customer_reference',
            'discount_mode', 'discount_value', 'tax_treatment', 'tax_exempt_reason', 'payment_terms', 'delivery_terms', 'notes',
        ]);
        $this->form['tax_treatment'] ??= 'rule';
        $this->form['tax_exempt_reason'] ??= '';
        $this->form['discount_value'] = SalesOrderDisplay::inputDecimal((string) ($this->form['discount_value'] ?? '0')) ?: '0';
        $this->form['order_date'] = $record->order_date->toDateString();
        $this->form['requested_delivery_date'] = $record->requested_delivery_date?->toDateString() ?? '';
        $productsApi = app(ProductsApi::class);
        $this->lines = $record->lines->map(function ($line) use ($productsApi) {
            $categoryId = '';
            $brandId = '';
            $defaults = ['spec_model' => '', 'spec_type' => '', 'spec_ampere' => '', 'spec_kilo' => ''];
            try {
                $p = $productsApi->getProduct(new GetProductRequest(auth()->id(), (int) $line->product_id))->product;
                $categoryId = (string) ($p['category_id'] ?? '');
                $brandId = (string) ($p['brand_id'] ?? '');
                $defaults = $productsApi->extractGuidedSpec($p);
            } catch (\Throwable) {
            }

            return [
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'description' => $line->description,
                'quantity' => SalesOrderDisplay::inputDecimal((string) $line->quantity),
                'suggested_price' => $line->suggested_price !== null ? SalesOrderDisplay::inputDecimal((string) $line->suggested_price) : null,
                'requested_price' => $line->requested_price !== null ? SalesOrderDisplay::inputDecimal((string) $line->requested_price) : '',
                'price_source' => $line->price_source,
                'discount_mode' => $line->discount_mode,
                'discount_value' => SalesOrderDisplay::inputDecimal((string) $line->discount_value) ?: '0',
                'override_reason' => $line->override_reason ?? '',
                'sku' => $line->product_snapshot['sku'] ?? '—',
                'product_name' => $line->product_snapshot['name'] ?? $line->description,
                'spec_model' => $line->spec_model ?? $defaults['spec_model'],
                'spec_type' => $line->spec_type ?? $defaults['spec_type'],
                'spec_ampere' => $line->spec_ampere ?? $defaults['spec_ampere'],
                'spec_kilo' => $line->spec_kilo ?? $defaults['spec_kilo'],
                'serial_reference' => $line->serial_reference ?? '',
                'accessories_note' => $line->accessories_note ?? '',
            ];
        })->all();

        $activeTerms = QuotationTerm::where('active', true)->get();
        if (! empty($this->form['payment_terms'])) {
            $match = $activeTerms->where('kind', 'payment')->firstWhere('body', $this->form['payment_terms']);
            $this->paymentTermId = $match ? (string) $match->id : '';
        }
        if (! empty($this->form['delivery_terms'])) {
            $match = $activeTerms->where('kind', 'delivery')->firstWhere('body', $this->form['delivery_terms']);
            $this->deliveryTermId = $match ? (string) $match->id : '';
        }
    }

    public function updatedPaymentTermId($value): void
    {
        $this->authorizeCurrentAction();
        $term = $value !== '' ? QuotationTerm::where('kind', 'payment')->where('active', true)->find($value) : null;
        if ($term) {
            $this->form['payment_terms'] = $term->body;
            $this->dispatch('sales-order-dirty');
        }
    }

    public function updatedDeliveryTermId($value): void
    {
        $this->authorizeCurrentAction();
        $term = $value !== '' ? QuotationTerm::where('kind', 'delivery')->where('active', true)->find($value) : null;
        if ($term) {
            $this->form['delivery_terms'] = $term->body;
            $this->dispatch('sales-order-dirty');
        }
    }

    public function addLine(): void
    {
        $this->authorizeCurrentAction();
        abort_if(count($this->lines) >= 500, 422);
        $this->lines[] = $this->emptyLine();
        $this->dispatch('sales-order-dirty');
    }

    public function duplicateLine(int $index): void
    {
        $this->authorizeCurrentAction();
        abort_if(count($this->lines) >= 500, 422);
        if (isset($this->lines[$index])) {
            array_splice($this->lines, $index + 1, 0, [$this->lines[$index]]);
            $this->dispatch('sales-order-dirty');
        }
    }

    private function emptyLine(): array
    {
        return [
            'category_id' => '',
            'brand_id' => '',
            'product_id' => '',
            'unit_id' => '',
            'description' => '',
            'sku' => '',
            'product_name' => '',
            'quantity' => '1',
            'suggested_price' => null,
            'requested_price' => '',
            'price_source' => null,
            'discount_mode' => 'percent',
            'discount_value' => '0',
            'override_reason' => '',
            'spec_model' => '',
            'spec_type' => '',
            'spec_ampere' => '',
            'spec_kilo' => '',
            'serial_reference' => '',
            'accessories_note' => '',
        ];
    }

    public function removeLine(int $index): void
    {
        $this->authorizeCurrentAction();
        if (array_key_exists($index, $this->lines)) {
            array_splice($this->lines, $index, 1);
            $this->dispatch('sales-order-dirty');
        }
    }

    public function moveLine(int $index, int $delta): void
    {
        $this->authorizeCurrentAction();
        abort_unless(in_array($delta, [-1, 1], true), 422);
        $target = $index + $delta;
        if (isset($this->lines[$index], $this->lines[$target])) {
            [$this->lines[$index], $this->lines[$target]] = [$this->lines[$target], $this->lines[$index]];
            $this->dispatch('sales-order-dirty');
        }
    }

    public function updatedLines($value, $key): void
    {
        $this->authorizeCurrentAction();
        if (preg_match('/^(\d+)\.unit_id$/D', $key, $match)) {
            $this->refreshSuggestedPrice((int) $match[1]);

            return;
        }
        if (! preg_match('/^(\d+)\.product_id$/D', $key, $match)) {
            return;
        }

        $index = (int) $match[1];
        if (! isset($this->lines[$index])) {
            return;
        }
        $productsApi = app(ProductsApi::class);
        $product = empty($value) ? null : $productsApi->getProduct(new GetProductRequest(auth()->id(), (int) $value))->product;
        if ($product !== null && ! $product['active']) {
            throw ValidationException::withMessages(["lines.$index.product_id" => 'الصنف غير نشط.']);
        }
        $specs = $product ? $productsApi->extractGuidedSpec($product) : ['spec_model' => '', 'spec_type' => '', 'spec_ampere' => '', 'spec_kilo' => ''];

        if ($product) {
            $this->lines[$index]['category_id'] = (string) ($product['category_id'] ?? '');
            $this->lines[$index]['brand_id'] = (string) ($product['brand_id'] ?? '');
        }
        $this->lines[$index]['unit_id'] = $product['sales_unit_id'] ?? $product['base_unit_id'] ?? '';
        $this->lines[$index]['sku'] = $product['sku'] ?? '';
        $this->lines[$index]['description'] = $product['name'] ?? '';
        $this->lines[$index]['product_name'] = $product['name'] ?? '';
        $this->lines[$index]['spec_model'] = $specs['spec_model'];
        $this->lines[$index]['spec_type'] = $specs['spec_type'];
        $this->lines[$index]['spec_ampere'] = $specs['spec_ampere'];
        $this->lines[$index]['spec_kilo'] = $specs['spec_kilo'];
        $this->lines[$index]['suggested_price'] = null;
        $this->lines[$index]['requested_price'] = '';
        $this->lines[$index]['price_source'] = null;
        $this->lines[$index]['discount_mode'] = 'percent';
        $this->lines[$index]['discount_value'] = '0';
        $this->lines[$index]['override_reason'] = '';
        $this->refreshSuggestedPrice($index);
        $this->dispatch('sales-order-dirty');
    }

    public function updatedForm($value, $key): void
    {
        $this->authorizeCurrentAction();
        if ($key === 'order_date') {
            foreach (array_keys($this->lines) as $index) {
                $this->refreshSuggestedPrice($index);
            }
        }
        if ($key === 'payment_terms') {
            $term = QuotationTerm::where('kind', 'payment')->where('active', true)->where('body', (string) $value)->first();
            $this->paymentTermId = $term ? (string) $term->id : '';
        }
        if ($key === 'delivery_terms') {
            $term = QuotationTerm::where('kind', 'delivery')->where('active', true)->where('body', (string) $value)->first();
            $this->deliveryTermId = $term ? (string) $term->id : '';
        }
    }

    private function refreshSuggestedPrice(int $index): void
    {
        if (! isset($this->lines[$index])) {
            return;
        }
        $line = $this->lines[$index];
        $date = $this->form['order_date'] ?? '';
        $valid = validator(
            ['product' => $line['product_id'], 'unit' => $line['unit_id'], 'date' => $date],
            ['product' => 'required|integer|min:1', 'unit' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d']
        )->passes();
        $price = $valid ? app(QuotationPricing::class)->suggested(auth()->id(), (int) $line['product_id'], (int) $line['unit_id'], $date) : null;
        $this->lines[$index]['suggested_price'] = isset($price['amount']) ? SalesOrderDisplay::inputDecimal((string) $price['amount']) : null;
        $this->lines[$index]['price_source'] = $price;
    }

    private function authorizeCurrentAction(): void
    {
        if ($this->orderId === null) {
            app(SalesOrderAccess::class)->authorize(auth()->id(), 'create');

            return;
        }

        $record = app(SalesOrderQuery::class)->query(auth()->id())->findOrFail($this->orderId);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'edit', (int) $record->branch_id);
        abort_unless($record->editable() && $record->source_type === 'direct' && (int) $record->version === $this->version, 409, 'تغيرت المسودة أو لم تعد قابلة للتعديل. أعد تحميل الصفحة.');
    }

    private function input(): array
    {
        return [
            'creation_key' => $this->creationKey,
            'branch_id' => $this->form['branch_id'] ?? null,
            'customer_id' => $this->form['customer_id'] ?? null,
            'project_name' => $this->form['project_name'] ?? null,
            'customer_reference' => $this->form['customer_reference'] ?? null,
            'order_date' => $this->form['order_date'] ?? null,
            'requested_delivery_date' => $this->form['requested_delivery_date'] ?? null,
            'discount_mode' => $this->form['discount_mode'] ?? 'percent',
            'discount_value' => $this->form['discount_value'] ?? '0',
            'tax_treatment' => $this->form['tax_treatment'] ?? 'rule',
            'tax_exempt_reason' => ($this->form['tax_treatment'] ?? 'rule') === 'exempt_0' ? ($this->form['tax_exempt_reason'] ?? '') : null,
            'payment_terms' => $this->form['payment_terms'] ?? null,
            'delivery_terms' => $this->form['delivery_terms'] ?? null,
            'notes' => $this->form['notes'] ?? null,
            'lines' => array_map(fn (array $line) => [
                'product_id' => $line['product_id'] ?? null,
                'unit_id' => $line['unit_id'] ?? null,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'suggested_price' => null,
                'requested_price' => ($line['requested_price'] ?? '') === '' ? null : $line['requested_price'],
                'price_source' => null,
                'discount_mode' => $line['discount_mode'] ?? 'percent',
                'discount_value' => $line['discount_value'] ?? '0',
                'override_reason' => $line['override_reason'] ?? null,
                'spec_model' => $line['spec_model'] ?? null,
                'spec_type' => $line['spec_type'] ?? null,
                'spec_ampere' => $line['spec_ampere'] ?? null,
                'spec_kilo' => $line['spec_kilo'] ?? null,
                'serial_reference' => $line['serial_reference'] ?? null,
                'accessories_note' => $line['accessories_note'] ?? null,
            ], $this->lines),
        ];
    }

    public function save()
    {
        $this->authorizeCurrentAction();
        $record = app(SalesOrderActions::class)->save(auth()->id(), $this->input(), $this->orderId, $this->version);
        $this->orderId = (int) $record->id;
        $this->version = (int) $record->version;
        $this->creationKey = (string) $record->draft_key;
        $this->dispatch('sales-order-saved');
        session()->flash('status', 'تم حفظ مسودة أمر البيع.');

        return $this->redirectRoute('sales-orders.show', ['order' => $record->id], navigate: true);
    }

    private function isValidDecimal(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/D', $value) === 1;
    }

    public function render()
    {
        $this->authorizeCurrentAction();
        $actor = auth()->id();
        $catalog = app(SalesOrderCatalog::class);
        $customers = $catalog->customers($actor, $this->customerSearch);
        if (! empty($this->form['customer_id']) && ! collect($customers)->contains('id', (int) $this->form['customer_id'])) {
            $customers[] = $catalog->customer($actor, (int) $this->form['customer_id']);
        }

        $productsApi = app(ProductsApi::class);
        $guided = $productsApi->guidedCatalog($actor, mb_substr($this->productSearch, 0, 200));
        $categories = $guided['categories'];
        $brands = $guided['brands'];
        $products = $guided['products'];
        foreach ($this->lines as $line) {
            $catId = ! empty($line['category_id']) ? (int) $line['category_id'] : null;
            $brId = ! empty($line['brand_id']) ? (int) $line['brand_id'] : null;
            if ($catId || $brId) {
                foreach ($productsApi->guidedCatalog($actor, mb_substr($this->productSearch, 0, 200), $catId, $brId)['products'] as $fp) {
                    if (! collect($products)->contains('id', (int) $fp['id'])) {
                        $products[] = $fp;
                    }
                }
            }
        }
        foreach ($this->lines as $line) {
            if (empty($line['product_id']) || empty($line['unit_id']) || collect($products)->contains('id', (int) $line['product_id'])) {
                continue;
            }
            $products[] = [
                'id' => (int) $line['product_id'],
                'sku' => $line['sku'] ?? '—',
                'name' => $line['product_name'] ?? $line['description'] ?? 'الصنف المحدد',
                'category_id' => ! empty($line['category_id']) ? (int) $line['category_id'] : null,
                'brand_id' => ! empty($line['brand_id']) ? (int) $line['brand_id'] : null,
                'sales_unit_id' => $line['unit_id'],
                'base_unit_id' => $line['unit_id'],
            ];
        }

        try {
            $amounts = app(DraftAmounts::class)->calculate($this->lines, $this->form['discount_mode'] ?? 'percent', (string) ($this->form['discount_value'] ?? '0'));
            $amountErrors = [];
            $taxChoice = (string) ($this->form['tax_treatment'] ?? 'vat_14');
            if ($amounts['net'] !== null && in_array($taxChoice, ['vat_14', 'exempt_0'], true)) {
                $roundedNet = FinancialDecimal::round($amounts['net']);
                $previewTax = $taxChoice === 'exempt_0' ? '0.00' : FinancialDecimal::round(bcmul($amounts['net'], '0.14', 10));
                $amounts['preview_tax'] = $previewTax;
                $amounts['preview_total'] = bcadd($roundedNet, $previewTax, 2);
                $amounts['tax_treatment'] = $taxChoice;
            }
        } catch (ValidationException $exception) {
            $amounts = ['lines' => [], 'gross' => null, 'line_discounts' => null, 'quotation_discount' => null, 'net' => null, 'tax' => null, 'total' => null];
            $amountErrors = $exception->validator->errors()->all();
        }

        $action = $this->orderId === null ? 'create' : 'edit';
        $branches = $catalog->branches($actor, $action);
        if ($this->orderId !== null && ! collect($branches)->contains('id', (int) ($this->form['branch_id'] ?? 0))) {
            $record = app(SalesOrderQuery::class)->query($actor)->findOrFail($this->orderId);
            $branches[] = [
                'id' => $record->branch_id,
                'code' => $record->branch_snapshot['code'] ?? '',
                'name' => $record->branch_snapshot['name'] ?? '',
                'address' => $record->branch_snapshot['address'] ?? '',
            ];
        }

        $terms = QuotationTerm::where('active', true)->orderBy('kind')->orderBy('name')->get();
        $paymentTerms = $terms->where('kind', 'payment')->values();
        $deliveryTerms = $terms->where('kind', 'delivery')->values();

        $lineExceptions = [];
        $exceptionCount = 0;
        foreach ($this->lines as $i => $line) {
            $flags = [];
            $req = (string) ($line['requested_price'] ?? '');
            $sug = $line['suggested_price'] ?? null;
            if ($req !== '' && $sug !== null && $this->isValidDecimal($req) && $this->isValidDecimal((string) $sug) && bccomp($req, (string) $sug, 8) !== 0) {
                $flags[] = 'price';
            }
            $disc = (string) ($line['discount_value'] ?? '0');
            if ($this->isValidDecimal($disc) && bccomp($disc, '0', 8) > 0) {
                $flags[] = 'discount';
            }
            $lineExceptions[$i] = $flags;
            $exceptionCount += count($flags);
        }

        $orderDiscountVal = (string) ($this->form['discount_value'] ?? '0');
        $hasOrderDiscount = $this->isValidDecimal($orderDiscountVal) && bccomp($orderDiscountVal, '0', 8) > 0;
        $paymentStandard = $paymentTerms->contains('body', (string) ($this->form['payment_terms'] ?? ''));
        $deliveryStandard = $deliveryTerms->contains('body', (string) ($this->form['delivery_terms'] ?? ''));
        if ($hasOrderDiscount) {
            $exceptionCount++;
        }
        if (! $paymentStandard) {
            $exceptionCount++;
        }
        if (! $deliveryStandard) {
            $exceptionCount++;
        }

        return view('sales.orders.form', [
            'branches' => $branches,
            'customers' => $customers,
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'units' => $catalog->units($actor),
            'amounts' => $amounts,
            'amountErrors' => $amountErrors,
            'paymentTerms' => $paymentTerms,
            'deliveryTerms' => $deliveryTerms,
            'lineExceptions' => $lineExceptions,
            'termStatus' => [
                'payment_standard' => $paymentStandard,
                'delivery_standard' => $deliveryStandard,
                'order_discount' => $hasOrderDiscount,
            ],
            'exceptionCount' => $exceptionCount,
        ])->layout('layouts::app', ['title' => $this->orderId ? 'تعديل أمر البيع' : 'إنشاء أمر بيع']);
    }
}

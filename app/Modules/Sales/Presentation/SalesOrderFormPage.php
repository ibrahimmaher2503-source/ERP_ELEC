<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\DraftAmounts;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesOrderCatalog;
use App\Modules\Sales\Application\SalesOrderQuery;
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
                'payment_terms' => '',
                'delivery_terms' => '',
                'notes' => '',
            ];
            $this->lines = [$this->emptyLine()];

            return;
        }

        $record = app(SalesOrderQuery::class)->query(auth()->id())->with('lines')->findOrFail($order);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'edit', (int) $record->branch_id);
        abort_unless($record->editable(), 422, 'هذا الأمر غير قابل للتعديل في حالته الحالية.');

        $this->version = (int) $record->version;
        $this->creationKey = (string) $record->draft_key;
        $this->form = $record->only([
            'branch_id', 'customer_id', 'project_name', 'customer_reference',
            'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'notes',
        ]);
        $this->form['order_date'] = $record->order_date->toDateString();
        $this->form['requested_delivery_date'] = $record->requested_delivery_date?->toDateString() ?? '';
        $this->lines = $record->lines->map(fn ($line) => $line->only([
            'product_id', 'unit_id', 'description', 'quantity', 'suggested_price',
            'requested_price', 'price_source', 'discount_mode', 'discount_value', 'override_reason',
        ]) + [
            'sku' => $line->product_snapshot['sku'] ?? '—',
            'product_name' => $line->product_snapshot['name'] ?? $line->description,
        ])->all();
    }

    public function addLine(): void
    {
        $this->authorizeCurrentAction();
        abort_if(count($this->lines) >= 500, 422);
        $this->lines[] = $this->emptyLine();
        $this->dispatch('sales-order-dirty');
    }

    private function emptyLine(): array
    {
        return [
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
        if (! preg_match('/^(\d+)\.product_id$/D', $key, $match)) {
            return;
        }

        $index = (int) $match[1];
        if (! isset($this->lines[$index])) {
            return;
        }
        $product = empty($value) ? null : app(ProductsApi::class)->getProduct(new GetProductRequest(auth()->id(), (int) $value))->product;
        if ($product !== null && ! $product['active']) {
            throw ValidationException::withMessages(["lines.$index.product_id" => 'الصنف غير نشط.']);
        }

        $this->lines[$index]['unit_id'] = $product['sales_unit_id'] ?? $product['base_unit_id'] ?? '';
        $this->lines[$index]['sku'] = $product['sku'] ?? '';
        $this->lines[$index]['description'] = $product['name'] ?? '';
        $this->lines[$index]['product_name'] = $product['name'] ?? '';
        $this->lines[$index]['suggested_price'] = null;
        $this->lines[$index]['requested_price'] = '';
        $this->lines[$index]['price_source'] = null;
        $this->lines[$index]['discount_mode'] = 'percent';
        $this->lines[$index]['discount_value'] = '0';
        $this->lines[$index]['override_reason'] = '';
        $this->dispatch('sales-order-dirty');
    }

    private function authorizeCurrentAction(): void
    {
        if ($this->orderId === null) {
            app(SalesOrderAccess::class)->authorize(auth()->id(), 'create');

            return;
        }

        $record = app(SalesOrderQuery::class)->query(auth()->id())->findOrFail($this->orderId);
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'edit', (int) $record->branch_id);
        abort_unless($record->editable() && (int) $record->version === $this->version, 409, 'تغيرت المسودة أو لم تعد قابلة للتعديل. أعد تحميل الصفحة.');
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

    public function render()
    {
        $this->authorizeCurrentAction();
        $actor = auth()->id();
        $catalog = app(SalesOrderCatalog::class);
        $customers = $catalog->customers($actor, $this->customerSearch);
        if (! empty($this->form['customer_id']) && ! collect($customers)->contains('id', (int) $this->form['customer_id'])) {
            $customers[] = $catalog->customer($actor, (int) $this->form['customer_id']);
        }

        $products = $catalog->products($actor, $this->productSearch);
        foreach ($this->lines as $line) {
            if (empty($line['product_id']) || empty($line['unit_id']) || collect($products)->contains('id', (int) $line['product_id'])) {
                continue;
            }
            $products[] = [
                'id' => (int) $line['product_id'],
                'sku' => $line['sku'] ?? '—',
                'name' => $line['product_name'] ?? $line['description'] ?? 'الصنف المحدد',
                'sales_unit_id' => $line['unit_id'],
                'base_unit_id' => $line['unit_id'],
            ];
        }

        try {
            $amounts = app(DraftAmounts::class)->calculate($this->lines, $this->form['discount_mode'] ?? 'percent', (string) ($this->form['discount_value'] ?? '0'));
            $amountErrors = [];
        } catch (ValidationException $exception) {
            $amounts = ['lines' => [], 'gross' => null, 'line_discounts' => null, 'quotation_discount' => null, 'net' => null, 'tax' => null, 'total' => null];
            $amountErrors = $exception->validator->errors()->all();
        }

        $action = $this->orderId === null ? 'create' : 'edit';
        $branches = $catalog->branches($actor, $action);
        if ($this->orderId !== null && ! collect($branches)->contains('id', (int) ($this->form['branch_id'] ?? 0))) {
            $record = app(SalesOrderQuery::class)->query($actor)->findOrFail($this->orderId);
            $branches[] = ['id' => $record->branch_id, 'code' => $record->branch_snapshot['code'] ?? '', 'name' => $record->branch_snapshot['name'] ?? '', 'address' => $record->branch_snapshot['address'] ?? ''];
        }

        return view('sales.orders.form', [
            'branches' => $branches,
            'customers' => $customers,
            'products' => $products,
            'units' => $catalog->units($actor),
            'amounts' => $amounts,
            'amountErrors' => $amountErrors,
        ])->layout('layouts::app', ['title' => $this->orderId ? 'تعديل أمر البيع' : 'إنشاء أمر بيع']);
    }
}

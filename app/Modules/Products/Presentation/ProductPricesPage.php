<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Products\Domain\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ProductPricesPage extends Component
{
    #[Locked]
    public int $productId;

    public array $form = ['amount' => '', 'unit_id' => '', 'starts_on' => '', 'ends_on' => '', 'reference' => ''];

    public function mount(int $product): void
    {
        $this->productId = $product;
    }

    public function boot(): void
    {
        app(ProductAccess::class)->authorize(auth()->id(), 'M02.product_view');
        app(ProductAccess::class)->authorize(auth()->id(), 'M02.price_view');
    }

    public function save(): void
    {
        app(SalePrices::class)->save(auth()->id(), $this->productId, $this->form);
        $this->form['amount'] = '';
        session()->flash('status', 'تم حفظ مسودة السعر للاعتماد المستقل.');
    }

    public function decide(int $price, string $action): void
    {
        abort_unless(DB::table('product_sale_prices')->where('product_id', $this->productId)->where('id', $price)->exists(), 404);
        app(SalePrices::class)->decide(auth()->id(), $price, $action);
    }

    public function render()
    {
        $product = Product::with(['baseUnit', 'conversions.unit'])->findOrFail($this->productId);

        return view('products.prices', ['product' => $product, 'rows' => DB::table('product_sale_prices')->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'product_sale_prices.unit_id')->where('product_sale_prices.product_id', $this->productId)->orderByDesc('product_sale_prices.id')->select('product_sale_prices.*', 'units_of_measure.name as unit_name')->get(), 'access' => app(ProductAccess::class)])
            ->layout('layouts::app', ['title' => 'أسعار البيع المعتمدة']);
    }
}

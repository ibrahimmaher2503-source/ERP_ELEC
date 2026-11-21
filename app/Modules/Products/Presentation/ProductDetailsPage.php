<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductHistoryReader;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProductDetailsPage extends Component
{
    #[Locked]
    public int $productId;

    public function mount(int $product): void
    {
        $this->productId = $product;
    }

    public function boot(): void
    {
        app(ProductAccess::class)->authorize(auth()->id(), 'M02.product_view');
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(ProductAccess::class);
        $product = Product::with(['category', 'brand', 'baseUnit', 'purchaseUnit', 'salesUnit', 'conversions.unit', 'media'])->findOrFail($this->productId);
        $canNotes = $access->allows($actor, 'M02.notes_view');
        if (! $canNotes) {
            $product->internal_notes = null;
        }
        $canPrices = $access->allows($actor, 'M02.price_view');
        $prices = $canPrices
            ? DB::table('product_sale_prices')
                ->leftJoin('units_of_measure', 'units_of_measure.id', '=', 'product_sale_prices.unit_id')
                ->where('product_sale_prices.product_id', $product->id)
                ->orderByDesc('product_sale_prices.id')
                ->limit(25)
                ->select('product_sale_prices.*', 'units_of_measure.name as unit_name')
                ->get()
            : collect();
        $guidedSpecs = app(ProductsApi::class)->extractGuidedSpec(['model' => $product->model, 'attributes' => $product->attributes]);

        return view('products.details', [
            'product' => $product,
            'media' => $product->media,
            'audit' => app(ProductHistoryReader::class)->forProduct($product->id),
            'canUpdate' => $access->allows($actor, 'M02.product_update'),
            'canNotes' => $canNotes,
            'canMedia' => true,
            'canPrices' => $canPrices,
            'prices' => $prices,
            'guidedSpecs' => $guidedSpecs,
        ])->layout('layouts::app', ['title' => $product->name]);
    }
}

<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductHistoryReader;
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
        $access = app(ProductAccess::class);
        $product = Product::with(['category', 'brand', 'baseUnit', 'purchaseUnit', 'salesUnit', 'conversions.unit', 'media'])->findOrFail($this->productId);
        $canNotes = $access->allows(auth()->id(), 'M02.notes_view');
        if (! $canNotes) {
            $product->internal_notes = null;
        }

        return view('products.details', ['product' => $product, 'media' => $product->media, 'audit' => app(ProductHistoryReader::class)->forProduct($product->id), 'canUpdate' => $access->allows(auth()->id(), 'M02.product_update'), 'canNotes' => $canNotes, 'canMedia' => true])->layout('layouts::app', ['title' => $product->name]);
    }
}

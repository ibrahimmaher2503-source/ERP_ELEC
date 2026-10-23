<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\ProductActions;
use App\Modules\Products\Application\ProductMediaActions;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductMedia;
use App\Modules\Products\Domain\Unit;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class ProductFormPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public ?int $version = null;

    public array $form = ['sku' => '', 'name' => '', 'name_en' => '', 'short_description' => '', 'description' => '', 'kind' => '', 'tracking' => '', 'category_id' => '', 'brand_id' => '', 'model' => '', 'base_unit_id' => '', 'purchase_unit_id' => '', 'sales_unit_id' => '', 'barcode' => '', 'active' => true, 'internal_notes' => ''];

    public array $conversions = [];

    public array $specifications = [];

    public array $uploads = [];

    public function boot(): void
    {
        app(ProductAccess::class)->authorize(auth()->id(), 'M02.product_view');
    }

    public function mount(?int $product = null): void
    {
        $this->productId = $product;
        $access = app(ProductAccess::class);
        $access->authorize(auth()->id(), $product === null ? 'M02.product_create' : 'M02.product_update');
        if ($product !== null) {
            $record = Product::with('conversions')->findOrFail($product);
            $this->version = $record->version;
            foreach (array_keys($this->form) as $key) {
                if ($key === 'internal_notes' && ! $access->allows(auth()->id(), 'M02.notes_view')) {
                    continue;
                }
                $this->form[$key] = $record->$key ?? '';
            }
            $this->specifications = $record->getAttribute('attributes') ?? [];
            $this->conversions = $record->conversions->map(fn ($row) => ['unit_id' => $row->unit_id, 'factor' => $row->factor])->all();
        }
    }

    public function addConversion(): void
    {
        if (count($this->conversions) < 30) {
            $this->conversions[] = ['unit_id' => '', 'factor' => ''];
        }
    }

    public function removeConversion(int $index): void
    {
        unset($this->conversions[$index]);
        $this->conversions = array_values($this->conversions);
    }

    public function addAttribute(): void
    {
        if (count($this->specifications) < 100) {
            $this->specifications[] = ['name' => '', 'value' => '', 'unit_id' => null];
        }
    }

    public function removeAttribute(int $index): void
    {
        unset($this->specifications[$index]);
        $this->specifications = array_values($this->specifications);
    }

    public function removeMedia(int $id): void
    {
        abort_unless($this->productId && ProductMedia::where('product_id', $this->productId)->whereKey($id)->exists(), 404);
        app(ProductMediaActions::class)->remove(auth()->id(), $id);
    }

    public function save()
    {
        $access = app(ProductAccess::class);
        $input = $this->form;
        if (! $access->allows(auth()->id(), 'M02.notes_update') || ! $access->allows(auth()->id(), 'M02.notes_view')) {
            unset($input['internal_notes']);
        }
        try {
            $record = app(ProductActions::class)->saveWithMedia(auth()->id(), $input, $this->productId, $this->version, $this->conversions, $this->specifications, $this->uploads);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[str_starts_with($key, 'attributes') ? 'specifications'.substr($key, 10) : (str_starts_with($key, 'conversions') || str_starts_with($key, 'uploads') || $key === 'file' ? $key : 'form.'.$key)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->productId = $record->id;
        $this->version = $record->version;
        $this->uploads = [];
        session()->flash('status', 'تم حفظ الصنف بنجاح.');

        return $this->redirectRoute('products.show', ['product' => $record->id], navigate: true);
    }

    public function render()
    {
        $access = app(ProductAccess::class);
        $access->authorize(auth()->id(), $this->productId === null ? 'M02.product_create' : 'M02.product_update');

        return view('products.product-form', [
            'categories' => Category::orderBy('name')->get(), 'brands' => Brand::orderBy('name')->get(), 'units' => Unit::orderBy('name')->get(),
            'media' => $this->productId ? Product::findOrFail($this->productId)->media : collect(),
            'canCreate' => $access->allows(auth()->id(), 'M02.product_create'), 'canUpdate' => $access->allows(auth()->id(), 'M02.product_update'),
            'canStatus' => $access->allows(auth()->id(), 'M02.product_status'),
            'canNotes' => $access->allows(auth()->id(), 'M02.notes_view') && $access->allows(auth()->id(), 'M02.notes_update'),
            'canMedia' => $access->allows(auth()->id(), 'M02.media_manage'),
        ])->layout('layouts::app', ['title' => $this->productId ? 'تعديل الصنف' : 'إضافة صنف جديد']);
    }
}

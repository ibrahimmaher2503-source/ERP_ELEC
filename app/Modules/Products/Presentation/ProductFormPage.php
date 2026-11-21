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

    public array $form = ['sku' => '', 'name' => '', 'name_en' => '', 'short_description' => '', 'description' => '', 'kind' => 'bulk', 'tracking' => 'quantity', 'category_id' => '', 'brand_id' => '', 'model' => '', 'base_unit_id' => '', 'purchase_unit_id' => '', 'sales_unit_id' => '', 'barcode' => '', 'active' => true, 'internal_notes' => ''];

    public array $quickSpecs = ['type' => '', 'ampere' => '', 'kilo' => ''];

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
            $this->hydrateQuickSpecsFromAttributes();
        } else {
            $defaultUnit = Unit::where('active', true)->orderByRaw("CASE WHEN dimension = 'count' THEN 0 ELSE 1 END")->orderBy('id')->first();
            if ($defaultUnit) {
                $this->form['base_unit_id'] = (string) $defaultUnit->id;
            }
        }
    }

    private function hydrateQuickSpecsFromAttributes(): void
    {
        $map = [];
        foreach ($this->specifications as $row) {
            if (is_array($row) && isset($row['name'], $row['value'])) {
                $map[trim((string) $row['name'])] = trim((string) $row['value']);
            }
        }
        $this->quickSpecs['type'] = $map['النوع / الأقطاب'] ?? $map['النوع'] ?? $map['عدد الأقطاب'] ?? '';
        $this->quickSpecs['ampere'] = $map['التيار المقنن'] ?? $map['نطاق التيار'] ?? $map['مقطع الموصل'] ?? '';
        $this->quickSpecs['kilo'] = $map['قدرة القطع'] ?? $map['القدرة'] ?? '';
    }

    private function syncQuickSpecsToAttributes(): void
    {
        $mappings = [
            'type' => ['النوع', ['النوع / الأقطاب', 'النوع', 'عدد الأقطاب']],
            'ampere' => ['التيار المقنن', ['التيار المقنن', 'نطاق التيار', 'مقطع الموصل']],
            'kilo' => ['قدرة القطع', ['قدرة القطع', 'القدرة']],
        ];
        foreach ($mappings as $key => [$primaryName, $aliases]) {
            $val = trim((string) ($this->quickSpecs[$key] ?? ''));
            $foundIndex = null;
            foreach ($this->specifications as $idx => $spec) {
                if (in_array(trim((string) ($spec['name'] ?? '')), $aliases, true)) {
                    $foundIndex = $idx;
                    break;
                }
            }
            if ($val !== '') {
                if ($foundIndex !== null) {
                    $this->specifications[$foundIndex]['value'] = $val;
                } elseif (count($this->specifications) < 100) {
                    $this->specifications[] = ['name' => $primaryName, 'value' => $val, 'unit_id' => null];
                }
            }
        }
        $this->specifications = array_values(array_filter(
            $this->specifications,
            fn ($s) => trim((string) ($s['name'] ?? '')) !== '' || trim((string) ($s['value'] ?? '')) !== ''
        ));
    }

    public function updatedForm($value, $key): void
    {
        if ($key === 'kind' && $this->productId === null) {
            $this->form['tracking'] = match ((string) $value) {
                'serialized_equipment' => 'serial',
                'bulk' => 'quantity',
                'service' => 'none',
                default => $this->form['tracking'],
            };
        }
    }

    public function generateSuggestedSku(): void
    {
        if ($this->productId !== null) {
            return;
        }
        $cat = ! empty($this->form['category_id']) ? Category::find($this->form['category_id']) : null;
        $brand = ! empty($this->form['brand_id']) ? Brand::find($this->form['brand_id']) : null;
        $prefixParts = [];
        if ($cat && $cat->code) {
            $cleanCat = preg_replace('/^DEMO(-EG)?-CAT-/', '', $cat->code);
            $prefixParts[] = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($cleanCat)), 0, 4) ?: 'PRD';
        } else {
            $prefixParts[] = 'ELEC';
        }
        if ($brand && $brand->code) {
            $cleanBrand = preg_replace('/^DEMO(-EG)?-BRAND-/', '', $brand->code);
            $bPart = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($cleanBrand)), 0, 4);
            if ($bPart) {
                $prefixParts[] = $bPart;
            }
        }
        $nextNum = Product::max('id') + 1;
        $candidate = implode('-', $prefixParts).'-'.str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        while (Product::where('sku', $candidate)->exists()) {
            $nextNum++;
            $candidate = implode('-', $prefixParts).'-'.str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        }
        $this->form['sku'] = $candidate;
    }

    public function composeSuggestedName(): void
    {
        $cat = ! empty($this->form['category_id']) ? Category::find($this->form['category_id']) : null;
        $brand = ! empty($this->form['brand_id']) ? Brand::find($this->form['brand_id']) : null;
        $parts = array_filter([
            $cat?->name,
            $brand?->name,
            trim((string) ($this->form['model'] ?? '')),
            trim((string) ($this->quickSpecs['type'] ?? '')),
            trim((string) ($this->quickSpecs['ampere'] ?? '')),
            trim((string) ($this->quickSpecs['kilo'] ?? '')),
        ]);
        if ($parts !== []) {
            $this->form['name'] = mb_substr(implode(' · ', $parts), 0, 200);
        }
    }

    public function addPresetAttribute(string $name, string $defaultValue = ''): void
    {
        foreach ($this->specifications as $spec) {
            if (trim((string) ($spec['name'] ?? '')) === $name) {
                return;
            }
        }
        if (count($this->specifications) < 100) {
            $this->specifications[] = ['name' => $name, 'value' => $defaultValue, 'unit_id' => null];
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
        $this->syncQuickSpecsToAttributes();
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

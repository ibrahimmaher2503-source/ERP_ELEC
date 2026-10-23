<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\ProductActions;
use App\Modules\Products\Application\ProductCatalogQuery;
use App\Modules\Products\Application\ProductDataDefinition;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\System\Application\DataOperations\OperationService;
use Livewire\Component;
use Livewire\WithPagination;

class CatalogPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = '';

    public string $brandFilter = '';

    public string $typeFilter = '';

    public string $statusFilter = '';

    public string $unitFilter = '';

    public string $sort = 'sku';

    public int $perPage = 10;

    public array $selected = [];

    public bool $bulkOpen = false;

    public array $changes = [];

    public function boot(): void
    {
        app(ProductAccess::class)->authorize(auth()->id(), 'M02.product_view');
    }

    public function updated($property): void
    {
        if ($property !== 'page' && ! str_starts_with($property, 'selected') && ! str_starts_with($property, 'changes') && $property !== 'bulkOpen') {
            $this->resetPage();
        }
    }

    public function sortBy(string $field): void
    {
        abort_unless(in_array($field, ['sku', 'name', 'created_at'], true), 422);
        $this->sort = $field;
        $this->resetPage();
    }

    public function changeStatus(int $id, int $version): void
    {
        app(ProductActions::class)->changeStatus(auth()->id(), $id, $version);
        session()->flash('status', 'تم تحديث حالة الصنف.');
    }

    private function filters(): array
    {
        return collect(['search', 'categoryFilter', 'brandFilter', 'typeFilter', 'statusFilter', 'unitFilter'])->mapWithKeys(fn ($key) => [$key => $this->$key])->all();
    }

    private function catalogQuery()
    {
        return app(ProductCatalogQuery::class)->query(auth()->id(), $this->filters());
    }

    public function selectVisible(): void
    {
        $query = $this->catalogQuery()->orderBy(in_array($this->sort, ['sku', 'name', 'created_at'], true) ? $this->sort : 'sku')->orderBy('id');
        $ids = $query->forPage($this->getPage(), in_array($this->perPage, [5, 10, 25, 50], true) ? $this->perPage : 10)->pluck('id')->all();
        if ($ids !== []) {
            $allSelected = count(array_intersect(array_map('intval', $this->selected), $ids)) === count($ids);
            $selection = $allSelected ? array_values(array_diff(array_map('intval', $this->selected), $ids)) : array_values(array_unique(array_merge($this->selected, $ids)));
            $this->selected = $selection === [] ? [] : app(OperationService::class)->ids($selection);
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function openBulk(): void
    {
        app(ProductDataDefinition::class)->authorize(auth()->id(), 'bulk');
        app(OperationService::class)->ids($this->selected);
        $this->changes = collect(app(ProductDataDefinition::class)->bulkFields())->map(fn () => ['mode' => 'keep', 'value' => ''])->all();
        $this->bulkOpen = true;
    }

    public function previewBulk()
    {
        $run = app(OperationService::class)->previewBulk(auth()->id(), 'products', $this->selected, $this->changes);
        $this->bulkOpen = false;

        return $this->redirectRoute('operations.show', ['runId' => $run->id], navigate: true);
    }

    public function startExport(string $mode)
    {
        $path = app(OperationService::class)->export(auth()->id(), 'products', $mode, $this->filters(), $this->selected);

        return response()->download($path, 'products-'.$mode.'.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
    }

    public function render()
    {
        $query = $this->catalogQuery()->with(['category', 'brand', 'baseUnit', 'media']);
        $access = app(ProductAccess::class);

        return view('products.catalog', [
            'products' => $query->orderBy(in_array($this->sort, ['sku', 'name', 'created_at'], true) ? $this->sort : 'sku')->paginate(in_array($this->perPage, [5, 10, 25, 50], true) ? $this->perPage : 10),
            'categories' => Category::withCount('products')->orderBy('name')->get(), 'brands' => Brand::orderBy('name')->get(), 'units' => Unit::orderBy('name')->get(),
            'stats' => ['total' => Product::count(), 'active' => Product::where('active', true)->count(), 'categories' => Category::count()],
            'canCreate' => $access->allows(auth()->id(), 'M02.product_create'), 'canUpdate' => $access->allows(auth()->id(), 'M02.product_update'),
            'canStatus' => $access->allows(auth()->id(), 'M02.product_status'), 'canMedia' => true,
            'canBulk' => $access->allows(auth()->id(), 'M02.product_bulk_update'), 'canImport' => $access->allows(auth()->id(), 'M02.product_import'), 'canExport' => $access->allows(auth()->id(), 'M02.product_export'), 'bulkFields' => app(ProductDataDefinition::class)->bulkFields(),
        ])->layout('layouts::app', ['title' => 'الأصناف والمنتجات']);
    }
}

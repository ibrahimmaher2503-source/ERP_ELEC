<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\System\Application\DataOperations\OperationService;
use Livewire\Component;
use Livewire\WithPagination;

abstract class PartyCatalogPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $entity_type = '';

    public string $segment = '';

    public string $city = '';

    public string $country = '';

    public string $sort = 'code';

    public string $direction = 'asc';

    public int $perPage = 10;

    public array $selected = [];

    public bool $bulkOpen = false;

    public array $changes = [];

    protected const ENTITY = 'customers';

    public string $supplier_type = '';

    public string $category_id = '';

    protected function profile(): PartyPresentation
    {
        return new PartyPresentation(static::ENTITY);
    }

    public function boot(): void
    {
        $this->profile()->authorize(auth()->id(), 'view');
    }

    public function updated($property): void
    {
        if ($property !== 'page' && ! str_starts_with($property, 'selected') && ! str_starts_with($property, 'changes') && $property !== 'bulkOpen') {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'entity_type', 'segment', 'city', 'country', 'supplier_type', 'category_id');
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        abort_unless(in_array($field, ['code', 'name', 'created_at'], true), 422);
        $this->direction = $this->sort === $field && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $field;
        $this->resetPage();
    }

    public function changeStatus(int $id, int $version, string $status): void
    {
        $this->profile()->actions()->changeStatus(auth()->id(), $id, $version, $status);
        session()->flash('status', 'تم تحديث حالة '.$this->profile()->singular.'.');
    }

    private function filters(): array
    {
        return collect(['search', 'status', 'entity_type', 'segment', 'city', 'country', 'supplier_type', 'category_id'])->mapWithKeys(fn ($key) => [$key => $this->$key])->all();
    }

    private function catalogQuery()
    {
        return $this->profile()->query(auth()->id(), $this->filters());
    }

    public function selectVisible(): void
    {
        $query = $this->orderedQuery();
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

    private function orderedQuery()
    {
        return $this->catalogQuery()->orderBy(in_array($this->sort, ['code', 'name', 'created_at'], true) ? $this->sort : 'code', $this->direction === 'desc' ? 'desc' : 'asc')->orderBy('id');
    }

    public function openBulk(): void
    {
        $this->profile()->definition()->authorize(auth()->id(), 'bulk');
        app(OperationService::class)->ids($this->selected);
        $this->changes = collect($this->profile()->definition()->bulkFields())->map(fn () => ['mode' => 'keep', 'value' => ''])->all();
        $this->bulkOpen = true;
    }

    public function previewBulk()
    {
        $run = app(OperationService::class)->previewBulk(auth()->id(), static::ENTITY, $this->selected, $this->changes);
        $this->bulkOpen = false;

        return $this->redirectRoute('operations.show', ['runId' => $run->id], navigate: true);
    }

    public function startExport(string $mode)
    {
        $path = app(OperationService::class)->export(auth()->id(), static::ENTITY, $mode, $this->filters(), $this->selected);

        return response()->download($path, static::ENTITY.'-'.$mode.'.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
    }

    public function render()
    {
        $ui = $this->profile();
        $model = $ui->model;
        $can = fn ($key) => $ui->allows(auth()->id(), $key);
        $query = $this->orderedQuery()->with(['contacts' => fn ($q) => $q->where('is_primary', true)]);
        if ($ui->supplier) {
            $query->with('category');
        }

        return view('parties.catalog', [
            'ui' => $ui, 'records' => $query->paginate(in_array($this->perPage, [5, 10, 25, 50], true) ? $this->perPage : 10),
            'stats' => ['total' => $model::count(), 'active' => $model::where('status', 'active')->count(), 'inactive' => $model::where('status', 'inactive')->count(), 'archived' => $model::where('status', 'archived')->count(), 'new' => $model::where('created_at', '>=', now('Africa/Cairo')->startOfMonth()->utc())->count()],
            'segments' => $ui->supplier ? collect() : $model::whereNotNull('segment')->distinct()->orderBy('segment')->pluck('segment'),
            'categories' => $ui->supplier ? SupplierCategory::withCount('suppliers')->orderBy('name')->get() : collect(),
            'cities' => $model::whereNotNull('city')->distinct()->orderBy('city')->pluck('city'), 'countries' => $model::whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
            'canCreate' => $can('create'), 'canUpdate' => $can('update'), 'canStatus' => $can('status'), 'canBulk' => $can('bulk_update'), 'canImport' => $can('import'), 'canExport' => $can('export'), 'canCategories' => $ui->supplier && $can('category_manage'), 'bulkFields' => $ui->definition()->bulkFields(),
        ])->layout('layouts::app', ['title' => $ui->plural]);
    }
}

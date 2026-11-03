<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\ProductMasters;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Unit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class MastersPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $kind = 'category';

    public string $search = '';

    public string $statusFilter = '';

    public bool $editing = false;

    public array $form = ['code' => '', 'name' => '', 'parent_id' => '', 'dimension' => 'count', 'active' => true];

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public ?int $selectedId = null;

    public function mount(string $kind): void
    {
        abort_unless(in_array($kind, ['category', 'brand', 'unit'], true), 404);
        $this->kind = $kind;
    }

    public function booted(): void
    {
        $access = app(ProductAccess::class);
        $actorId = (int) Auth::id();
        abort_unless($access->allows($actorId, $this->managePermission()) || $access->allows($actorId, 'M02.product_view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->reset('form', 'editingId', 'version');
        $this->form = ['code' => '', 'name' => '', 'parent_id' => '', 'dimension' => 'count', 'active' => true];
        $this->resetValidation();
        $this->editing = true;
    }

    public function showDetails(int $id): void
    {
        $model = $this->modelClass();
        abort_unless($model::query()->whereKey($id)->exists(), 404);
        $this->selectedId = $id;
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $model = $this->modelClass();
        $record = $model::query()->findOrFail($id);
        $this->selectedId = $id;
        $this->editingId = $id;
        $this->version = (int) $record->version;
        $this->form = [
            'code' => $record->code,
            'name' => $record->name,
            'parent_id' => $this->kind === 'category' ? (string) ($record->parent_id ?? '') : '',
            'dimension' => $this->kind === 'unit' ? $record->dimension : 'count',
            'active' => (bool) $record->active,
        ];
        $this->resetValidation();
        $this->editing = true;
    }

    public function save(): void
    {
        $this->authorizeManage();
        abort_unless(($this->editingId === null) === ($this->version === null), 422);

        try {
            $input = match ($this->kind) {
                'category' => ['code' => $this->form['code'] ?? null, 'name' => $this->form['name'] ?? null, 'parent_id' => ($this->form['parent_id'] ?? '') === '' ? null : $this->form['parent_id'], 'active' => $this->form['active'] ?? null],
                'brand' => ['code' => $this->form['code'] ?? null, 'name' => $this->form['name'] ?? null, 'active' => $this->form['active'] ?? null],
                'unit' => ['code' => $this->form['code'] ?? null, 'name' => $this->form['name'] ?? null, 'dimension' => $this->form['dimension'] ?? null, 'active' => $this->form['active'] ?? null],
            };
            $record = app(ProductMasters::class)->save((int) Auth::id(), $this->kind, $input, $this->editingId, $this->version);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[str_starts_with($key, 'form.') ? $key : 'form.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        $this->resetValidation();
        $this->selectedId = (int) $record->getKey();
        $this->editingId = null;
        $this->version = null;
        $this->editing = false;
        session()->flash('status', 'تم حفظ البيانات بنجاح.');
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->editingId = null;
        $this->version = null;
        $this->resetValidation();
    }

    private function authorizeManage(): void
    {
        app(ProductAccess::class)->authorize((int) Auth::id(), $this->managePermission());
    }

    private function managePermission(): string
    {
        return 'M02.'.$this->kind.'_manage';
    }

    private function modelClass(): string
    {
        return match ($this->kind) {
            'category' => Category::class,
            'brand' => Brand::class,
            'unit' => Unit::class,
        };
    }

    private function parentChoices()
    {
        if ($this->kind !== 'category' || ! $this->editing) {
            return collect();
        }

        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'parent_id', 'active']);
        $blocked = collect($this->editingId === null ? [] : [$this->editingId]);
        $frontier = $blocked->all();
        while ($frontier !== []) {
            $frontier = $categories->whereIn('parent_id', $frontier)->pluck('id')->diff($blocked)->all();
            $blocked = $blocked->merge($frontier)->unique()->values();
        }

        $currentParentId = (int) ($this->form['parent_id'] ?? 0);

        return $categories->whereNotIn('id', $blocked->all())
            ->filter(fn ($category) => $category->active || (int) $category->id === $currentParentId)
            ->values();
    }

    public function render()
    {
        $model = $this->modelClass();
        $query = $model::query();
        if ($this->kind === 'category') {
            $query->with('parent')->withCount('products');
        }

        $search = mb_substr(trim($this->search), 0, 200);
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'ilike', '%'.$search.'%')->orWhere('code', 'ilike', '%'.$search.'%');
            });
        }
        if (in_array($this->statusFilter, ['active', 'inactive'], true)) {
            $query->where('active', $this->statusFilter === 'active');
        }

        $statsQuery = $model::query();
        $stats = [
            'total' => (clone $statsQuery)->count(),
            'active' => (clone $statsQuery)->where('active', true)->count(),
            'inactive' => (clone $statsQuery)->where('active', false)->count(),
        ];
        $selected = null;
        if ($this->selectedId) {
            $selectedQuery = $model::query();
            if ($this->kind === 'category') {
                $selectedQuery->with('parent')->withCount('products');
            }
            $selected = $selectedQuery->find($this->selectedId);
        }

        return view('products.masters', [
            'records' => $query->orderBy('name')->orderBy('id')->paginate(10),
            'stats' => $stats,
            'selected' => $selected,
            'canManage' => app(ProductAccess::class)->allows((int) Auth::id(), $this->managePermission()),
            'parentChoices' => $this->parentChoices(),
            'kindLabel' => ['category' => 'الفئات', 'brand' => 'العلامات التجارية', 'unit' => 'الوحدات'][$this->kind],
            'dimensions' => ['length' => 'طول', 'mass' => 'كتلة', 'volume' => 'حجم', 'count' => 'عدد', 'time' => 'زمن'],
        ])->layout('layouts::app', ['title' => ['category' => 'فئات المنتجات', 'brand' => 'العلامات التجارية', 'unit' => 'وحدات القياس'][$this->kind]]);
    }
}

<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\Parties\Application\SupplierAccess;
use App\Modules\Parties\Application\SupplierCategories;
use App\Modules\Parties\Domain\SupplierCategory;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SupplierCategoriesPage extends Component
{
    public string $search = '';

    #[Locked]
    public ?int $categoryId = null;

    public bool $open = false;

    public array $form = ['code' => '', 'name' => '', 'active' => true];

    public function boot(): void
    {
        app(SupplierAccess::class)->authorize(auth()->id(), 'M03.supplier_category_manage');
    }

    public function edit(?int $id = null): void
    {
        $this->resetValidation();
        $this->reset('form');
        $this->categoryId = $id;
        if ($id) {
            $this->form = SupplierCategory::findOrFail($id)->only(['code', 'name', 'active']);
        }$this->open = true;
    }

    public function save(): void
    {
        app(SupplierCategories::class)->save(auth()->id(), $this->form, $this->categoryId);
        $this->open = false;
        session()->flash('status', 'تم حفظ تصنيف المورد.');
    }

    public function render()
    {
        return view('parties.categories', ['categories' => SupplierCategory::withCount('suppliers')->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%')->orWhere('code', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%')))->orderBy('name')->get()])->layout('layouts::app', ['title' => 'تصنيفات الموردين']);
    }
}

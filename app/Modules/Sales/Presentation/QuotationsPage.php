<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\QuotationExportDefinition;
use App\Modules\Sales\Application\QuotationQuery;
use App\Modules\Sales\Domain\Quotation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class QuotationsPage extends Component
{
    use WithPagination;

    public string $search = '';

    public string $customer = '';

    public string $project = '';

    public string $status = '';

    public string $conversion = '';

    public string $representative = '';

    public string $from = '';

    public string $to = '';

    public string $sort = 'quotation_date';

    public string $direction = 'desc';

    public int $perPage = 10;

    public function boot(): void
    {
        app(QuotationAccess::class)->authorize(auth()->id());
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'customer', 'project', 'status', 'conversion', 'representative', 'from', 'to');
        $this->resetPage();
    }

    public function sortBy(string $key): void
    {
        abort_unless(in_array($key, ['quotation_date', 'number', 'created_at'], true), 422);
        $this->direction = $this->sort === $key && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $key;
        $this->resetPage();
    }

    private function filters(): array
    {
        return $this->only(['search', 'customer', 'project', 'status', 'conversion', 'representative', 'from', 'to']);
    }

    public function export()
    {
        $path = app(QuotationExportDefinition::class)->export(auth()->id(), $this->filters());

        return response()->download($path, 'quotations.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
    }

    public function render()
    {
        $this->resetValidation(array_keys($this->filters()));
        try {
            Validator::make($this->only(['from', 'to']), [
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d'.($this->from !== '' ? '|after_or_equal:from' : ''),
            ])->validate();
            $q = app(QuotationQuery::class)->query(auth()->id(), $this->filters());
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $q = null;
        }
        $sort = in_array($this->sort, ['quotation_date', 'number', 'created_at'], true) ? $this->sort : 'quotation_date';

        return view('sales.quotations.index', [
            'filtersValid' => $q !== null,
            'records' => $q?->orderBy($sort, $this->direction === 'asc' ? 'asc' : 'desc')->orderByDesc('id')->paginate(in_array($this->perPage, [10, 25, 50], true) ? $this->perPage : 10),
            'stats' => ['total' => Quotation::count(), 'pending_approval' => Quotation::where('status', 'pending_approval')->count(), 'accepted' => Quotation::where('status', 'accepted')->count(), 'rejected' => Quotation::where('status', 'rejected')->count(), 'converted' => null],
            'customerOptions' => Quotation::select('customer_id', 'customer_snapshot')->get()->unique('customer_id'),
            'can' => fn ($a) => app(QuotationAccess::class)->allows(auth()->id(), $a),
        ])->layout('layouts::app', ['title' => 'عروض الأسعار']);
    }
}

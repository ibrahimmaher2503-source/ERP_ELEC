<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Parties\Application\CustomerAccess;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrderCatalog;
use App\Modules\Sales\Application\SalesOrderExportDefinition;
use App\Modules\Sales\Application\SalesOrderProgress;
use App\Modules\Sales\Application\SalesOrderQuery;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SalesOrdersPage extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'order_date')]
    public string $sort = 'order_date';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    #[Url(except: 10)]
    public int $perPage = 10;

    public function boot(): void
    {
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'view');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'branch', 'source', 'from', 'to');
        $this->resetPage();
    }

    public function toggleStatus(string $value): void
    {
        $this->status = $this->status === $value ? '' : $value;
        $this->resetPage();
    }

    public function sortBy(string $key): void
    {
        abort_unless(in_array($key, ['order_date', 'number', 'created_at'], true), 422);
        $this->direction = $this->sort === $key && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $key;
        $this->resetPage();
    }

    private function filters(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'branch' => $this->branch,
            'source' => $this->source,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }

    public function export()
    {
        $access = app(SalesOrderAccess::class);
        $access->authorize(auth()->id(), 'export');
        $path = app(SalesOrderExportDefinition::class)->export(auth()->id(), $this->filters());

        return response()->download($path, 'sales-orders.xlsx', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(SalesOrderAccess::class);
        $this->resetValidation(array_keys($this->filters()));
        try {
            Validator::make($this->only(['from', 'to']), [
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d'.($this->from !== '' ? '|after_or_equal:from' : ''),
            ])->validate();
            $query = app(SalesOrderQuery::class)->query($actor, $this->filters());
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $query = null;
        }
        $sort = in_array($this->sort, ['order_date', 'number', 'created_at'], true) ? $this->sort : 'order_date';
        $records = $query
            ?->orderBy($sort, $this->direction === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate(in_array($this->perPage, [10, 25, 50], true) ? $this->perPage : 10);

        $progressMap = [];
        if ($records) {
            $progressReader = app(SalesOrderProgress::class);
            foreach ($records->items() as $record) {
                if ($record->status === 'approved') {
                    $progressMap[$record->id] = $progressReader->read($actor, $record);
                }
            }
        }

        $groupedCounts = app(SalesOrderQuery::class)->query($actor)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = [
            'total' => (int) $groupedCounts->sum(),
            'draft' => (int) ($groupedCounts['draft'] ?? 0),
            'pending_approval' => (int) ($groupedCounts['pending_approval'] ?? 0),
            'approved' => (int) ($groupedCounts['approved'] ?? 0),
            'cancelled' => (int) ($groupedCounts['cancelled'] ?? 0),
        ];

        $branchOptions = $access->allows($actor, 'view')
            ? app(SalesOrderCatalog::class)->branches($actor, 'view')
            : [];

        return view('sales.orders.index', [
            'filtersValid' => $query !== null,
            'records' => $records,
            'progressMap' => $progressMap,
            'stats' => $stats,
            'branches' => $branchOptions,
            'canViewCustomer' => app(CustomerAccess::class)->allows($actor, 'M03.customer_view'),
            'canViewReturns' => $access->allows($actor, 'return_view'),
            'can' => fn (string $action, ?int $branchId = null): bool => $access->allows($actor, $action, $branchId),
        ])->layout('layouts::app', ['title' => 'أوامر البيع']);
    }
}

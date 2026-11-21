<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Parties\Application\SupplierAccess;
use App\Modules\Purchasing\Application\PurchaseOrderProjection;
use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class OrdersPage extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $receiving = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $issued = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'id')]
    public string $sort = 'id';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    #[Url(except: 15)]
    public int $perPage = 15;

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'receiving', 'branch', 'issued', 'from', 'to');
        $this->resetPage();
    }

    public function toggleStatus(string $value): void
    {
        $this->status = $this->status === $value ? '' : $value;
        $this->resetPage();
    }

    public function sortBy(string $key): void
    {
        abort_unless(in_array($key, ['id', 'order_date', 'expected_date', 'net_amount'], true), 422);
        $this->direction = $this->sort === $key && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $key;
        $this->resetPage();
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(PurchasingAccess::class);
        $branches = $access->branches($actor, 'order_view');
        $ids = array_column($branches, 'id');
        abort_unless($ids, 403);

        $this->resetValidation(['from', 'to', 'branch']);
        $filtersValid = true;
        try {
            Validator::make($this->only(['from', 'to', 'branch']), [
                'from' => 'nullable|date_format:Y-m-d',
                'to' => 'nullable|date_format:Y-m-d'.($this->from !== '' ? '|after_or_equal:from' : ''),
                'branch' => 'nullable|integer|min:1',
            ])->validate();
        } catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $filtersValid = false;
        }

        $projection = app(PurchaseOrderProjection::class);
        $q = $projection->query()->whereIn('branch_id', $ids);
        if ($filtersValid) {
            $projection->filter($q, $this->status, $this->search, $this->receiving, $this->branch, $this->from, $this->to, $this->issued);
        }

        $sort = in_array($this->sort, ['id', 'order_date', 'expected_date', 'net_amount'], true) ? $this->sort : 'id';
        $dir = $this->direction === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($this->perPage, [15, 30, 50], true) ? $this->perPage : 15;

        $rows = $filtersValid
            ? $q->orderBy($sort, $dir)->orderByDesc('id')->paginate($perPage)
            : null;

        $grouped = DB::table('purchasing_orders')
            ->whereIn('branch_id', $ids)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = [
            'total' => (int) $grouped->sum(),
            'draft' => (int) ($grouped['draft'] ?? 0),
            'submitted' => (int) ($grouped['submitted'] ?? 0),
            'approved' => (int) ($grouped['approved'] ?? 0),
            'rejected' => (int) ($grouped['rejected'] ?? 0),
        ];

        $branchNames = collect($branches)->pluck('name', 'id')->all();
        $exportBranchIds = array_intersect($ids, array_column($access->branches($actor, 'export'), 'id'));
        $receiptBranches = array_column($access->branches($actor, 'receipt_create'), 'id');

        return view('purchasing.orders', [
            'filtersValid' => $filtersValid,
            'rows' => $rows,
            'stats' => $stats,
            'branches' => $branches,
            'branchNames' => $branchNames,
            'todayCairo' => now('Africa/Cairo')->toDateString(),
            'canCreate' => $access->branches($actor, 'order_create') !== [],
            'canExport' => $exportBranchIds !== [],
            'receiptBranches' => $receiptBranches,
            'canViewSuppliers' => app(SupplierAccess::class)->allows($actor, 'M03.supplier_view'),
            'canViewReturns' => $access->branches($actor, 'return_view') !== [],
        ])->layout('layouts::app', ['title' => 'أوامر الشراء']);
    }
}

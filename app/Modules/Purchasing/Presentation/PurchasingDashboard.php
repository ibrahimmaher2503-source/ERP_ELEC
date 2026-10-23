<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PurchasingDashboard extends Component
{
    #[Locked]
    public bool $reports = false;

    public string $from = '';

    public string $to = '';

    public string $branch = '';

    public string $supplier = '';

    public string $status = '';

    public function mount(bool $reports = false): void
    {
        $this->reports = $reports;
    }

    private function query(string $table, string $permission, string $date)
    {
        $access = app(PurchasingAccess::class);
        $ids = array_column($access->branches(auth()->id(), $permission), 'id');
        $screenIds = array_column($access->branches(auth()->id(), $this->reports ? 'report_view' : 'dashboard_view'), 'id');

        return DB::table($table)->whereIn('branch_id', array_intersect($ids, $screenIds))
            ->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch))
            ->when($this->from !== '', fn ($q) => $q->where($date, '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->where($date, '<=', $this->to));
    }

    public function render()
    {
        Validator::make(['from' => $this->from, 'to' => $this->to, 'branch' => $this->branch, 'supplier' => $this->supplier], ['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'branch' => 'nullable|integer|min:1', 'supplier' => 'nullable|string|max:200|not_regex:/\x00/'])->validate();
        $access = app(PurchasingAccess::class);
        $branches = $access->branches(auth()->id(), $this->reports ? 'report_view' : 'dashboard_view');
        abort_unless($branches, 403);
        $orders = $this->query('purchasing_orders', 'order_view', 'order_date')->when($this->supplier !== '', fn ($q) => $q->where('supplier_name', 'ilike', '%'.$this->supplier.'%'))->when($this->status !== '', fn ($q) => $q->where('status', $this->status));
        $offers = $this->query('purchasing_offers', 'offer_view', 'document_date')->when($this->supplier !== '', fn ($q) => $q->where('supplier_name', 'ilike', '%'.$this->supplier.'%'));
        $receipts = $this->query('purchasing_receipts', 'receipt_view', 'receipt_date');
        $warehouseIds = array_column(app(OrganizationApi::class)->inventoryScopes(auth()->id()), 'warehouse_id');
        $receipts->whereIn('warehouse_id', $warehouseIds);
        $stats = [
            'requisitions' => $this->query('purchase_requisitions', 'requisition_view', 'request_date')->whereIn('status', ['draft', 'submitted'])->count(),
            'rfqs' => $this->query('purchasing_rfqs', 'rfq_view', 'request_date')->where('status', 'issued')->count(),
            'pending' => (clone $orders)->where('status', 'submitted')->count(),
            'approved' => (clone $orders)->where('status', 'approved')->count(),
            'offers' => $offers->count(),
            'receipts' => $receipts->count(),
        ];
        $money = (clone $orders)->selectRaw('currency, sum(net_amount)::text as net, count(*) as total')->groupBy('currency')->orderBy('currency')->get();
        $suppliers = (clone $orders)->selectRaw('supplier_name,currency,sum(net_amount)::text as net,count(*) as total')->groupBy('supplier_name', 'currency')->orderByDesc('total')->limit(8)->get();
        $monthly = (clone $orders)->selectRaw("to_char(order_date,'YYYY-MM') as month, count(*) as total")->groupByRaw("to_char(order_date,'YYYY-MM')")->orderBy('month')->get();
        $latest = (clone $orders)->orderByDesc('id')->limit(8)->get();

        return view('purchasing.dashboard', compact('branches', 'stats', 'money', 'suppliers', 'monthly', 'latest'))->layout('layouts::app', ['title' => $this->reports ? 'تقارير المشتريات' : 'لوحة المشتريات']);
    }
}

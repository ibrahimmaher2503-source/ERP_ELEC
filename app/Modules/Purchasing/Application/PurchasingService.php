<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PurchasingService implements PurchasingApi
{
    public function projectSummary(int $actor, int $branch, array $links): array
    {
        app(PurchasingAccess::class)->authorize($actor, 'order_view', $branch);
        app(PurchasingAccess::class)->authorize($actor, 'receipt_view', $branch);
        $orderIds = array_map('intval', $links['purchase_order'] ?? []);
        foreach ($links['purchase_receipt'] ?? [] as $receipt) {
            $r = $this->projectDocument($actor, 'purchase_receipt', (int) $receipt);
            abort_unless($r['branchId'] === $branch, 422);
            $orderIds[] = (int) DB::table('purchasing_receipts')->where('id', $receipt)->value('order_id');
        }
        $orderIds = array_values(array_unique($orderIds));
        $committed = '0.00';
        $received = '0.00';
        $pending = 0;
        $rows = [];
        foreach (DB::table('purchasing_orders')->whereIn('id', $orderIds)->get() as $order) {
            abort_unless((int) $order->branch_id === $branch, 422);
            $issue = DB::table('purchasing_order_issues')->where('order_id', $order->id)->first();
            if (! $issue || ! in_array($order->status, ['approved', 'cancelled'], true)) {
                $pending++;

                continue;
            }
            $tax = collect(json_decode($issue->tax_snapshot, true, 512, JSON_THROW_ON_ERROR)['lines'])->keyBy('product_id');
            $open = '0.00';
            $orderReceived = '0.00';
            foreach (DB::table('purchasing_order_lines')->where('order_id', $order->id)->get() as $line) {
                $posted = DB::table('purchasing_receipt_postings as p')->join('purchasing_receipt_lines as l', 'l.id', '=', 'p.line_id')->where('l.order_line_id', $line->id)->selectRaw('coalesce(sum(l.quantity),0) quantity,coalesce(sum(p.amount),0) amount')->first();
                $frozen = $tax->get($line->product_id);
                $value = $frozen['purchase_recoverable'] ? $frozen['net'] : bcadd($frozen['net'], $frozen['tax'], 2);
                $open = bcadd($open, $order->status === 'cancelled' ? '0.00' : bcsub($value, $posted->amount, 2), 2);
                $orderReceived = bcadd($orderReceived, $posted->amount, 2);
            }
            $committed = bcadd($committed, $open, 2);
            $received = bcadd($received, $orderReceived, 2);
            $rows[] = ['id' => $order->id, 'number' => $issue->official_number, 'status' => $order->status, 'open_commitment' => $open, 'received_value' => $orderReceived];
        }
        $receiptsPending = DB::table('purchasing_receipts')->whereIn('order_id', $orderIds)->where('status', '<>', 'posted')->count();

        return ['order_ids' => $orderIds, 'open_commitments' => $committed, 'received_value' => $received, 'unapproved_documents' => $pending + $receiptsPending, 'orders' => $rows];
    }

    public function prepareReceiptPosting(int $actor, int $receipt): array
    {
        return app(PurchaseReceiptPosting::class)->prepare($actor, $receipt);
    }

    public function completeReceiptPosting(int $actor, int $receipt, int $version, array $stock, array $journal): void
    {
        app(PurchaseReceiptPosting::class)->complete($actor, $receipt, $version, $stock, $journal);
    }

    public function projectDocument(int $actor, string $type, int $id): array
    {
        [$table,$permission,$date] = match ($type) {
            'requisition' => ['purchase_requisitions', 'requisition_view', 'request_date'],'rfq' => ['purchasing_rfqs', 'rfq_view', 'request_date'],'supplier_offer' => ['purchasing_offers', 'offer_view', 'document_date'],'purchase_order' => ['purchasing_orders', 'order_view', 'order_date'],'purchase_receipt' => ['purchasing_receipts', 'receipt_view', 'receipt_date'],default => abort(422)
        };
        $row = DB::table($table)->where('id', $id)->firstOrFail();
        app(PurchasingAccess::class)->authorize($actor, $permission, $row->branch_id);
        if ($type === 'purchase_receipt') {
            abort_unless(in_array($row->warehouse_id, array_column(app(OrganizationApi::class)->inventoryScopes($actor), 'warehouse_id'), true), 403);
        }

        return ['id' => $row->id, 'branchId' => $row->branch_id, 'status' => $row->status, 'date' => $row->$date, 'number' => $row->official_number ?? null, 'orderId' => $type === 'purchase_receipt' ? (int) $row->order_id : null];
    }

    public function authorizeReturn(int $actor, int $receipt, int $line): array
    {
        return app(PurchaseReturns::class)->authorizeMovement($actor, $receipt, $line);
    }

    public function supplierInvoiceSource(int $actor, array $receiptLineIds): array
    {
        abort_unless(DB::transactionLevel() > 0 && $receiptLineIds !== [], 422);
        $ids = array_values(array_unique(array_map('intval', $receiptLineIds)));
        $rows = DB::table('purchasing_receipt_lines as rl')
            ->join('purchasing_receipts as r', 'r.id', '=', 'rl.receipt_id')
            ->join('purchasing_receipt_postings as rp', 'rp.line_id', '=', 'rl.id')
            ->join('purchasing_order_lines as ol', 'ol.id', '=', 'rl.order_line_id')
            ->join('purchasing_orders as o', 'o.id', '=', 'ol.order_id')
            ->join('purchasing_order_issues as oi', 'oi.order_id', '=', 'o.id')
            ->whereIn('rl.id', $ids)->orderBy('rl.id')->lockForUpdate()
            ->get(['rl.id as receipt_line_id', 'rl.quantity as received_quantity', 'r.id as receipt_id', 'r.status as receipt_status',
                'r.branch_id', 'r.receipt_date', 'o.id as order_id', 'o.supplier_id', 'o.supplier_name', 'ol.id as order_line_id',
                'ol.product_id', 'ol.product_name', 'ol.quantity as ordered_quantity', 'rp.amount as grni_amount', 'oi.tax_snapshot']);
        abort_unless($rows->count() === count($ids), 409, 'يلزم اختيار بنود استلام مرحّلة فعلياً.');
        $branch = (int) $rows->first()->branch_id;
        app(PurchasingAccess::class)->authorize($actor, 'receipt_view', $branch);
        abort_unless($rows->every(fn ($row) => $row->receipt_status === 'posted' && (int) $row->branch_id === $branch
            && (int) $row->supplier_id === (int) $rows->first()->supplier_id), 409, 'مصادر المطابقة يجب أن تكون مرحّلة ولنفس المورد والفرع.');

        return ['branch_id' => $branch, 'supplier_id' => (int) $rows->first()->supplier_id,
            'supplier_name' => $rows->first()->supplier_name, 'lines' => $rows->map(fn ($row) => (array) $row)->all()];
    }

    public function grniValue(int $actor, array $branchIds, ?string $to = null): string
    {
        abort_unless($actor > 0 && $branchIds !== [], 403);
        $to ??= now('Africa/Cairo')->toDateString();
        Validator::make(['to' => $to], ['to' => 'required|date_format:Y-m-d'])->validate();
        foreach ($branchIds as $branch) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.report_view', (int) $branch));
        }

        return (string) DB::table('purchasing_receipt_postings as p')->join('purchasing_receipts as r', 'r.id', '=', 'p.receipt_id')
            ->whereIn('r.branch_id', array_map('intval', $branchIds))->where('r.status', 'posted')->where('r.receipt_date', '<=', $to)->sum('p.amount');
    }
}

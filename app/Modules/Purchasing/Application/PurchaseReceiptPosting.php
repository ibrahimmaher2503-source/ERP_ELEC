<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;

final class PurchaseReceiptPosting
{
    public function prepare(int $actor, int $id): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(SupplierOffers::class)->lock();
        $receipt = DB::table('purchasing_receipts')->lockForUpdate()->find($id);
        abort_unless($receipt, 404);
        app(PurchasingAccess::class)->authorize($actor, 'receipt_post', $receipt->branch_id);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M06.receipt_post', $receipt->branch_id, $receipt->warehouse_id));
        $helper = app(SupplierOffers::class);
        if ($receipt->status === 'posted') {
            return ['receipt' => (array) $receipt, 'already_posted' => true];
        }
        if ($receipt->status !== 'integration_pending' || $receipt->created_by === $actor) {
            $helper->fail('يلزم استلام مثبت واعتماد ترحيل من شخص مستقل عن مُعد الاستلام.');
        }
        $order = app(PurchaseOrders::class)->record($actor, $receipt->order_id);
        $issue = DB::table('purchasing_order_issues')->where('order_id', $order->id)->first();
        if ($order->status !== 'approved' || ! $issue || $receipt->receipt_date < $order->order_date) {
            $helper->fail('يلزم أمر شراء صادر رسميًا وتاريخ استلام لا يسبق الأمر.');
        }
        $tax = json_decode($issue->tax_snapshot, true, 512, JSON_THROW_ON_ERROR);
        $commercial = collect($tax['lines'])->keyBy('product_id');
        $rows = [];
        $total = '0.00';
        $lines = DB::table('purchasing_receipt_lines')->where('receipt_id', $id)->orderBy('order_line_id')->get();
        foreach ($lines as $line) {
            $ordered = DB::table('purchasing_order_lines')->where('order_id', $order->id)->lockForUpdate()->find($line->order_line_id);
            $issued = $ordered ? $commercial->get($ordered->product_id) : null;
            if (! $issued) {
                $helper->fail('بند الاستلام لا يطابق لقطة أمر الشراء الصادرة.');
            }
            $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $ordered->product_id))->product;
            if (! $product['active'] || $product['base_unit_id'] !== $ordered->unit_id) {
                $helper->fail('الصنف أو وحدته الأساسية لا يطابقان مصدر الاستلام المعتمد.');
            }
            $prior = DB::table('purchasing_receipt_postings as p')->join('purchasing_receipt_lines as l', 'l.id', '=', 'p.line_id')
                ->where('l.order_line_id', $ordered->id)->selectRaw('coalesce(sum(l.quantity),0) as quantity,coalesce(sum(p.amount),0) as amount')->first();
            $cumulativeQty = bcadd($prior->quantity, $line->quantity, 8);
            if (bccomp($cumulativeQty, $ordered->quantity, 8) > 0) {
                $helper->fail('الاستلام يتجاوز الكمية المتبقية للأمر.');
            }
            // Final receipt exhausts the issued capitalizable net/nonrecoverable tax.
            if (! is_bool($issued['purchase_recoverable'] ?? null)) {
                $helper->fail('يلزم تحديد قابلية استرداد ضريبة الشراء في اللقطة الصادرة.');
            }
            $capitalized = $issued['purchase_recoverable'] ? $issued['net'] : bcadd($issued['net'], $issued['tax'], 2);
            $cumulativeValue = bccomp($cumulativeQty, $ordered->quantity, 8) === 0 ? $capitalized
                : Decimal::round(bcdiv(bcmul($capitalized, $cumulativeQty, Decimal::SCALE), $ordered->quantity, Decimal::SCALE));
            $amount = bcsub($cumulativeValue, $prior->amount, 2);
            $rawCost = $issued['purchase_recoverable'] ? $issued['taxable_raw'] : bcadd($issued['taxable_raw'], $issued['tax_raw'], Decimal::SCALE);
            $rows[] = ['id' => $line->id, 'product_id' => $ordered->product_id, 'quantity' => $line->quantity, 'amount' => $amount, 'unit_cost' => bcdiv($rawCost, $ordered->quantity, 16), 'serials' => json_decode($line->serials, true, 512, JSON_THROW_ON_ERROR)];
            $total = bcadd($total, $amount, 2);
        }
        if ($rows === []) {
            $helper->fail('الاستلام بلا بنود.');
        }

        return ['receipt' => (array) $receipt, 'order' => (array) $order, 'lines' => $rows, 'total' => $total];
    }

    public function complete(int $actor, int $id, int $version, array $stock, array $journal): void
    {
        $source = $this->prepare($actor, $id);
        $receipt = $source['receipt'];
        if ($receipt['version'] !== $version || $stock['amount'] !== $source['total'] || $journal['amount'] !== $source['total']
            || count($stock['lines']) !== count($source['lines'])) {
            app(SupplierOffers::class)->fail('تغير الاستلام أو اختلفت مصالحة FIFO وGRNI. تم إلغاء المعاملة كاملة.');
        }
        $expected = collect($source['lines'])->keyBy('id');
        foreach ($stock['lines'] as $line) {
            $row = $expected->pull($line['line_id']);
            if (! $row || $row['amount'] !== $line['amount']) {
                app(SupplierOffers::class)->fail('اختلاف قيمة أو هوية بند المخزون وGRNI.');
            }
            DB::table('purchasing_receipt_postings')->insert(['receipt_id' => $id, 'line_id' => $line['line_id'], 'movement_id' => $line['movement_id'], 'layer_id' => $line['layer_id'], 'amount' => $line['amount'], 'posted_at' => now()]);
        }
        DB::table('purchasing_receipts')->where('id', $id)->update(['status' => 'posted', 'journal_id' => $journal['journal_id'], 'capitalized_amount' => $source['total'], 'posted_by' => $actor, 'posted_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
        app(SupplierOffers::class)->audit($actor, 'purchase_receipt', $id, 'receipt.posted', $receipt, ['journal_id' => $journal['journal_id'], 'amount' => $source['total'], 'lines' => $stock['lines']]);
    }
}

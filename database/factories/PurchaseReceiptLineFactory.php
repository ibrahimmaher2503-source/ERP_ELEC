<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseReceipt;
use App\Modules\Purchasing\Domain\PurchaseReceiptLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class PurchaseReceiptLineFactory extends Factory
{
    protected $model = PurchaseReceiptLine::class;

    public function definition(): array
    {
        return ['receipt_id' => PurchaseReceipt::factory(), 'quantity' => '1.00000000'];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PurchaseReceiptLine $line) {
            $receipt = PurchaseReceipt::findOrFail($line->receipt_id);
            $source = DB::table('purchasing_order_lines')->where('order_id', $receipt->order_id)->where('quantity', '>=', '1')->orderBy('id')->first();
            if (! $source) {
                throw new \RuntimeException('Eligible order line required.');
            }
            $allocated = DB::table('purchasing_receipt_lines as l')->join('purchasing_receipts as r', 'r.id', '=', 'l.receipt_id')->where('l.order_line_id', $source->id)->sum('l.quantity');
            if (bccomp(bcadd((string) $allocated, '1', 8), $source->quantity, 8) > 0) {
                throw new \RuntimeException('No remaining receiving draft quantity.');
            }
            $line->order_line_id = $source->id;
        });
    }
}

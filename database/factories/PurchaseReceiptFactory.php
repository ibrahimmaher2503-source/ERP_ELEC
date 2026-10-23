<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseReceiptFactory extends Factory
{
    protected $model = PurchaseReceipt::class;

    public function definition(): array
    {
        $order = DB::table('purchasing_orders')->where('notes', 'like', 'DEMO-EG%')->where('status', 'approved')->orderBy('id')->first();
        $warehouse = $order ? DB::table('inventory_warehouses')->where('branch_id', $order->branch_id)->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->first() : null;
        if (! $order || ! $warehouse) {
            throw new \RuntimeException('Actually approved synthetic order and matching synthetic warehouse required. No approval or stock is fabricated.');
        }

        return ['creation_key' => (string) Str::uuid(), 'order_id' => $order->id, 'branch_id' => $order->branch_id, 'warehouse_id' => $warehouse->id, 'created_by' => $order->created_by, 'status' => 'draft', 'version' => 1, 'receipt_date' => now('Africa/Cairo')->toDateString(), 'delivery_reference' => 'DEMO-EG-RECEIPT-DRAFT', 'notes' => 'DEMO-EG — استلام تجريبي غير مرحل'];
    }
}

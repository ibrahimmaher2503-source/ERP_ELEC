<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseAmendmentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseAmendmentRequestFactory extends Factory
{
    protected $model = PurchaseAmendmentRequest::class;

    public function definition(): array
    {
        $order = DB::table('purchasing_orders')->where('notes', 'like', 'DEMO-EG%')->where('status', 'approved')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchasing_amendment_requests')->whereColumn('order_id', 'purchasing_orders.id'))->orderBy('id')->first();
        $line = $order ? DB::table('purchasing_order_lines')->where('order_id', $order->id)->first() : null;
        if (! $order || ! $line) {
            throw new \RuntimeException('Actually approved synthetic PO with lines required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'order_id' => $order->id, 'created_by' => $order->created_by, 'source_version' => $order->version, 'reason' => 'DEMO-EG — طلب مراجعة كمية تجريبي دون تعديل الأمر', 'proposed_changes' => json_encode([['order_line_id' => $line->id, 'quantity' => $line->quantity]], JSON_THROW_ON_ERROR), 'status' => 'pending_contract'];
    }
}

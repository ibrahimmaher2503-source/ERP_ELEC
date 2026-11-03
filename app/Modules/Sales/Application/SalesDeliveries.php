<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderChanged;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Sales authorizes commercial fulfillment; Inventory owns every physical effect. */
final class SalesDeliveries
{
    public function create(int $actor, int $order, array $data): int
    {
        $d = Validator::make($data, ['creation_key' => 'required|uuid', 'reference' => 'required|string|min:3|max:500',
            'lines' => 'required|array|min:1|max:500', 'lines.*.line_id' => 'required|integer|distinct|min:1',
            'lines.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D']])->validate();
        $lines = array_map(fn ($l) => ['line_id' => (int) $l['line_id'], 'quantity' => bcadd($l['quantity'], '0', 8)], $d['lines']);
        usort($lines, fn ($a, $b) => $a['line_id'] <=> $b['line_id']);
        $hash = hash('sha256', json_encode([$order, $d['reference'], $lines], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $order, $d, $lines, $hash) {
            $r = SalesOrder::lockForUpdate()->findOrFail($order);
            app(SalesOrderAccess::class)->authorize($actor, 'view', $r->branch_id);
            app(SalesOrderAccess::class)->authorize($actor, 'delivery_create', $r->branch_id);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sales-delivery:'.$d['creation_key']]);
            $old = DB::table('sales_delivery_instructions')->where('creation_key', $d['creation_key'])->first();
            if ($old) {
                abort_unless($old->order_id === $order && hash_equals($old->payload_hash, $hash), 409);

                return $old->id;
            }
            abort_unless($r->status === 'approved', 409);
            $id = DB::table('sales_delivery_instructions')->insertGetId(['order_id' => $order, 'creation_key' => $d['creation_key'],
                'payload_hash' => $hash, 'reference' => $d['reference'], 'created_by' => $actor, 'created_at' => now()]);
            foreach ($lines as $line) {
                $allocation = DB::table('sales_order_allocations')->where('order_id', $order)->where('line_id', $line['line_id'])->first();
                abort_unless($allocation, 404);
                if (bccomp($line['quantity'], '0', 8) <= 0) {
                    throw ValidationException::withMessages(['deliveryQuantities.'.$line['line_id'] => 'أدخل كمية تسليم موجبة.']);
                }
                $reservation = app(InventoryApi::class)->reservationStatus($actor, $allocation->reservation_id);
                if (bccomp($line['quantity'], $reservation['outstanding'], 8) > 0) {
                    throw ValidationException::withMessages(['deliveryQuantities.'.$line['line_id'] => 'الكمية تتجاوز الحجز المتاح؛ لا يسلم الطلب المؤجل.']);
                }
                DB::table('sales_delivery_instruction_lines')->insert(['instruction_id' => $id, 'line_id' => $line['line_id'],
                    'reservation_id' => $allocation->reservation_id, 'quantity' => $line['quantity'],
                    'issue_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-delivery:'.$id.':'.$line['line_id'])->toString()]);
            }
            event(new SalesOrderChanged($actor, $order, 'delivery_instructed', null, ['instruction_id' => $id, 'lines' => $lines]));

            return $id;
        });
    }

    public function confirm(int $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $source = DB::table('sales_delivery_instructions')->find($id);
            abort_unless($source, 404);
            $r = SalesOrder::lockForUpdate()->findOrFail($source->order_id);
            app(SalesOrderAccess::class)->authorize($actor, 'view', $r->branch_id);
            app(SalesOrderAccess::class)->authorize($actor, 'delivery_confirm', $r->branch_id);
            $source = DB::table('sales_delivery_instructions')->lockForUpdate()->find($id);
            if ($source->status === 'confirmed') {
                foreach (DB::table('sales_delivery_instruction_lines')->where('instruction_id', $id)->get() as $line) {
                    // Replays still enforce the current Inventory mutation scope.
                    app(InventoryApi::class)->fulfillReservation($actor, $line->reservation_id, $line->quantity, $line->issue_key);
                }

                return;
            }
            abort_unless($source->status === 'draft' && $r->status === 'approved', 409);
            DB::table('sales_delivery_instructions')->where('id', $id)->update(['status' => 'posting']);
            $rows = [];
            foreach (DB::table('sales_delivery_instruction_lines')->where('instruction_id', $id)->orderBy('line_id')->get() as $line) {
                app(InventoryApi::class)->fulfillReservation($actor, $line->reservation_id, $line->quantity, $line->issue_key);
                $product = $r->lines()->findOrFail($line->line_id);
                $rows[] = [$product->description, $line->quantity];
            }
            $doc = app(OfficialDocuments::class)->issue($actor, 'sales_delivery', (string) $id, 1, now('Africa/Cairo')->toDateString(), [
                'title' => 'إذن تسليم', 'fields' => ['أمر البيع' => $r->number, 'العميل' => $r->customer_snapshot['name'], 'مرجع التسليم' => $source->reference],
                'columns' => ['الصنف', 'الكمية بالوحدة الأساسية'], 'rows' => $rows,
            ]);
            DB::table('sales_delivery_instructions')->where('id', $id)->update(['status' => 'confirmed', 'document_id' => $doc['id'], 'confirmed_by' => $actor, 'confirmed_at' => now()]);
            event(new SalesOrderChanged($actor, $r->id, 'delivery_confirmed', null, ['instruction_id' => $id, 'document_id' => $doc['id'], 'number' => $doc['number']]));
        });
    }
}

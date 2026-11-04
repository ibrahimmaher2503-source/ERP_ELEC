<?php

namespace App\Modules\Sales\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Sales owns the return document; Inventory and M12 own its stock and credit effects. */
final class SalesReturns
{
    public const REASONS = ['defective' => 'عيب في الصنف', 'wrong_item' => 'صنف خطأ', 'customer_refused' => 'رفض العميل', 'excess_quantity' => 'كمية زائدة', 'other' => 'سبب آخر'];

    public const CONDITIONS = ['sellable' => 'سليم', 'damaged' => 'تالف'];

    public function list(int $actor, array $filters = []): array
    {
        $branches = app(SalesOrderAccess::class)->branchIds($actor, 'return_view');

        return DB::table('sales_returns as r')->join('sales_orders as o', 'o.id', '=', 'r.order_id')->whereIn('r.branch_id', $branches)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('r.status', $status))
            ->orderByDesc('r.id')->limit(200)->get(['r.*', 'o.number as order_number'])
            ->map(fn ($row) => (array) $row + ['lines' => DB::table('sales_return_lines')->where('return_id', $row->id)->orderBy('id')->get()->map(fn ($line) => (array) $line)->all()])->all();
    }

    /** Confirmed deliveries that still have invoiced, unreturned quantity. */
    public function eligibleDeliveries(int $actor): array
    {
        $branches = app(SalesOrderAccess::class)->branchIds($actor, 'return_create');
        $rows = DB::table('sales_delivery_instructions as d')->join('sales_orders as o', 'o.id', '=', 'd.order_id')
            ->where('d.status', 'confirmed')->whereIn('o.branch_id', $branches)->orderByDesc('d.id')->limit(200)
            ->get(['d.id', 'd.reference', 'd.confirmed_at', 'o.number as order_number', 'o.customer_snapshot']);

        return $rows->map(function ($row) use ($actor) {
            $lines = $this->deliveryLines($actor, (int) $row->id);
            $customer = json_decode($row->customer_snapshot, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => (int) $row->id, 'reference' => $row->reference, 'order_number' => $row->order_number, 'customer' => $customer['name'] ?? '', 'lines' => $lines];
        })->filter(fn ($row) => $row['lines'] !== [])->values()->all();
    }

    public function deliveryLines(int $actor, int $delivery): array
    {
        $source = DB::table('sales_delivery_instructions as d')->join('sales_orders as o', 'o.id', '=', 'd.order_id')->where('d.id', $delivery)->value('o.branch_id');
        abort_unless($source, 404);
        app(SalesOrderAccess::class)->authorize($actor, 'return_view', (int) $source);
        $rows = DB::table('sales_delivery_instruction_lines as dl')->join('sales_order_lines as l', 'l.id', '=', 'dl.line_id')
            ->where('dl.instruction_id', $delivery)->orderBy('dl.id')->get(['dl.id', 'dl.quantity as delivered', 'dl.issue_key', 'l.product_id', 'l.description']);
        if ($rows->isEmpty()) {
            return [];
        }
        $remaining = $this->returnable($rows->pluck('id')->all());

        return $rows->filter(fn ($row) => bccomp($remaining[(int) $row->id], '0', 8) > 0)->map(fn ($row) => ['delivery_line_id' => (int) $row->id, 'product_id' => (int) $row->product_id,
            'description' => $row->description, 'delivered' => $row->delivered, 'returnable' => $remaining[(int) $row->id],
            'serials' => app(InventoryApi::class)->salesIssueEvidence($actor, $row->issue_key)['serials']])->values()->all();
    }

    public function create(int $actor, array $data): int
    {
        $d = Validator::make($data, ['creation_key' => 'required|uuid', 'delivery_id' => 'required|integer|min:1', 'reason_code' => 'required|in:'.implode(',', array_keys(self::REASONS)),
            'reason' => 'required|string|min:5|max:2000', 'lines' => 'required|array|min:1|max:500', 'lines.*.delivery_line_id' => 'required|integer|distinct|min:1',
            'lines.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D'], 'lines.*.condition' => 'required|in:sellable,damaged',
            'lines.*.serials' => 'array', 'lines.*.serials.*' => 'string|max:200'])->validate();
        $hash = hash('sha256', json_encode([$d['delivery_id'], $d['reason_code'], $d['reason'], $d['lines']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $d, $hash) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['sales-return:'.$d['creation_key']]);
            $old = DB::table('sales_returns')->where('creation_key', $d['creation_key'])->first();
            if ($old) {
                abort_unless((int) $old->created_by === $actor && hash_equals($old->payload_hash, $hash), 409);

                return (int) $old->id;
            }
            $delivery = DB::table('sales_delivery_instructions')->find($d['delivery_id']);
            abort_unless($delivery, 404);
            $order = SalesOrder::lockForUpdate()->findOrFail($delivery->order_id);
            app(SalesOrderAccess::class)->authorize($actor, 'return_create', $order->branch_id);
            abort_unless($delivery->status === 'confirmed', 409, 'يلزم إذن تسليم مؤكد.');
            $sources = DB::table('sales_delivery_instruction_lines as dl')->join('sales_order_lines as l', 'l.id', '=', 'dl.line_id')->where('dl.instruction_id', $delivery->id)
                ->get(['dl.id', 'dl.line_id', 'dl.issue_key', 'l.product_id', 'l.description'])->keyBy('id');
            $lines = [];
            foreach ($d['lines'] as $index => $line) {
                $source = $sources->get((int) $line['delivery_line_id']);
                abort_unless($source, 422);
                DB::select('SELECT pg_advisory_xact_lock(12013, ?)', [(int) $source->id]);
                $quantity = bcadd($line['quantity'], '0', 8);
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $this->returnable([(int) $source->id])[(int) $source->id], 8) > 0) {
                    $this->fail('lines.'.$index.'.quantity', 'الكمية تتجاوز المفوتر غير المرتجع. إذا لم تصدر الفاتورة بعد، أصدرها أولًا.');
                }
                $serialsHeld = DB::table('sales_return_lines as l')->join('sales_returns as r', 'r.id', '=', 'l.return_id')->whereIn('r.status', ['draft', 'posting'])
                    ->where('l.delivery_line_id', $source->id)->pluck('l.serials')->flatMap(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->all();
                if (array_intersect($this->serials($actor, $source, $quantity, $line['serials'] ?? [], $index), $serialsHeld) !== []) {
                    $this->fail('lines.'.$index.'.serials', 'الرقم المسلسل موجود في مرتجع آخر لم يُعتمد بعد.');
                }
                $lines[] = ['source' => $source, 'quantity' => $quantity, 'condition' => $line['condition'], 'serials' => $this->serials($actor, $source, $quantity, $line['serials'] ?? [], $index)];
            }
            $id = DB::table('sales_returns')->insertGetId(['creation_key' => $d['creation_key'], 'payload_hash' => $hash, 'branch_id' => $order->branch_id, 'order_id' => $order->id,
                'delivery_id' => $delivery->id, 'customer_id' => $order->customer_id, 'customer_name' => $order->customer_snapshot['name'], 'reason_code' => $d['reason_code'],
                'reason' => $d['reason'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                DB::table('sales_return_lines')->insert(['return_id' => $id, 'delivery_line_id' => $line['source']->id, 'line_id' => $line['source']->line_id, 'product_id' => $line['source']->product_id,
                    'description' => $line['source']->description, 'quantity' => $line['quantity'], 'condition' => $line['condition'], 'serials' => json_encode($line['serials'], JSON_THROW_ON_ERROR),
                    'stock_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-return-line:'.$id.':'.$line['source']->id)->toString()]);
            }
            app(RecordAudit::class)->execute($actor, 'sales_return', $id, 'sales_return.created', null, ['order_id' => $order->id, 'delivery_id' => $delivery->id], 'succeeded', 'Sales');

            return $id;
        }, 5);
    }

    public function reject(int $actor, int $id, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $reason) {
            $row = DB::table('sales_returns')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(SalesOrderAccess::class)->authorize($actor, 'return_approve', $row->branch_id);
            abort_unless($row->status === 'draft' && (int) $row->created_by !== $actor, 409, 'رفض المرتجع يجب أن يكون من مستخدم غير مُعده.');
            DB::table('sales_returns')->where('id', $id)->update(['status' => 'rejected', 'rejected_by' => $actor, 'rejection_reason' => $reason, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'sales_return', $id, 'sales_return.rejected', ['status' => 'draft'], ['status' => 'rejected', 'reason' => $reason], 'succeeded', 'Sales');
        }, 5);
    }

    /** Stock, COGS reversal, credit note and return document commit together, or not at all. */
    public function approve(int $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            app(AccountingApi::class)->lockPosting();
            $row = DB::table('sales_returns')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(SalesOrderAccess::class)->authorize($actor, 'return_approve', $row->branch_id);
            if ($row->status === 'posted') {
                return ['return_id' => $id, 'number' => $row->official_number];
            }
            abort_unless($row->status === 'draft' && (int) $row->created_by !== $actor, 409, 'اعتماد المرتجع يجب أن يكون من مستخدم غير مُعده.');
            DB::table('sales_returns')->where('id', $id)->update(['status' => 'posting', 'approved_by' => $actor, 'updated_at' => now()]);
            $date = now('Africa/Cairo')->toDateString();
            $lines = DB::table('sales_return_lines as r')->join('sales_delivery_instruction_lines as dl', 'dl.id', '=', 'r.delivery_line_id')
                ->where('r.return_id', $id)->orderBy('r.id')->get(['r.*', 'dl.issue_key']);
            $credit = [];
            $rows = [];
            foreach ($lines as $line) {
                $serials = json_decode($line->serials, true, 512, JSON_THROW_ON_ERROR);
                foreach (app(InventoryApi::class)->returnSalesIssue($actor, $line->issue_key, $line->quantity, $line->stock_key, $row->reason, $serials) as $posted) {
                    $isolation = null;
                    if ($line->condition === 'damaged') {
                        $evidence = app(InventoryApi::class)->salesIssueEvidence($actor, $line->issue_key);
                        $isolation = app(InventoryApi::class)->isolateStock($actor, ['warehouse_id' => $evidence['warehouse_id'], 'product_id' => $line->product_id, 'source_movement_id' => $posted['movement_id'],
                            'quantity' => $posted['quantity'], 'reason' => 'مرتجع عميل تالف: '.$row->reason, 'serials' => $posted['serials'],
                            'idempotency_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'sales-return-isolation:'.$posted['movement_id'])->toString()])['id'];
                    }
                    DB::table('sales_return_postings')->insert(['line_id' => $line->id, 'movement_id' => $posted['movement_id'], 'amount' => $posted['amount'], 'isolation_id' => $isolation, 'created_at' => now()]);
                }
                $credit[] = ['source_line_id' => (int) $line->delivery_line_id, 'quantity' => $line->quantity];
                $rows[] = [$line->description, $line->quantity, self::CONDITIONS[$line->condition]];
            }
            $notes = app(ReceivablesPayablesApi::class)->postReturnCredits($actor, (int) $row->created_by, 'customer', 'sales-return:'.$id, $date, $row->reason, $credit);
            $document = app(OfficialDocuments::class)->issue($actor, 'sales_return', (string) $id, 1, $date, ['title' => 'إذن مرتجع بيع',
                'fields' => ['العميل' => $row->customer_name, 'السبب' => self::REASONS[$row->reason_code], 'التفاصيل' => $row->reason, 'إشعارات التصحيح' => implode('، ', array_column($notes, 'number'))],
                'columns' => ['الصنف', 'الكمية', 'الحالة'], 'rows' => $rows]);
            DB::table('sales_returns')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now(), 'official_document_id' => $document['id'], 'official_number' => $document['number'],
                'credit_notes' => json_encode($notes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'sales_return', $id, 'sales_return.posted', ['status' => 'draft'], ['status' => 'posted', 'number' => $document['number'], 'credit_notes' => $notes], 'succeeded', 'Sales');

            return ['return_id' => $id, 'number' => $document['number'], 'credit_notes' => $notes];
        }, 5);
    }

    /** Stock posting callback: only a return being approved right now can cost its movements. */
    public function authorizeMovement(int $actor, int $order, int $line): array
    {
        $row = DB::table('sales_returns as r')->join('sales_return_lines as l', 'l.return_id', '=', 'r.id')
            ->where('r.order_id', $order)->where('l.line_id', $line)->where('r.status', 'posting')->where('r.approved_by', $actor)->first(['r.branch_id', 'r.customer_id', 'r.created_by', 'r.approved_by']);
        abort_unless($row, 409, 'لا يوجد مرتجع قيد الاعتماد لهذا البند.');

        return ['branch_id' => (int) $row->branch_id, 'customer_id' => (int) $row->customer_id, 'created_by' => (int) $row->created_by, 'approved_by' => (int) $row->approved_by];
    }

    private function returnable(array $deliveryLines): array
    {
        $remaining = app(ReceivablesPayablesApi::class)->returnableQuantities('customer', $deliveryLines);
        $open = DB::table('sales_return_lines as l')->join('sales_returns as r', 'r.id', '=', 'l.return_id')->whereIn('l.delivery_line_id', $deliveryLines)
            ->whereIn('r.status', ['draft', 'posting'])->groupBy('l.delivery_line_id')->selectRaw('l.delivery_line_id,sum(l.quantity) quantity')->pluck('quantity', 'delivery_line_id');
        foreach ($open as $line => $quantity) {
            $remaining[(int) $line] = bcsub($remaining[(int) $line], $quantity, 8);
        }

        return $remaining;
    }

    private function serials(int $actor, object $source, string $quantity, array $serials, int $index): array
    {
        $available = app(InventoryApi::class)->salesIssueEvidence($actor, $source->issue_key)['serials'];
        if ($available === [] && $serials === []) {
            return [];
        }
        $selected = app(InventoryApi::class)->normalizedSerials($serials, $quantity);
        if (array_diff($selected, $available) !== []) {
            $this->fail('lines.'.$index.'.serials', 'الرقم المسلسل لا يخص هذا التسليم أو سبق إرجاعه.');
        }

        return $selected;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

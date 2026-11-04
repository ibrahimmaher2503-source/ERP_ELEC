<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Purchasing owns the return document; Inventory and M12 own its stock and credit effects. */
final class PurchaseReturns
{
    public const REASONS = ['defective' => 'عيب في الصنف', 'wrong_item' => 'صنف خطأ', 'excess_quantity' => 'كمية زائدة', 'expired' => 'منتهي الصلاحية', 'other' => 'سبب آخر'];

    public function list(int $actor, array $filters = []): array
    {
        $branches = array_column(app(PurchasingAccess::class)->branches($actor, 'return_view'), 'id');

        return DB::table('purchasing_returns as r')->join('purchasing_receipts as p', 'p.id', '=', 'r.receipt_id')->whereIn('r.branch_id', $branches)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('r.status', $status))
            ->orderByDesc('r.id')->limit(200)->get(['r.*', 'p.delivery_reference as receipt_number'])
            ->map(fn ($row) => (array) $row + ['lines' => DB::table('purchasing_return_lines')->where('return_id', $row->id)->orderBy('id')->get()->map(fn ($line) => (array) $line)->all()])->all();
    }

    /** Posted receipts that still have invoiced, unreturned quantity. */
    public function eligibleReceipts(int $actor): array
    {
        $branches = array_column(app(PurchasingAccess::class)->branches($actor, 'return_create'), 'id');
        $found = [];
        // Un-invoiced and fully returned receipts are filtered after reading, so page through newest first until enough qualify.
        for ($offset = 0; $offset < 2000 && count($found) < 100; $offset += 200) {
            $rows = DB::table('purchasing_receipts as r')->join('purchasing_orders as o', 'o.id', '=', 'r.order_id')->where('r.status', 'posted')->whereIn('r.branch_id', $branches)
                ->orderByDesc('r.id')->offset($offset)->limit(200)->get(['r.id', 'r.delivery_reference', 'r.receipt_date', 'o.supplier_name']);
            foreach ($rows as $row) {
                $lines = $this->receiptLines($actor, (int) $row->id);
                if ($lines !== []) {
                    $found[] = ['id' => (int) $row->id, 'number' => $row->delivery_reference, 'date' => $row->receipt_date, 'supplier' => $row->supplier_name, 'lines' => $lines];
                }
            }
            if ($rows->count() < 200) {
                break;
            }
        }

        return $found;
    }

    public function receiptLines(int $actor, int $receipt): array
    {
        $branch = DB::table('purchasing_receipts')->where('id', $receipt)->value('branch_id');
        abort_unless($branch, 404);
        app(PurchasingAccess::class)->authorize($actor, 'return_view', (int) $branch);
        $rows = DB::table('purchasing_receipt_lines as l')->join('purchasing_order_lines as o', 'o.id', '=', 'l.order_line_id')->where('l.receipt_id', $receipt)->orderBy('l.id')
            ->get(['l.id', 'l.quantity as received', 'l.serials', 'o.product_id', 'o.product_name']);
        if ($rows->isEmpty()) {
            return [];
        }
        $remaining = $this->returnable($rows->pluck('id')->all());

        return $rows->filter(fn ($row) => bccomp($remaining[(int) $row->id], '0', 8) > 0)->map(fn ($row) => ['receipt_line_id' => (int) $row->id, 'product_id' => (int) $row->product_id,
            'description' => $row->product_name, 'received' => $row->received, 'returnable' => $remaining[(int) $row->id], 'serials' => json_decode($row->serials, true, 512, JSON_THROW_ON_ERROR)])->values()->all();
    }

    public function create(int $actor, array $data): int
    {
        $d = Validator::make($data, ['creation_key' => 'required|uuid', 'receipt_id' => 'required|integer|min:1', 'reason_code' => 'required|in:'.implode(',', array_keys(self::REASONS)),
            'reason' => 'required|string|min:5|max:2000', 'lines' => 'required|array|min:1|max:500', 'lines.*.receipt_line_id' => 'required|integer|distinct|min:1',
            'lines.*.quantity' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D'], 'lines.*.serials' => 'array', 'lines.*.serials.*' => 'string|max:200'])->validate();
        $hash = hash('sha256', json_encode([$d['receipt_id'], $d['reason_code'], $d['reason'], $d['lines']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $d, $hash) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['purchase-return:'.$d['creation_key']]);
            $old = DB::table('purchasing_returns')->where('creation_key', $d['creation_key'])->first();
            if ($old) {
                abort_unless((int) $old->created_by === $actor && hash_equals($old->payload_hash, $hash), 409);

                return (int) $old->id;
            }
            $receipt = DB::table('purchasing_receipts')->lockForUpdate()->find($d['receipt_id']);
            abort_unless($receipt, 404);
            app(PurchasingAccess::class)->authorize($actor, 'return_create', $receipt->branch_id);
            abort_unless($receipt->status === 'posted', 409, 'يلزم استلام مرحّل.');
            $order = DB::table('purchasing_orders')->find($receipt->order_id);
            $sources = DB::table('purchasing_receipt_lines as l')->join('purchasing_order_lines as o', 'o.id', '=', 'l.order_line_id')->where('l.receipt_id', $receipt->id)
                ->get(['l.id', 'l.serials', 'o.product_id', 'o.product_name'])->keyBy('id');
            $lines = [];
            foreach ($d['lines'] as $index => $line) {
                $source = $sources->get((int) $line['receipt_line_id']);
                abort_unless($source, 422);
                DB::select('SELECT pg_advisory_xact_lock(12012, ?)', [(int) $source->id]);
                $quantity = bcadd($line['quantity'], '0', 8);
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $this->returnable([(int) $source->id])[(int) $source->id], 8) > 0) {
                    $this->fail('lines.'.$index.'.quantity', 'الكمية تتجاوز المفوتر غير المرتجع. إذا لم تسجل فاتورة المورد بعد، سجلها أولًا.');
                }
                $lines[] = ['source' => $source, 'quantity' => $quantity, 'serials' => $this->serials($source, $quantity, $line['serials'] ?? [], $index)];
            }
            $id = DB::table('purchasing_returns')->insertGetId(['creation_key' => $d['creation_key'], 'payload_hash' => $hash, 'branch_id' => $receipt->branch_id, 'receipt_id' => $receipt->id,
                'order_id' => $order->id, 'supplier_id' => $order->supplier_id, 'supplier_name' => $order->supplier_name, 'reason_code' => $d['reason_code'], 'reason' => $d['reason'],
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                DB::table('purchasing_return_lines')->insert(['return_id' => $id, 'receipt_line_id' => $line['source']->id, 'product_id' => $line['source']->product_id, 'description' => $line['source']->product_name,
                    'quantity' => $line['quantity'], 'serials' => json_encode($line['serials'], JSON_THROW_ON_ERROR), 'stock_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'purchase-return-line:'.$id.':'.$line['source']->id)->toString()]);
            }
            app(RecordAudit::class)->execute($actor, 'purchase_return', $id, 'purchase_return.created', null, ['receipt_id' => $receipt->id], 'succeeded', 'Purchasing');

            return $id;
        }, 5);
    }

    public function reject(int $actor, int $id, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $reason) {
            $row = DB::table('purchasing_returns')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(PurchasingAccess::class)->authorize($actor, 'return_approve', $row->branch_id);
            abort_unless($row->status === 'draft' && (int) $row->created_by !== $actor, 409, 'رفض المرتجع يجب أن يكون من مستخدم غير مُعده.');
            DB::table('purchasing_returns')->where('id', $id)->update(['status' => 'rejected', 'rejected_by' => $actor, 'rejection_reason' => $reason, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'purchase_return', $id, 'purchase_return.rejected', ['status' => 'draft'], ['status' => 'rejected', 'reason' => $reason], 'succeeded', 'Purchasing');
        }, 5);
    }

    /** Stock leaves and the supplier credit posts together; there is no uncredited dispatch. */
    public function approve(int $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            app(AccountingApi::class)->lockPosting();
            $row = DB::table('purchasing_returns')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(PurchasingAccess::class)->authorize($actor, 'return_approve', $row->branch_id);
            if ($row->status === 'posted') {
                return ['return_id' => $id, 'number' => $row->official_number];
            }
            abort_unless($row->status === 'draft' && (int) $row->created_by !== $actor, 409, 'اعتماد المرتجع يجب أن يكون من مستخدم غير مُعده.');
            DB::table('purchasing_returns')->where('id', $id)->update(['status' => 'posting', 'approved_by' => $actor, 'updated_at' => now()]);
            $date = now('Africa/Cairo')->toDateString();
            $credit = [];
            $rows = [];
            foreach (DB::table('purchasing_return_lines')->where('return_id', $id)->orderBy('id')->get() as $line) {
                $movement = (int) DB::table('purchasing_receipt_postings')->where('line_id', $line->receipt_line_id)->value('movement_id');
                abort_unless($movement > 0, 409);
                $posted = app(InventoryApi::class)->returnToSupplier($actor, $movement, $line->quantity, $line->stock_key, $row->reason, json_decode($line->serials, true, 512, JSON_THROW_ON_ERROR));
                DB::table('purchasing_return_postings')->insert(['line_id' => $line->id, 'movement_id' => $posted['movement_id'], 'amount' => $posted['amount'], 'created_at' => now()]);
                $credit[] = ['source_line_id' => (int) $line->receipt_line_id, 'quantity' => $line->quantity];
                $rows[] = [$line->description, $line->quantity];
            }
            $notes = app(ReceivablesPayablesApi::class)->postReturnCredits($actor, (int) $row->created_by, 'supplier', 'purchase-return:'.$id, $date, $row->reason, $credit);
            $document = app(OfficialDocuments::class)->issue($actor, 'purchase_return', (string) $id, 1, $date, ['title' => 'إذن مرتجع مشتريات',
                'fields' => ['المورد' => $row->supplier_name, 'السبب' => self::REASONS[$row->reason_code], 'التفاصيل' => $row->reason, 'إشعارات التصحيح' => implode('، ', array_column($notes, 'number'))],
                'columns' => ['الصنف', 'الكمية'], 'rows' => $rows]);
            DB::table('purchasing_returns')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now(), 'official_document_id' => $document['id'], 'official_number' => $document['number'],
                'credit_notes' => json_encode($notes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'purchase_return', $id, 'purchase_return.posted', ['status' => 'draft'], ['status' => 'posted', 'number' => $document['number'], 'credit_notes' => $notes], 'succeeded', 'Purchasing');

            return ['return_id' => $id, 'number' => $document['number'], 'credit_notes' => $notes];
        }, 5);
    }

    /** Stock posting callback: only a return being approved right now can cost its movement. */
    public function authorizeMovement(int $actor, int $receipt, int $line): array
    {
        $row = DB::table('purchasing_returns as r')->join('purchasing_return_lines as l', 'l.return_id', '=', 'r.id')->where('r.receipt_id', $receipt)->where('l.receipt_line_id', $line)
            ->where('r.status', 'posting')->where('r.approved_by', $actor)->first(['r.branch_id', 'r.order_id', 'r.created_by', 'r.approved_by']);
        abort_unless($row, 409, 'لا يوجد مرتجع قيد الاعتماد لهذا البند.');

        return ['branch_id' => (int) $row->branch_id, 'order_id' => (int) $row->order_id, 'created_by' => (int) $row->created_by, 'approved_by' => (int) $row->approved_by];
    }

    private function returnable(array $receiptLines): array
    {
        $remaining = app(ReceivablesPayablesApi::class)->returnableQuantities('supplier', $receiptLines);
        $open = DB::table('purchasing_return_lines as l')->join('purchasing_returns as r', 'r.id', '=', 'l.return_id')->whereIn('l.receipt_line_id', $receiptLines)
            ->whereIn('r.status', ['draft', 'posting'])->groupBy('l.receipt_line_id')->selectRaw('l.receipt_line_id,sum(l.quantity) quantity')->pluck('quantity', 'receipt_line_id');
        foreach ($open as $line => $quantity) {
            $remaining[(int) $line] = bcsub($remaining[(int) $line], $quantity, 8);
        }

        return $remaining;
    }

    private function serials(object $source, string $quantity, array $serials, int $index): array
    {
        $received = json_decode($source->serials, true, 512, JSON_THROW_ON_ERROR);
        if ($received === [] && $serials === []) {
            return [];
        }
        $selected = app(InventoryApi::class)->normalizedSerials($serials, $quantity);
        $allowed = app(InventoryApi::class)->normalizedSerials($received, bcadd((string) count($received), '0', 8));
        if (array_diff($selected, $allowed) !== []) {
            $this->fail('lines.'.$index.'.serials', 'الرقم المسلسل لا يخص هذا الاستلام.');
        }

        return $selected;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

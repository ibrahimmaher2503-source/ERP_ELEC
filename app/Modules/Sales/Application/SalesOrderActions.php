<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderChanged;
use App\Modules\Sales\Domain\SalesOrderReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SalesOrderActions
{
    private function access(int $actor, string $action, int $branch): void
    {
        app(SalesOrderAccess::class)->authorize($actor, 'view', $branch);
        app(SalesOrderAccess::class)->authorize($actor, $action, $branch);
    }

    public function version(SalesOrder $r, ?int $expected): void
    {
        if ($expected === null || $r->version !== $expected) {
            throw ValidationException::withMessages(['order' => 'تغير أمر البيع. أعد تحميله قبل تنفيذ الإجراء.']);
        }
    }

    public function snapshot(SalesOrder $r): array
    {
        return ['order' => $r->only(['branch_id', 'branch_snapshot', 'customer_id', 'customer_snapshot', 'source_type', 'project_name', 'customer_reference', 'order_date', 'requested_delivery_date', 'currency', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'notes', 'calculation']), 'lines' => $r->lines->map(fn ($l) => $l->only(['position', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'suggested_price', 'requested_price', 'price_source', 'discount_mode', 'discount_value', 'override_reason']))->all()];
    }

    public function save(int $actor, array $input, ?int $id = null, ?int $expectedVersion = null): SalesOrder
    {
        app(SalesOrderAccess::class)->authorize($actor);
        $data = Validator::make($input, [
            'creation_key' => [$id === null ? 'required' : 'sometimes', 'uuid'],
            'branch_id' => ['required', 'integer', 'min:1'], 'customer_id' => ['required', 'integer', 'min:1'],
            'source_type' => ['sometimes', Rule::in(['direct'])], 'order_date' => ['required', 'date_format:Y-m-d'],
            'requested_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'project_name' => ['nullable', 'string', 'max:200'], 'customer_reference' => ['nullable', 'string', 'max:100'],
            'discount_mode' => ['required', Rule::in(['percent', 'fixed'])], 'discount_value' => ['required', 'string'],
            'payment_terms' => ['nullable', 'string', 'max:4000'], 'delivery_terms' => ['nullable', 'string', 'max:4000'], 'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*' => ['array'], 'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.unit_id' => ['required', 'integer', 'min:1'], 'lines.*.quantity' => ['required', 'string'], 'lines.*.requested_price' => ['nullable', 'string'],
            'lines.*.description' => ['nullable', 'string', 'max:4000'], 'lines.*.discount_mode' => ['required', Rule::in(['percent', 'fixed'])],
            'lines.*.discount_value' => ['required', 'string'], 'lines.*.override_reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $data['requested_delivery_date'] = ($data['requested_delivery_date'] ?? '') === '' ? null : $data['requested_delivery_date'];

        return DB::transaction(function () use ($actor, $data, $id, $expectedVersion) {
            if ($id === null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['creation_key']]);
                $existing = SalesOrder::where('draft_key', $data['creation_key'])->lockForUpdate()->first();
                if ($existing) {
                    $this->access($actor, 'create', $existing->branch_id);
                    abort_unless((int) $existing->created_by === $actor, 409, 'معرف الحفظ مستخدم من عملية أخرى.');

                    return $existing->load('lines');
                }
            }
            $r = $id === null ? new SalesOrder : SalesOrder::lockForUpdate()->findOrFail($id);
            if ($id !== null) {
                $this->access($actor, 'edit', $r->branch_id);
                $this->version($r, $expectedVersion);
                if (! $r->editable()) {
                    throw ValidationException::withMessages(['order' => 'التعديل المباشر للمسودة فقط. الأوامر المعتمدة تحتاج تعديلًا رسميًا.']);
                }
            }
            $this->access($actor, $id === null ? 'create' : 'edit', (int) $data['branch_id']);
            $catalog = app(SalesOrderCatalog::class);
            $branch = collect($catalog->branches($actor, $id === null ? 'create' : 'edit'))->firstWhere('id', (int) $data['branch_id']);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'اختر فرعًا نشطًا ضمن صلاحيتك.']);
            }
            $customer = $catalog->customer($actor, (int) $data['customer_id']);
            $lines = [];
            foreach ($data['lines'] as $i => $line) {
                DraftAmounts::decimal($line['quantity'], "lines.$i.quantity", true);
                $snapshot = $catalog->product($actor, (int) $line['product_id'], (int) $line['unit_id'], $line['quantity']);
                $price = app(QuotationPricing::class)->suggested($actor, (int) $line['product_id'], (int) $line['unit_id'], $data['order_date']);
                if ($price !== null) {
                    DraftAmounts::decimal($price['amount'] ?? null, "lines.$i.suggested_price");
                }
                $lines[] = ['position' => $i + 1, 'product_id' => $snapshot['id'], 'unit_id' => (int) $line['unit_id'], 'product_snapshot' => $snapshot, 'description' => trim($line['description'] ?? '') ?: $snapshot['name'], 'quantity' => $line['quantity'], 'requested_price' => ($line['requested_price'] ?? '') === '' ? null : $line['requested_price'], 'suggested_price' => $price['amount'] ?? null, 'price_source' => $price, 'discount_mode' => $line['discount_mode'], 'discount_value' => $line['discount_value'], 'override_reason' => $line['override_reason'] ?? null];
            }
            $before = $id === null ? null : $this->snapshot($r->load('lines'));
            $calculation = app(DraftAmounts::class)->calculate($lines, $data['discount_mode'], $data['discount_value']);
            $creationKey = $data['creation_key'] ?? null;
            unset($data['lines'], $data['source_type'], $data['creation_key']);
            $r->fill($data);
            $r->branch_snapshot = $branch;
            $r->customer_snapshot = $customer;
            $r->calculation = $calculation;
            $r->version = $id === null ? 1 : $r->version + 1;
            $r->updated_by = $actor;
            if ($id === null) {
                $r->draft_key = $creationKey;
                $r->source_type = 'direct';
                $r->currency = 'EGP';
                $r->status = 'draft';
                $r->created_by = $actor;
            }
            $r->save();
            $r->lines()->delete();
            $r->lines()->createMany($lines);
            $r->load('lines');
            event(new SalesOrderChanged($actor, $r->id, $id === null ? 'draft_created' : 'draft_updated', $before, $this->snapshot($r) + ['version' => $r->version]));

            return $r;
        });
    }

    public function submit(int $actor, int $id, int $version): SalesOrderReview
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            $r = SalesOrder::with('lines')->lockForUpdate()->findOrFail($id);
            $this->access($actor, 'submit', $r->branch_id);
            $this->version($r, $version);
            if (! $r->editable() || $r->lines->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'أرسل مسودة تحتوي بنودًا فقط.']);
            }
            if (! collect(app(SalesOrderCatalog::class)->branches($actor, 'submit'))->contains('id', $r->branch_id)) {
                throw ValidationException::withMessages(['order' => 'فرع الأمر غير نشط أو خارج نطاق التقديم.']);
            }
            // Revalidate owning identities; saving a draft is not approval of prices or terms.
            app(SalesOrderCatalog::class)->customer($actor, $r->customer_id);
            foreach ($r->lines as $l) {
                app(SalesOrderCatalog::class)->product($actor, $l->product_id, $l->unit_id, $l->quantity);
            }
            $snapshot = $this->snapshot($r);
            $r->update(['status' => 'pending_approval', 'version' => $r->version + 1, 'updated_by' => $actor]);
            $review = $r->reviews()->create(['target_version' => $r->version, 'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'snapshot' => $snapshot, 'requested_by' => $actor, 'status' => 'pending']);
            event(new SalesOrderChanged($actor, $id, 'review_submitted', null, ['review_id' => $review->id, 'version' => $r->version, 'status' => $r->status, 'reservation' => 'not_requested']));

            return $review;
        });
    }

    public function withdraw(int $actor, int $id, int $version): void
    {
        $this->finishReview($actor, $id, $version, 'withdraw', '');
    }

    public function reject(int $actor, int $id, int $version, string $reason): void
    {
        $this->finishReview($actor, $id, $version, 'reject', $reason);
    }

    private function finishReview(int $actor, int $id, int $version, string $action, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:2000']])->validate();
        DB::transaction(function () use ($actor, $id, $version, $action, $reason) {
            $r = SalesOrder::with('lines')->lockForUpdate()->findOrFail($id);
            $this->access($actor, $action, $r->branch_id);
            $this->version($r, $version);
            $review = $r->reviews()->where('status', 'pending')->lockForUpdate()->first();
            if ($r->status !== 'pending_approval' || ! $review || $review->target_version !== $r->version || $review->content_hash !== hash('sha256', json_encode($this->snapshot($r), JSON_THROW_ON_ERROR))) {
                throw ValidationException::withMessages(['order' => 'طلب المراجعة غير صالح أو تغير محتواه.']);
            }
            if ($action === 'reject' && $actor === $review->requested_by) {
                throw ValidationException::withMessages(['order' => 'اسحب طلبك؛ قرار المراجع يجب أن يصدر من مستخدم آخر.']);
            }
            $review->update(['status' => $action === 'reject' ? 'rejected' : 'withdrawn', 'reason' => $reason ?: null, 'decided_by' => $actor, 'decided_at' => now()]);
            $r->update(['status' => 'draft', 'version' => $r->version + 1, 'updated_by' => $actor]);
            event(new SalesOrderChanged($actor, $id, $action === 'reject' ? 'review_rejected' : 'review_withdrawn', null, ['review_id' => $review->id, 'reason' => $reason, 'version' => $r->version, 'status' => 'draft']));
        });
    }

    public function cancel(int $actor, int $id, int $version, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();
        DB::transaction(function () use ($actor, $id, $version, $reason) {
            $r = SalesOrder::with('lines')->lockForUpdate()->findOrFail($id);
            $this->access($actor, 'cancel', $r->branch_id);
            $this->version($r, $version);
            if (! in_array($r->status, ['draft', 'pending_approval'], true)) {
                throw ValidationException::withMessages(['order' => 'إلغاء الأمر المنفذ يتطلب فحص الكميات وإتمام تحرير حجوزات المخزون.']);
            }
            $before = ['status' => $r->status, 'version' => $r->version];
            $r->reviews()->where('status', 'pending')->update(['status' => 'withdrawn', 'reason' => $reason, 'decided_by' => $actor, 'decided_at' => now()]);
            $r->update(['status' => 'cancelled', 'version' => $r->version + 1, 'updated_by' => $actor]);
            event(new SalesOrderChanged($actor, $id, 'unexecuted_cancelled', $before, ['status' => 'cancelled', 'reason' => $reason, 'version' => $r->version, 'lines' => $r->lines->map(fn ($l) => ['id' => $l->id, 'quantity' => $l->quantity])->all(), 'reservation_release' => 'not_required_never_approved']));
        });
    }
}

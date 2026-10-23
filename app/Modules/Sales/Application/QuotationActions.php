<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationChanged;
use App\Modules\Sales\Domain\QuotationTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class QuotationActions
{
    private function access(int $actor, string $action): void
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, $action);
    }

    public function save(int $actor, array $input, ?int $id = null, ?int $expectedVersion = null): Quotation
    {
        $this->access($actor, $id === null ? 'create' : 'edit');

        return DB::transaction(function () use ($actor, $input, $id, $expectedVersion) {
            $r = $id === null ? new Quotation : Quotation::lockForUpdate()->findOrFail($id);
            if ($id !== null) {
                $this->version($r, $expectedVersion);
                if (! $r->editable()) {
                    throw ValidationException::withMessages(['quotation' => 'يمكن تعديل المسودة فقط.']);
                }
            }
            $data = Validator::make($input, [
                'customer_id' => ['required', 'integer', 'min:1'], 'quotation_date' => ['required', 'date_format:Y-m-d'],
                'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:quotation_date'],
                'project_name' => ['nullable', 'string', 'max:200'], 'rfq_reference' => ['nullable', 'string', 'max:100'],
                'discount_mode' => ['required', Rule::in(['percent', 'fixed'])], 'discount_value' => ['required', 'string'],
                'payment_term_id' => ['nullable', 'integer', 'min:1'], 'delivery_term_id' => ['nullable', 'integer', 'min:1'],
                'payment_terms' => ['nullable', 'string', 'max:4000'], 'delivery_terms' => ['nullable', 'string', 'max:4000'],
                'notes' => ['nullable', 'string', 'max:10000'], 'exception_reason' => ['nullable', 'string', 'max:2000'],
                'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*' => ['array'],
                'lines.*.product_id' => ['required', 'integer', 'min:1'], 'lines.*.unit_id' => ['required', 'integer', 'min:1'],
                'lines.*.quantity' => ['required', 'string'], 'lines.*.requested_price' => ['nullable', 'string'],
                'lines.*.description' => ['nullable', 'string', 'max:4000'], 'lines.*.discount_mode' => ['required', Rule::in(['percent', 'fixed'])],
                'lines.*.discount_value' => ['required', 'string'], 'lines.*.override_reason' => ['nullable', 'string', 'max:2000'],
            ])->validate();
            // Livewire restores an unset date input as an empty string.
            $data['valid_until'] = ($data['valid_until'] ?? '') === '' ? null : $data['valid_until'];
            foreach (['payment', 'delivery'] as $kind) {
                $termId = $data[$kind.'_term_id'] ?? null;
                if ($termId) {
                    $term = QuotationTerm::where('kind', $kind)->where('active', true)->find($termId);
                    if (! $term) {
                        throw ValidationException::withMessages([$kind.'_term_id' => 'القالب التجاري لم يعد متاحًا.']);
                    }$data[$kind.'_terms'] = $term->body;
                }
                $data[$kind.'_term_id'] = $termId ?: null;
            }
            $catalog = app(QuotationCatalog::class);
            $customer = $catalog->customer($actor, (int) $data['customer_id']);
            $lines = [];
            foreach ($data['lines'] as $i => $line) {
                DraftAmounts::decimal($line['quantity'], "lines.$i.quantity", true);
                if (($line['requested_price'] ?? '') !== '') {
                    DraftAmounts::decimal($line['requested_price'], "lines.$i.requested_price");
                }
                $snapshot = $catalog->product($actor, (int) $line['product_id'], (int) $line['unit_id'], $line['quantity']);
                $price = app(QuotationPricing::class)->suggested($actor, (int) $line['product_id'], (int) $line['unit_id'], $data['quotation_date']);
                if ($price !== null) {
                    DraftAmounts::decimal($price['amount'] ?? null, "lines.$i.suggested_price");
                }
                $lines[] = ['position' => $i + 1, 'product_id' => $snapshot['id'], 'unit_id' => (int) $line['unit_id'], 'product_snapshot' => $snapshot, 'description' => trim($line['description'] ?? '') ?: $snapshot['name'], 'quantity' => $line['quantity'], 'suggested_price' => $price['amount'] ?? null, 'requested_price' => ($line['requested_price'] ?? '') === '' ? null : $line['requested_price'], 'price_source' => $price === null ? null : $price, 'discount_mode' => $line['discount_mode'], 'discount_value' => $line['discount_value'], 'override_reason' => $line['override_reason'] ?? null];
            }
            $calculation = app(DraftAmounts::class)->calculate($lines, $data['discount_mode'], $data['discount_value']);
            unset($data['lines']);
            $before = $id === null ? null : $r->only(['version', 'status', 'discount_mode', 'discount_value']);
            $r->fill($data);
            $r->customer_snapshot = $customer;
            $r->calculation = $calculation;
            $r->version = $id === null ? 1 : $r->version + 1;
            $r->updated_by = $actor;
            if ($id === null) {
                $r->draft_key = (string) Str::uuid();
                $r->created_by = $actor;
                $r->status = 'draft';
                $r->currency = 'EGP';
                $r->revision = 1;
            }
            $oldLines = $id === null ? [] : $r->lines()->get()->map(fn ($l) => $l->only(['product_id', 'unit_id', 'requested_price', 'discount_mode', 'discount_value']))->all();
            $r->save();
            $r->lines()->delete();
            $r->lines()->createMany($lines);
            event(new QuotationChanged($actor, $r->id, $id === null ? 'draft_created' : 'draft_updated', $before, $r->only(['version', 'status', 'discount_mode', 'discount_value'])));

            foreach ($lines as $position => $line) {
                $old = $oldLines[$position] ?? null;
                if ($line['requested_price'] !== null && ($old === null || $old['product_id'] !== $line['product_id'] || ($old['requested_price'] ?? null) === null || bccomp($old['requested_price'], $line['requested_price'], 8) !== 0)) {
                    event(new QuotationChanged($actor, $r->id, $line['suggested_price'] === null ? 'manual_price_proposed' : (bccomp($line['suggested_price'], $line['requested_price'], 8) !== 0 ? 'price_override_requested' : 'requested_price_set'), null, ['version' => $r->version, 'line' => $position + 1, 'product_id' => $line['product_id'], 'suggested_price' => $line['suggested_price'], 'requested_price' => $line['requested_price']]));
                }
                if ((bccomp($line['discount_value'], '0', 8) > 0 || ($old !== null && bccomp($old['discount_value'], '0', 8) > 0)) && ($old === null || $old['discount_mode'] !== $line['discount_mode'] || bccomp($old['discount_value'], $line['discount_value'], 8) !== 0)) {
                    event(new QuotationChanged($actor, $r->id, 'line_discount_changed', null, ['version' => $r->version, 'line' => $position + 1, 'mode' => $line['discount_mode'], 'value' => $line['discount_value']]));
                }
            }

            return $r->load('lines');
        });
    }

    public function version(Quotation $r, ?int $expected): void
    {
        if ($expected === null || $r->version !== $expected) {
            throw ValidationException::withMessages(['quotation' => 'تغير عرض السعر. أعد تحميل الصفحة قبل تنفيذ الإجراء.']);
        }
    }

    public function cancel(int $actor, int $id, int $version, string $reason): void
    {
        $this->access($actor, 'cancel');
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();
        DB::transaction(function () use ($actor, $id, $version, $reason) {
            $r = Quotation::lockForUpdate()->findOrFail($id);
            $this->version($r, $version);
            if (! in_array($r->status, ['draft', 'pending_approval'], true)) {
                throw ValidationException::withMessages(['quotation' => 'العرض مغلق ولا يمكن تغيير حالته.']);
            }
            $before = $r->only(['status', 'version']);
            $r->approvals()->where('status', 'pending')->update(['status' => 'withdrawn', 'decided_by' => $actor, 'decided_at' => now()]);
            $r->update(['status' => 'cancelled', 'version' => $r->version + 1, 'updated_by' => $actor]);
            event(new QuotationChanged($actor, $id, 'cancelled', $before, ['status' => 'cancelled', 'version' => $r->version, 'reason' => $reason]));
        });
    }

    public function duplicate(int $actor, int $id): Quotation
    {
        $this->access($actor, 'duplicate');
        $this->access($actor, 'create');
        $source = Quotation::with('lines')->findOrFail($id);
        $input = $source->only(['customer_id', 'project_name', 'rfq_reference']);
        $input += ['quotation_date' => now('Africa/Cairo')->toDateString(), 'valid_until' => null, 'discount_mode' => 'percent', 'discount_value' => '0', 'lines' => []];
        foreach ($source->lines as $line) {
            $input['lines'][] = $line->only(['product_id', 'unit_id', 'quantity']) + ['requested_price' => null, 'discount_mode' => 'percent', 'discount_value' => '0', 'description' => ''];
        }

        return DB::transaction(function () use ($actor, $input, $source) {
            $copy = $this->save($actor, $input);
            $copy->update(['copied_from_id' => $source->id]);
            event(new QuotationChanged($actor, $copy->id, 'duplicated', null, ['copied_from_id' => $source->id]));

            return $copy;
        });
    }
}

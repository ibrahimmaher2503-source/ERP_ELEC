<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Purchasing\Domain\ProcurementChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupplierOffers
{
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(10401)');
        DB::select('SELECT pg_advisory_xact_lock(50501)');
    }

    public function fail(string $message): never
    {
        throw ValidationException::withMessages(['offer' => $message]);
    }

    public function audit(int $actor, string $type, int $id, string $action, ?array $before, array $after): void
    {
        event(new ProcurementChanged($actor, $type, $id, $action, $before, $after));
    }

    public function record(int $actor, int $id, string $permission = 'offer_view'): object
    {
        $row = DB::table('purchasing_offers')->find($id);
        abort_unless($row, 404);
        app(PurchasingAccess::class)->authorize($actor, $permission, $row->branch_id);

        return $row;
    }

    public function save(int $actor, array $data, ?int $id, int $version): int
    {
        return DB::transaction(function () use ($actor, $data, $id, $version) {
            $this->lock();
            $v = Validator::make($data, [
                'creation_key' => 'required|uuid', 'rfq_id' => 'required|integer|min:1', 'supplier_id' => 'required|integer|min:1', 'previous_id' => 'nullable|integer|min:1',
                'supplier_reference' => 'required|string|max:160|not_regex:/\x00/', 'document_date' => 'required|date_format:Y-m-d', 'valid_until' => 'nullable|date_format:Y-m-d|after_or_equal:document_date',
                'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'lead_days' => 'nullable|integer|min:0|max:36500',
                'payment_terms' => 'nullable|string|max:3000|not_regex:/\x00/', 'warranty' => 'nullable|string|max:3000|not_regex:/\x00/', 'notes' => 'nullable|string|max:5000|not_regex:/\x00/',
                'reported_tax' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines' => 'required|array|min:1|max:100', 'lines.*.rfq_line_id' => 'required|integer|distinct',
                'lines.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.unit_price' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.discount_amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.specification' => 'nullable|string|max:3000|not_regex:/\x00/',
            ])->validate();
            $rfq = DB::table('purchasing_rfqs')->where('id', $v['rfq_id'])->lockForUpdate()->first();
            abort_unless($rfq, 404);
            app(PurchasingAccess::class)->authorize($actor, $id ? 'offer_update' : 'offer_create', $rfq->branch_id);
            if ($rfq->status !== 'issued') {
                $this->fail('يجب إصدار طلب العرض أولًا.');
            }
            if (! DB::table('purchasing_rfq_suppliers')->where('rfq_id', $rfq->id)->where('supplier_id', $v['supplier_id'])->exists()) {
                $this->fail('المورد ليس ضمن المدعوين لطلب العرض.');
            }
            try {
                $supplier = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $v['supplier_id']));
            } catch (SupplierContractException $e) {
                if ($e->getCode() === 403) {
                    abort(403);
                } $this->fail('المورد غير متاح أو غير نشط.');
            }
            $old = $id ? DB::table('purchasing_offers')->where('id', $id)->lockForUpdate()->first() : DB::table('purchasing_offers')->where('creation_key', $v['creation_key'])->first();
            if ($id && ! $old) {
                abort(404);
            }
            $previous = ! empty($v['previous_id']) ? $this->record($actor, (int) $v['previous_id']) : null;
            if ($previous && ($previous->status !== 'recorded' || $previous->rfq_id !== $rfq->id || $previous->supplier_id !== $supplier->id)) {
                $this->fail('مرجع المراجعة لا يطابق العرض الأصلي.');
            }
            $lines = [];
            $net = '0.00000000';
            foreach ($v['lines'] as $line) {
                $source = DB::table('purchasing_rfq_lines')->where('rfq_id', $rfq->id)->find($line['rfq_line_id']);
                if (! $source || bccomp($line['quantity'], '0', 8) <= 0 || bccomp($line['quantity'], $source->quantity, 8) > 0) {
                    $this->fail('الكمية المعروضة يجب أن تكون موجبة وفي حدود كمية RFQ.');
                }
                // Supplier values are recorded; no tax policy or exchange rate is inferred.
                $raw = bcmul($line['quantity'], $line['unit_price'], 16);
                if (bccomp($raw, bcadd($raw, '0', 8), 16) !== 0) {
                    $this->fail('ناتج السعر والكمية يتجاوز دقة 8 منازل؛ صحح بيانات المورد.');
                }
                $amount = bcsub($raw, $line['discount_amount'], 8);
                if (bccomp($amount, '0', 8) < 0) {
                    $this->fail('الخصم أكبر من قيمة البند.');
                }
                $lines[] = ['rfq_line_id' => (int) $source->id, 'quantity' => bcadd($line['quantity'], '0', 8), 'unit_price' => bcadd($line['unit_price'], '0', 8), 'discount_amount' => bcadd($line['discount_amount'], '0', 8), 'net_amount' => $amount, 'specification' => ($line['specification'] ?? null) ?: null];
                $net = bcadd($net, $amount, 8);
                if (bccomp($net, '999999999999999999999999.99999999', 8) > 0) {
                    $this->fail('إجمالي العرض يتجاوز الدقة المالية المدعومة.');
                }
            }
            $header = array_intersect_key($v, array_flip(['supplier_reference', 'document_date', 'valid_until', 'currency', 'lead_days', 'payment_terms', 'warranty', 'notes', 'reported_tax']));
            foreach (['valid_until', 'lead_days', 'payment_terms', 'warranty', 'notes', 'reported_tax'] as $k) {
                $header[$k] = ($header[$k] ?? null) === '' ? null : ($header[$k] ?? null);
            }
            if ($header['reported_tax'] !== null) {
                $header['reported_tax'] = bcadd($header['reported_tax'], '0', 8);
            }
            $header += ['net_amount' => $net];
            if ($old) {
                if ($old->created_by !== $actor || $old->rfq_id !== $rfq->id || $old->supplier_id !== $supplier->id || $old->creation_key !== $v['creation_key'] || $old->previous_id !== ($previous?->id)) {
                    $this->fail('هوية العرض غير قابلة للتغيير.');
                }
                if (! $id) {
                    $same = collect($header)->every(fn ($value, $key) => (string) ($old->$key ?? '') === (string) ($value ?? ''));
                    $stored = DB::table('purchasing_offer_lines')->where('offer_id', $old->id)->orderBy('id')->get()->map(fn ($l) => array_intersect_key((array) $l, array_flip(array_keys($lines[0]))))->all();
                    if (! $same || $stored !== $lines) {
                        $this->fail('مفتاح حفظ مكرر ببيانات مختلفة.');
                    }

                    return $old->id;
                }
                if ($old->status !== 'draft' || $old->version !== $version) {
                    $this->fail('العرض مثبت أو نسخته قديمة.');
                }
                DB::table('purchasing_offers')->where('id', $id)->update($header + ['version' => $version + 1, 'updated_at' => now()]);
                DB::table('purchasing_offer_lines')->where('offer_id', $id)->delete();
            } else {
                $id = DB::table('purchasing_offers')->insertGetId($header + ['creation_key' => $v['creation_key'], 'rfq_id' => $rfq->id, 'branch_id' => $rfq->branch_id, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'created_by' => $actor, 'previous_id' => $previous?->id, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($lines as $line) {
                DB::table('purchasing_offer_lines')->insert($line + ['offer_id' => $id]);
            }
            $this->audit($actor, 'supplier_offer', $id, 'offer.saved', $old ? (array) $old : null, $header + ['lines' => $lines]);

            return $id;
        });
    }

    public function freeze(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            $this->lock();
            $doc = $this->record($actor, $id, 'offer_update');
            if ($doc->status === 'recorded') {
                return;
            }
            if ($doc->created_by !== $actor || $doc->version !== $version) {
                $this->fail('نسخة قديمة أو ليس عرضك.');
            }
            if (! DB::table('purchasing_offer_lines')->where('offer_id', $id)->exists()) {
                $this->fail('العرض بلا بنود.');
            }
            DB::table('purchasing_offers')->where('id', $id)->update(['status' => 'recorded', 'version' => $version + 1, 'updated_at' => now()]);
            $this->audit($actor, 'supplier_offer', $id, 'offer.recorded', (array) $doc, ['status' => 'recorded', 'version' => $version + 1]);
        });
    }

    public function propose(int $actor, int $id, string $rationale): int
    {
        return DB::transaction(function () use ($actor, $id, $rationale) {
            $this->lock();
            $doc = $this->record($actor, $id);
            $this->assertCurrentRevision($doc);
            app(PurchasingAccess::class)->authorize($actor, 'award_propose', $doc->branch_id);
            Validator::make(['rationale' => $rationale], ['rationale' => 'required|string|max:3000|not_regex:/\x00/'])->validate();
            if ($doc->status !== 'recorded' || ($doc->valid_until && $doc->valid_until < now('Africa/Cairo')->toDateString())) {
                $this->fail('العرض غير مثبت أو انتهت صلاحيته.');
            }
            $lines = DB::table('purchasing_offer_lines')->where('offer_id', $id)->orderBy('rfq_line_id')->get();
            $requested = DB::table('purchasing_rfq_lines')->where('rfq_id', $doc->rfq_id)->get();
            if ($lines->count() !== $requested->count() || $requested->contains(fn ($l) => ! $lines->firstWhere('rfq_line_id', $l->id) || bccomp($lines->firstWhere('rfq_line_id', $l->id)->quantity, $l->quantity, 8) !== 0)) {
                $this->fail('PUR-01: الترسية لمورد واحد يغطي كامل بنود وكميات طلب العرض؛ لا يسمح بالترسية الجزئية أو المجزأة.');
            }
            $existing = DB::table('purchasing_awards')->where('rfq_id', $doc->rfq_id)->whereIn('status', ['proposed', 'approved'])->first();
            if ($existing) {
                if ($existing->offer_id === $id && $existing->created_by === $actor && $existing->rationale === $rationale) {
                    return $existing->id;
                }
                $this->fail('يوجد مقترح أو ترسية معتمدة لهذا RFQ بالفعل.');
            }
            $award = DB::table('purchasing_awards')->insertGetId(['rfq_id' => $doc->rfq_id, 'offer_id' => $id, 'branch_id' => $doc->branch_id, 'created_by' => $actor, 'rationale' => $rationale, 'snapshot' => json_encode(['offer' => (array) $doc, 'lines' => $lines->all()], JSON_THROW_ON_ERROR), 'status' => 'proposed', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'supplier_award', $award, 'award.proposed', null, ['offer_id' => $id, 'rationale' => $rationale]);

            return $award;
        });
    }

    public function decide(int $actor, int $id, int $version, bool $approve): void
    {
        DB::transaction(function () use ($actor, $id, $version, $approve) {
            $this->lock();
            $row = DB::table('purchasing_awards')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            app(PurchasingAccess::class)->authorize($actor, 'award_approve', $row->branch_id);
            if ($row->created_by === $actor) {
                $this->fail('لا يمكنك اعتماد مقترحك بنفسك.');
            }
            if ($row->version !== $version || $row->status !== 'proposed') {
                $this->fail('المقترح تمت معالجته أو نسخته قديمة.');
            }
            $offer = $this->record($actor, $row->offer_id);
            if ($approve) { $this->assertCurrentRevision($offer); }
            if ($approve && $offer->valid_until && $offer->valid_until < now('Africa/Cairo')->toDateString()) {
                $this->fail('انتهت صلاحية العرض.');
            }
            $after = ['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $actor, 'version' => $version + 1, 'updated_at' => now()];
            DB::table('purchasing_awards')->where('id', $id)->update($after);
            $this->audit($actor, 'supplier_award', $id, 'award.'.$after['status'], (array) $row, $after);
        });
    }

    private function assertCurrentRevision(object $offer): void
    {
        if (DB::table('purchasing_offers')->where('previous_id', $offer->id)->where('status', 'recorded')->exists()) {
            $this->fail('A newer recorded supplier revision supersedes this offer.');
        }
    }
}

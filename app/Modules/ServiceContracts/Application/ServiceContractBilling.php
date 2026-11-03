<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ServiceContractBilling
{
    public function validateRevision(int $contract, array $terms): void
    {
        $c = DB::table('service_contracts')->find($contract);
        if (! $c->active_revision_id) {
            return;
        }
        $prior = json_decode(DB::table('service_contract_revisions')->where('id', $c->active_revision_id)->value('data'), true, 512, JSON_THROW_ON_ERROR);
        $sources = DB::table('service_contract_billing_sources')->where('contract_id', $contract)->where('status', '<>', 'rejected')->get();
        if ($sources->isNotEmpty() || DB::table('service_contract_measurements')->where('contract_id', $contract)->exists()) {
            abort_unless($terms['billing_mode'] === $prior['billing_mode'] && ($terms['pricing_tax_mode'] ?? null) === ($prior['pricing_tax_mode'] ?? null), 422, 'لا تغير طريقة أو أساس أسعار فوترة لها مصادر محفوظة.');
        }
        foreach ($sources->where('kind', 'installment')->groupBy('source_code') as $code => $rows) {
            $line = collect($terms['schedule'])->firstWhere('code', $code);
            $used = $rows->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00');
            abort_unless($line && bccomp($line['amount'], $used, 2) >= 0, 422, 'المراجعة تقل عن استحقاقات الأقساط المحفوظة.');
        }
        foreach (DB::table('service_contract_measurements')->where('contract_id', $contract)->where('status', 'approved')->get()->groupBy('boq_code') as $code => $rows) {
            $line = collect($terms['boq'])->firstWhere('code', $code);
            $used = $rows->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 8), '0');
            abort_unless($line && bccomp($line['quantity'], $used, 8) >= 0 && $rows->every(fn ($row) => (int) $line['product_id'] === (int) $row->product_id && bccomp($line['rate'], $row->rate, 2) === 0), 422, 'احتفظ بهوية وسعر وكميات القياسات المعتمدة.');
        }
        $usage = DB::table('service_contract_work')->where('contract_id', $contract)->where('status', '<>', 'released')->get();
        abort_unless((int) $terms['covered_labor_minutes'] >= (int) $usage->sum('labor_minutes'), 422, 'وقت المراجعة أقل من العمل المستخدم.');
        foreach ($usage->groupBy(fn ($w) => $w->equipment_ref.'|'.$w->site_id.'|'.$w->kind) as $rows) {
            $first = $rows->first();
            $equipment = collect($terms['coverage'])->first(fn ($e) => $e['equipment_ref'] === $first->equipment_ref && $e['site_id'] === $first->site_id);
            abort_unless($equipment && (int) $equipment[$first->kind.'_visits'] >= (int) $rows->sum('visits'), 422, 'التغطية المعدلة أقل من الزيارات المحجوزة أو المنفذة.');
        }
        $parts = [];
        foreach ($usage as $work) {
            foreach (json_decode($work->parts_snapshot, true, 512, JSON_THROW_ON_ERROR) as $part) {
                $parts[$part['product_id']] = bcadd($parts[$part['product_id']] ?? '0', $part['quantity'], 8);
            }
        }
        foreach ($parts as $product => $quantity) {
            $line = collect($terms['parts'])->firstWhere('product_id', $product);
            abort_unless($line && ! in_array($product, $terms['excluded_parts']) && bccomp($line['quantity'], $quantity, 8) >= 0, 422, 'تغطية القطع أقل من الاستخدام المحفوظ.');
        }
    }

    private function terms(int $actor, int $contract, string $permission = 'billing_manage'): array
    {
        $c = app(ServiceContractActions::class)->contract($actor, $contract, $permission);
        abort_unless($c->active_revision_id, 409, 'يلزم عقد معتمد وموقع ومفعل.');
        $r = DB::table('service_contract_revisions')->find($c->active_revision_id);

        return [$c, $r, json_decode($r->data, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function dates(array $input): array
    {
        return Validator::make($input, ['invoice_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:invoice_date',
            'tax_mode' => 'required|in:inclusive,exclusive', 'reference' => 'required|string|min:5|max:2000'])->validate();
    }

    private function taxMode(array $terms, string $mode): void
    {
        if (($terms['pricing_tax_mode'] ?? null) !== $mode) {
            throw ValidationException::withMessages(['tax_mode' => 'حدد أساس الضريبة في مراجعة العقد الموقعة؛ يجب أن تطابقه الفوترة.']);
        }
    }

    private function amount(string $value, int $scale = 2): string
    {
        Decimal::value($value, $scale);
        if (bccomp($value, '0', $scale) <= 0) {
            throw ValidationException::withMessages(['amount' => 'يلزم مقدار عشري موجب.']);
        }

        return bcadd($value, '0', $scale);
    }

    private function key(string $key): void
    {
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();
    }

    public function proposeInstallment(int $actor, int $contract, string $lineCode, string $creationKey, array $input): int
    {
        $this->key($creationKey);
        $date = $this->dates($input);
        Decimal::value($input['amount'] ?? null, 2);
        $amount = $this->amount($input['amount']);

        return DB::transaction(function () use ($actor, $contract, $lineCode, $creationKey, $date, $amount) {
            [$c, $r, $d] = $this->terms($actor, $contract);
            abort_unless($d['billing_mode'] === 'installments', 409, 'العقد يدعم طريقة فوترة واحدة فقط.');
            $this->taxMode($d, $date['tax_mode']);
            $line = collect($d['schedule'])->firstWhere('code', $lineCode);
            abort_unless($line && $line['due_date'] <= $date['invoice_date'], 422, 'القسط غير مستحق في تاريخ الفاتورة.');
            $old = DB::table('service_contract_billing_sources')->where('creation_key', $creationKey)->first();
            if ($old) {
                abort_unless((int) $old->contract_id === $contract && (int) $old->created_by === $actor && $old->source_code === $lineCode && bccomp($old->amount, $amount, 2) === 0
                    && $old->invoice_date === $date['invoice_date'] && $old->due_date === $date['due_date'] && $old->tax_mode === $date['tax_mode'] && $old->reference === $date['reference'], 409);

                return (int) $old->id;
            }
            $prior = (string) DB::table('service_contract_billing_sources')->where('contract_id', $contract)->where('source_code', $lineCode)->where('status', '<>', 'rejected')->sum('amount');
            abort_unless(bccomp(bcadd($prior, $amount, 2), $line['amount'], 2) <= 0, 422, 'القسط يتجاوز المتبقي من مصدره الموقع.');
            $id = DB::table('service_contract_billing_sources')->insertGetId($date + ['contract_id' => $contract, 'revision_id' => $r->id, 'kind' => 'installment', 'source_code' => $lineCode, 'amount' => $amount,
                'product_id' => $d['service_product_id'], 'description' => $line['description'], 'revenue_purpose' => 'service_contract_deferred', 'creation_key' => $creationKey, 'created_by' => $actor]);
            app(ServiceContractActions::class)->audit($actor, $c, 'billing.installment_proposed', ['source_id' => $id, 'code' => $lineCode, 'amount' => $amount]);

            return $id;
        }, 3);
    }

    public function approveSource(int $actor, int $source, int $version): void
    {
        DB::transaction(function () use ($actor, $source, $version) {
            app(ServiceContractActions::class)->lock();
            $s = DB::table('service_contract_billing_sources')->lockForUpdate()->find($source);
            abort_unless($s, 404);
            [$c, , $d] = $this->terms($actor, (int) $s->contract_id, 'billing_approve');
            abort_unless($s->status === 'proposed' && (int) $s->version === $version, 409);
            abort_unless((int) $s->created_by !== $actor, 403, 'اعتماد استحقاق الفوترة مستقل.');
            $this->taxMode($d, $s->tax_mode);
            $items = [['product_id' => (int) $s->product_id, 'description' => $s->description, 'quantity' => '1', 'unit_price' => $s->amount, 'discount_amount' => '0.00']];
            if ($s->kind === 'claim') {
                $revision = DB::table('service_contract_revisions')->find($s->revision_id);
                $boq = collect(json_decode($revision->data, true, 512, JSON_THROW_ON_ERROR)['boq']);
                $items = [];
                foreach (DB::table('service_contract_claim_lines as l')->join('service_contract_measurements as m', 'm.id', '=', 'l.measurement_id')->where('l.claim_id', $s->claim_id)->orderBy('l.id')->get(['l.amount', 'm.boq_code']) as $row) {
                    $signed = $boq->firstWhere('code', $row->boq_code);
                    abort_unless($signed, 409);
                    // Each measured line retains its own product tax treatment and allocated cents.
                    $items[] = ['product_id' => (int) $signed['product_id'], 'description' => $signed['description'], 'quantity' => '1', 'unit_price' => $row->amount, 'discount_amount' => '0.00'];
                }
            }
            $tax = app(AccountingApi::class)->calculateTax($actor, 'sale', $s->invoice_date, $s->tax_mode, $items);
            $net = $taxAmount = '0.00';
            $lines = [];
            foreach ($tax['lines'] as $index => $frozen) {
                $frozen['approved_tax_date'] = $s->invoice_date;
                $net = bcadd($net, $frozen['net'], 2);
                $taxAmount = bcadd($taxAmount, $frozen['tax'], 2);
                $lines[] = ['product_id' => $items[$index]['product_id'], 'description' => $items[$index]['description'], 'quantity' => '1',
                    'net_amount' => $frozen['net'], 'tax_amount' => $frozen['tax'], 'total_amount' => bcadd($frozen['net'], $frozen['tax'], 2), 'tax' => $frozen];
            }
            $line = $lines[0]['tax'] + ['lines' => $lines];
            DB::table('service_contract_billing_sources')->where('id', $source)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'version' => $version + 1,
                'net_amount' => $net, 'tax_amount' => $taxAmount, 'total_amount' => bcadd($net, $taxAmount, 2), 'tax_snapshot' => json_encode($line, JSON_THROW_ON_ERROR)]);
            app(ServiceContractActions::class)->audit($actor, $c, 'billing.source_approved', ['source_id' => $source]);
        }, 3);
    }

    public function invoice(int $actor, int $source, string $key): int
    {
        $this->key($key);

        return DB::transaction(function () use ($actor, $source, $key) {
            app(ServiceContractActions::class)->lock();
            $snapshot = $this->source($actor, $source);
            $old = DB::table('service_contract_billing_allocations')->where('source_id', $source)->first();
            if ($old) {
                return (int) $old->invoice_id;
            }
            $id = app(ReceivablesPayablesApi::class)->createContractInvoice($actor, ['billing_source_id' => $source, 'creation_key' => $key]);
            DB::table('service_contract_billing_allocations')->insert(['source_id' => $source, 'invoice_id' => $id, 'created_by' => $actor]);
            DB::table('service_contract_billing_sources')->where('id', $source)->update(['status' => 'invoiced', 'version' => $snapshot['version'] + 1]);
            $c = DB::table('service_contracts')->find($snapshot['contract_id']);
            app(ServiceContractActions::class)->audit($actor, $c, 'billing.invoice_allocated', ['source_id' => $source, 'invoice_id' => $id]);

            return $id;
        }, 3);
    }

    public function source(int $actor, int $source): array
    {
        app(ServiceContractActions::class)->lock();
        $s = DB::table('service_contract_billing_sources')->find($source);
        abort_unless($s, 404);
        [$c] = $this->terms($actor, (int) $s->contract_id);
        abort_unless(in_array($s->status, ['approved', 'invoiced']) && $s->approved_by !== null && $s->tax_snapshot, 409);

        $tax = json_decode($s->tax_snapshot, true, 512, JSON_THROW_ON_ERROR);

        return ['billing_source_id' => $source, 'contract_id' => (int) $c->id, 'revision_id' => (int) $s->revision_id, 'branch_id' => (int) $c->branch_id, 'customer_id' => (int) $c->customer_id,
            'product_id' => (int) $s->product_id, 'description' => $s->description, 'invoice_date' => $s->invoice_date, 'due_date' => $s->due_date,
            'net_amount' => $s->net_amount, 'tax_amount' => $s->tax_amount, 'total_amount' => $s->total_amount,
            'tax' => $tax, 'lines' => $tax['lines'] ?? [['product_id' => (int) $s->product_id, 'description' => $s->description, 'quantity' => '1', 'net_amount' => $s->net_amount,
                'tax_amount' => $s->tax_amount, 'total_amount' => $s->total_amount, 'tax' => $tax]], 'revenue_purpose' => $s->revenue_purpose, 'version' => (int) $s->version];
    }

    public function createMeasurement(int $actor, int $contract, int $workUsageId, string $boqCode, string $quantity, string $key, string $reference): int
    {
        $this->key($key);
        $quantity = $this->amount($quantity, 8);
        Validator::make(compact('reference'), ['reference' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $contract, $workUsageId, $boqCode, $quantity, $key, $reference) {
            [$c, $r, $d] = $this->terms($actor, $contract);
            abort_unless($d['billing_mode'] === 'measured_work', 409);
            $work = DB::table('service_contract_work')->where('contract_id', $contract)->where('status', 'consumed')->find($workUsageId);
            $line = collect($d['boq'])->firstWhere('code', $boqCode);
            abort_unless($work && $line, 422, 'يلزم عمل فعلي مثبت وبند من جدول العقد.');
            $old = DB::table('service_contract_measurements')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->contract_id === $contract && (int) $old->work_id === $workUsageId && $old->boq_code === $boqCode && (int) $old->created_by === $actor && bccomp($old->quantity, $quantity, 8) === 0 && $old->reference === $reference, 409);

                return (int) $old->id;
            }
            abort_unless(bccomp($quantity, $line['quantity'], 8) <= 0, 422);
            $id = DB::table('service_contract_measurements')->insertGetId(['contract_id' => $contract, 'revision_id' => $r->id, 'work_id' => $workUsageId, 'boq_code' => $boqCode,
                'quantity' => $quantity, 'rate' => $line['rate'], 'product_id' => $line['product_id'], 'description' => $line['description'], 'creation_key' => $key, 'reference' => $reference,
                'source_snapshot' => json_encode((array) $work, JSON_THROW_ON_ERROR), 'created_by' => $actor]);
            app(ServiceContractActions::class)->audit($actor, $c, 'measurement.created', ['measurement_id' => $id, 'work_id' => $workUsageId, 'quantity' => $quantity]);

            return $id;
        }, 3);
    }

    public function approveMeasurement(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            app(ServiceContractActions::class)->lock();
            $m = DB::table('service_contract_measurements')->lockForUpdate()->find($id);
            abort_unless($m, 404);
            [$c, , $d] = $this->terms($actor, (int) $m->contract_id, 'billing_approve');
            abort_unless($m->status === 'draft' && (int) $m->version === $version, 409);
            abort_unless((int) $m->created_by !== $actor, 403);
            $line = collect($d['boq'])->firstWhere('code', $m->boq_code);
            $prior = (string) DB::table('service_contract_measurements')->where('contract_id', $c->id)->where('boq_code', $m->boq_code)->where('status', 'approved')->sum('quantity');
            abort_unless($line && bccomp(bcadd($prior, $m->quantity, 8), $line['quantity'], 8) <= 0, 422, 'القياس يتجاوز الكمية المتعاقد عليها.');
            DB::table('service_contract_measurements')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'version' => $version + 1]);
            app(ServiceContractActions::class)->audit($actor, $c, 'measurement.approved', ['measurement_id' => $id]);
        }, 3);
    }

    public function createClaim(int $actor, int $contract, string $key, array $input): int
    {
        $this->key($key);
        $dates = $this->dates($input);
        $d = Validator::make($input, ['period_from' => 'required|date_format:Y-m-d', 'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from', 'lines' => 'required|array|list|min:1|max:500',
            'lines.*' => 'array:measurement_id,quantity', 'lines.*.measurement_id' => 'required|integer|min:1|distinct', 'lines.*.quantity' => 'required|string'])->validate();

        return DB::transaction(function () use ($actor, $contract, $key, $dates, $d) {
            [$c, $r, $terms] = $this->terms($actor, $contract);
            abort_unless($terms['billing_mode'] === 'measured_work', 409);
            $this->taxMode($terms, $dates['tax_mode']);
            abort_unless($d['period_from'] >= $terms['starts_on'] && $d['period_to'] <= $terms['ends_on'] && $d['period_to'] <= $dates['invoice_date'], 422);
            $old = DB::table('service_contract_claims')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->contract_id === $contract && (int) $old->created_by === $actor && $old->period_from === $d['period_from'] && $old->period_to === $d['period_to']
                    && $old->invoice_date === $dates['invoice_date'] && $old->due_date === $dates['due_date'] && $old->tax_mode === $dates['tax_mode'] && $old->reference === $dates['reference'], 409);
                $saved = DB::table('service_contract_claim_lines')->where('claim_id', $old->id)->orderBy('measurement_id')->get();
                abort_unless($saved->count() === count($d['lines']), 409);
                foreach ($d['lines'] as $line) {
                    $match = $saved->firstWhere('measurement_id', (int) $line['measurement_id']);
                    abort_unless($match && bccomp($match->quantity, $this->amount($line['quantity'], 8), 8) === 0, 409);
                }

                return (int) $old->id;
            }
            $id = DB::table('service_contract_claims')->insertGetId($dates + ['contract_id' => $contract, 'revision_id' => $r->id, 'period_from' => $d['period_from'], 'period_to' => $d['period_to'], 'creation_key' => $key, 'created_by' => $actor]);
            foreach ($d['lines'] as $line) {
                $m = DB::table('service_contract_measurements')->where('contract_id', $contract)->where('status', 'approved')->find((int) $line['measurement_id']);
                abort_unless($m, 422, 'القياس غير معتمد لهذا العقد.');
                $work = DB::table('service_contract_work')->find($m->work_id);
                abort_unless($work->service_date >= $d['period_from'] && $work->service_date <= $d['period_to'], 422);
                $quantity = $this->amount($line['quantity'], 8);
                $this->claimCapacity($m, $quantity);
                $amount = Decimal::round(bcmul($quantity, $m->rate, Decimal::SCALE));
                DB::table('service_contract_claim_lines')->insert(['claim_id' => $id, 'measurement_id' => $m->id, 'quantity' => $quantity, 'amount' => $amount]);
            }
            app(ServiceContractActions::class)->audit($actor, $c, 'claim.created', ['claim_id' => $id]);

            return $id;
        }, 3);
    }

    private function claimCapacity(object $measurement, string $quantity): void
    {
        $prior = (string) DB::table('service_contract_claim_lines as l')->join('service_contract_claims as c', 'c.id', '=', 'l.claim_id')->where('l.measurement_id', $measurement->id)->where('c.status', 'approved')->sum('l.quantity');
        abort_unless(bccomp(bcadd($prior, $quantity, 8), $measurement->quantity, 8) <= 0, 422, 'المطالبة تتجاوز المتبقي من القياس المعتمد.');
    }

    public function submitClaim(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            app(ServiceContractActions::class)->lock();
            $c = DB::table('service_contract_claims')->find($id);
            abort_unless($c, 404);
            $this->terms($actor, (int) $c->contract_id);
            abort_unless($c->status === 'draft' && (int) $c->version === $version, 409);
            DB::table('service_contract_claims')->where('id', $id)->update(['status' => 'submitted', 'version' => $version + 1]);
        }, 3);
    }

    public function approveClaim(int $actor, int $id, int $version): int
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            app(ServiceContractActions::class)->lock();
            $claim = DB::table('service_contract_claims')->lockForUpdate()->find($id);
            abort_unless($claim, 404);
            [$c, , $terms] = $this->terms($actor, (int) $claim->contract_id, 'billing_approve');
            abort_unless($claim->status === 'submitted' && (int) $claim->version === $version, 409);
            abort_unless((int) $claim->created_by !== $actor, 403);
            $amount = '0.00';
            foreach (DB::table('service_contract_claim_lines')->where('claim_id', $id)->get() as $line) {
                $m = DB::table('service_contract_measurements')->find($line->measurement_id);
                $this->claimCapacity($m, $line->quantity);
                $amount = bcadd($amount, $line->amount, 2);
            }
            $source = DB::table('service_contract_billing_sources')->insertGetId(['contract_id' => $c->id, 'revision_id' => $claim->revision_id, 'kind' => 'claim', 'claim_id' => $id, 'amount' => $amount,
                'product_id' => $terms['service_product_id'], 'description' => 'مطالبة أعمال عقد #'.$c->id.' / '.$id, 'invoice_date' => $claim->invoice_date, 'due_date' => $claim->due_date,
                'tax_mode' => $claim->tax_mode, 'reference' => $claim->reference, 'creation_key' => $claim->creation_key, 'revenue_purpose' => 'sales', 'created_by' => $claim->created_by]);
            $this->approveSource($actor, $source, 1);
            DB::table('service_contract_claims')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'version' => $version + 1]);
            app(ServiceContractActions::class)->audit($actor, $c, 'claim.approved', ['claim_id' => $id, 'billing_source_id' => $source, 'amount' => $amount]);

            return $source;
        }, 3);
    }

    public function proposeRecognition(int $actor, int $contract, array $workUsageIds, string $amount, string $date, string $key, string $reference): int
    {
        $this->key($key);
        $amount = $this->amount($amount);
        $input = Validator::make(['work_ids' => $workUsageIds, 'date' => $date, 'reference' => $reference], ['work_ids' => 'required|array|list|min:1|max:500', 'work_ids.*' => 'required|integer|min:1|distinct', 'date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Africa/Cairo')->toDateString(), 'reference' => 'required|string|min:5|max:2000'])->validate();
        sort($input['work_ids']);

        return DB::transaction(function () use ($actor, $contract, $amount, $date, $key, $input) {
            [$c, , $d] = $this->terms($actor, $contract);
            abort_unless($d['billing_mode'] === 'installments', 409);
            $old = DB::table('service_contract_recognitions')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->contract_id === $contract && (int) $old->created_by === $actor && bccomp($old->amount, $amount, 2) === 0 && $old->recognition_date === $date
                    && json_decode($old->work_ids, true) === $input['work_ids'] && $old->reference === $input['reference'], 409);

                return (int) $old->id;
            }
            $work = DB::table('service_contract_work')->where('contract_id', $contract)->where('status', 'consumed')->whereIn('id', $input['work_ids'])->get();
            abort_unless($work->count() === count($input['work_ids']) && $work->every(fn ($w) => $w->service_date <= $date), 422, 'يلزم دليل أعمال فعلية مكتملة قبل تاريخ الاعتراف.');
            $id = DB::table('service_contract_recognitions')->insertGetId(['contract_id' => $contract, 'amount' => $amount, 'recognition_date' => $date,
                'work_ids' => json_encode($input['work_ids'], JSON_THROW_ON_ERROR), 'reference' => $input['reference'], 'creation_key' => $key, 'created_by' => $actor]);
            app(ServiceContractActions::class)->audit($actor, $c, 'recognition.proposed', ['recognition_id' => $id, 'amount' => $amount]);

            return $id;
        }, 3);
    }

    public function approveRecognition(int $actor, int $id, int $version): int
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            app(ServiceContractActions::class)->lock();
            $r = DB::table('service_contract_recognitions')->lockForUpdate()->find($id);
            abort_unless($r, 404);
            [$c] = $this->terms($actor, (int) $r->contract_id, 'billing_approve');
            if ($r->status === 'posted') {
                return (int) $r->journal_id;
            }
            abort_unless($r->status === 'proposed' && (int) $r->version === $version, 409);
            abort_unless((int) $r->created_by !== $actor, 403);
            $summary = app(ReceivablesPayablesApi::class)->contractFinancialSummary($actor, (int) $c->id, (int) $c->branch_id, $r->recognition_date);
            $earned = $this->earned((int) $c->id);
            $usedWork = DB::table('service_contract_recognitions')->where('contract_id', $c->id)->where('status', 'posted')
                ->whereNotIn('id', DB::table('service_contract_recognition_reversals')->where('status', 'posted')->select('recognition_id'))->pluck('work_ids')->flatMap(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR))->all();
            abort_if(array_intersect($usedWork, json_decode($r->work_ids, true, 512, JSON_THROW_ON_ERROR)), 409, 'دليل العمل سبق الاعتراف به؛ يلزم تصحيح المصدر أولاً.');
            abort_unless(bccomp(bcadd($earned, $r->amount, 2), $summary['deferred_billed'], 2) <= 0, 422, 'الاعتراف لا يتجاوز الإيراد المؤجل غير المكتسب.');
            $journal = app(AccountingApi::class)->postSourceDocument($actor, (int) $r->created_by, (int) $c->branch_id, $r->recognition_date, 'contract-recognition:'.$id, $r->reference,
                [['purpose' => 'service_contract_deferred', 'debit' => $r->amount, 'credit' => '0.00'], ['purpose' => 'sales', 'debit' => '0.00', 'credit' => $r->amount]]);
            DB::table('service_contract_recognitions')->where('id', $id)->update(['status' => 'posted', 'approved_by' => $actor, 'approved_at' => now(), 'journal_id' => $journal, 'version' => $version + 1]);
            app(ServiceContractActions::class)->audit($actor, $c, 'recognition.posted', ['recognition_id' => $id, 'journal_id' => $journal, 'amount' => $r->amount]);

            return $journal;
        }, 3);
    }

    public function earned(int $contract, ?string $to = null): string
    {
        $positive = (string) DB::table('service_contract_recognitions')->where('contract_id', $contract)->where('status', 'posted')->when($to, fn ($q) => $q->where('recognition_date', '<=', $to))->sum('amount');
        $negative = (string) DB::table('service_contract_recognition_reversals')->where('contract_id', $contract)->where('status', 'posted')->when($to, fn ($q) => $q->where('reversal_date', '<=', $to))->sum('amount');

        return bcsub($positive, $negative, 2);
    }

    public function proposeRecognitionReversal(int $actor, int $recognition, string $date, string $key, string $reason): int
    {
        $this->key($key);
        Validator::make(compact('date', 'reason'), ['date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Africa/Cairo')->toDateString(), 'reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $recognition, $date, $key, $reason) {
            app(ServiceContractActions::class)->lock();
            $r = DB::table('service_contract_recognitions')->find($recognition);
            abort_unless($r && $r->status === 'posted' && $date >= $r->recognition_date, 422);
            [$c] = $this->terms($actor, (int) $r->contract_id);
            $old = DB::table('service_contract_recognition_reversals')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->recognition_id === $recognition && (int) $old->created_by === $actor && $old->reversal_date === $date && $old->reason === $reason, 409);

                return (int) $old->id;
            }
            $id = DB::table('service_contract_recognition_reversals')->insertGetId(['recognition_id' => $recognition, 'contract_id' => $c->id, 'amount' => $r->amount, 'reversal_date' => $date, 'reason' => $reason, 'creation_key' => $key, 'created_by' => $actor]);
            app(ServiceContractActions::class)->audit($actor, $c, 'recognition.reversal_proposed', ['reversal_id' => $id, 'recognition_id' => $recognition]);

            return $id;
        }, 3);
    }

    public function approveRecognitionReversal(int $actor, int $id, int $version): int
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            app(ServiceContractActions::class)->lock();
            $r = DB::table('service_contract_recognition_reversals')->lockForUpdate()->find($id);
            abort_unless($r, 404);
            [$c] = $this->terms($actor, (int) $r->contract_id, 'billing_approve');
            if ($r->status === 'posted') {
                return (int) $r->journal_id;
            }
            abort_unless($r->status === 'proposed' && (int) $r->version === $version, 409);
            abort_unless((int) $r->created_by !== $actor, 403);
            $journal = app(AccountingApi::class)->postSourceDocument($actor, (int) $r->created_by, (int) $c->branch_id, $r->reversal_date, 'contract-recognition-reversal:'.$id, $r->reason,
                [['purpose' => 'sales', 'debit' => $r->amount, 'credit' => '0.00'], ['purpose' => 'service_contract_deferred', 'debit' => '0.00', 'credit' => $r->amount]]);
            DB::table('service_contract_recognition_reversals')->where('id', $id)->update(['status' => 'posted', 'approved_by' => $actor, 'approved_at' => now(), 'journal_id' => $journal, 'version' => $version + 1]);
            app(ServiceContractActions::class)->audit($actor, $c, 'recognition.reversed', ['reversal_id' => $id, 'recognition_id' => $r->recognition_id, 'journal_id' => $journal]);

            return $journal;
        }, 3);
    }

    public function correctContractBilling(int $actor, int $source, string $key, string $amount, string $noteDate, string $reason): int
    {
        $this->key($key);
        $amount = $this->amount($amount);
        Validator::make(['date' => $noteDate, 'reason' => $reason], ['date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $source, $key, $amount, $noteDate, $reason) {
            $s = $this->source($actor, $source);
            abort_unless(DB::table('service_contract_billing_allocations')->where('source_id', $source)->exists(), 409);
            $old = DB::table('service_contract_billing_corrections')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->source_id === $source && (int) $old->created_by === $actor && bccomp($old->amount, $amount, 2) === 0 && $old->note_date === $noteDate && $old->reason === $reason, 409);
                $id = (int) $old->id;
            } else {
                $id = DB::table('service_contract_billing_corrections')->insertGetId(['source_id' => $source, 'creation_key' => $key, 'amount' => $amount, 'note_date' => $noteDate, 'reason' => $reason, 'created_by' => $actor]);
            }
            $note = app(ReceivablesPayablesApi::class)->createContractCreditNote($actor, $id);
            app(ServiceContractActions::class)->audit($actor, DB::table('service_contracts')->find($s['contract_id']), 'billing.correction_prepared', ['correction_id' => $id, 'credit_note_id' => $note]);

            return $note;
        }, 3);
    }
}

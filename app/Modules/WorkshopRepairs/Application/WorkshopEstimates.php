<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkshopEstimates
{
    public function propose(int $actor, int $job, int $version, array $input): int
    {
        $rules = ['product_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:date', 'warranty_days' => 'required|integer|min:0|max:3650', 'warranty_conditions' => 'required|string|min:5|max:5000', 'pricing_reference' => 'required|string|min:5|max:5000'];
        foreach (['parts_amount', 'labor_amount', 'other_amount', 'billable_amount'] as $field) {
            $rules[$field] = ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'];
        }
        foreach (['parts_selling', 'labor_selling', 'other_selling', 'discount_amount'] as $field) {
            $rules[$field] = ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'];
        }
        $d = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($actor, $job, $version, $d) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'estimate_manage', $version);
            app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'cost_view');
            $a->project($actor, $w);
            abort_unless(in_array($w->status, ['inspection', 'waiting_approval', 'rejected', 'approved']) && $w->diagnosis && json_decode($w->required_tests, true), 409, 'يلزم تشخيص وخطة اختبارات.');
            abort_if(DB::table('workshop_work_records')->where('job_id', $job)->exists()
                || DB::table('workshop_part_issues')->whereIn('requirement_id', DB::table('workshop_part_requirements')->where('job_id', $job)->select('id'))->exists(), 409, 'بدأ التنفيذ الفعلي، ولا يجوز تغيير أساس العرض.');
            $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $d['product_id']))->product;
            abort_unless($p['active'] && $p['kind'] === 'service', 422);
            $billable = in_array($w->billing_mode, ['billable', 'partial']);
            $selling = bcsub(bcadd(bcadd($d['parts_selling'], $d['labor_selling'], 2), $d['other_selling'], 2), $d['discount_amount'], 2);
            abort_unless(bccomp($selling, '0', 2) >= 0 && bccomp($selling, $d['billable_amount'], 2) === 0, 422, 'قيمة العرض يجب أن تساوي أسعار البيع بعد الخصم.');
            $price = app(QuotationPricing::class)->suggested($actor, (int) $d['product_id'], (int) $p['base_unit_id'], $d['date']);
            abort_unless($billable ? bccomp($d['billable_amount'], '0', 2) > 0 : bccomp($d['billable_amount'], '0', 2) === 0, 422);
            $tax = app(AccountingApi::class)->calculateTax($actor, 'sale', $d['date'], 'exclusive', [['product_id' => (int) $d['product_id'], 'quantity' => '1', 'unit_price' => $d['billable_amount'], 'discount_amount' => '0.00']]);
            $line = $tax['lines'][0];
            $line['approved_tax_date'] = $d['date'];
            $revision = (int) DB::table('workshop_estimates')->where('job_id', $job)->max('revision') + 1;
            $commercial = ['selling' => array_intersect_key($d, array_flip(['parts_selling', 'labor_selling', 'other_selling', 'discount_amount'])), 'price_source' => $price, 'service_unit_id' => $p['base_unit_id'], 'exception' => $price === null || bccomp($price['amount'], $d['billable_amount'], 8) !== 0, 'reference' => $d['pricing_reference']];
            $id = DB::table('workshop_estimates')->insertGetId(array_diff_key($d, array_flip(['date', 'parts_selling', 'labor_selling', 'other_selling', 'discount_amount'])) + ['commercial_snapshot' => json_encode($commercial, JSON_THROW_ON_ERROR), 'job_id' => $job, 'revision' => $revision, 'tax_amount' => $line['tax'], 'tax_snapshot' => json_encode($line, JSON_THROW_ON_ERROR), 'proposed_by' => $actor]);
            $a->touch($actor, $w, 'estimate.proposed', ['estimate_id' => $id], ['status' => 'waiting_approval']);

            return $id;
        }, 5);
    }

    public function decide(int $actor, int $job, int $version, int $estimate, bool $accepted, string $evidence): void
    {
        // Kept as the internal approval entrypoint. Customer acceptance is a separate Action.
        abort_unless($accepted, 422, 'أنشئ تقديراً مصححاً أو سجل قرار العميل بعد إصدار العرض.');
        app(RepairOffers::class)->approve($actor, $job, $version, $estimate, $evidence);
    }

    public function authorized(object $job): void
    {
        app(RepairOffers::class)->authorized($job);
    }

    public function proposeRate(int $actor, array $input): int
    {
        $d = Validator::make($input, ['branch_id' => 'required|integer', 'technician_id' => 'required|integer', 'hourly_cost' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'], 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'required|date_format:Y-m-d|after_or_equal:effective_from', 'reference' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $d) {
            app(WorkshopActions::class)->lock();
            app(WorkshopAccess::class)->authorize($actor, (int) $d['branch_id'], 'labor_record');
            app(WorkshopAccess::class)->authorize((int) $d['technician_id'], (int) $d['branch_id'], 'execute');
            abort_unless(bccomp($d['hourly_cost'], '0', 2) > 0, 422);
            $id = DB::table('workshop_rates')->insertGetId($d + ['proposed_by' => $actor]);
            app(WorkshopActions::class)->audit($actor, null, null, 'rate.proposed', ['rate_id' => $id]);

            return $id;
        }, 5);
    }

    public function approveRate(int $actor, int $rate): void
    {
        DB::transaction(function () use ($actor, $rate) {
            app(WorkshopActions::class)->lock();
            $r = DB::table('workshop_rates')->lockForUpdate()->find($rate);
            abort_unless($r, 404);
            app(WorkshopAccess::class)->authorize($actor, $r->branch_id, 'rate_approve');
            abort_if($r->proposed_by === $actor, 403);
            abort_unless($r->approved_by === null, 409);
            abort_if(DB::table('workshop_rates')->where('branch_id', $r->branch_id)->where('technician_id', $r->technician_id)->whereNotNull('approved_by')->where('effective_from', '<=', $r->effective_to)->where('effective_to', '>=', $r->effective_from)->exists(), 409);
            DB::table('workshop_rates')->where('id', $rate)->update(['approved_by' => $actor]);
            app(WorkshopActions::class)->audit($actor, null, null, 'rate.approved', ['rate_id' => $rate]);
        }, 5);
    }
}

<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Source-specific repair offers reuse Sales price lookup, Accounting tax and System issuance. */
final class RepairOffers
{
    public function contract(int $actor, int $job, int $version, string $reference): void
    {
        Validator::make(compact('reference'), ['reference' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $reference) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'estimate_approve', $version);
            abort_unless($w->billing_mode === 'contract' && $w->contract_id !== null && $actor !== (int) $w->created_by
                && in_array($w->status, ['inspection', 'waiting_approval']) && $w->diagnosis
                && collect(json_decode($w->required_tests, true))->contains('required', true), 409, 'يلزم تشخيص وخطة فحص واعتماد تعاقدي مستقل.');
            app(ServiceContractsApi::class)->reserveWork($actor, (int) $w->contract_id, 'WorkshopRepairs', $job);
            $a->touch($actor, $w, 'contract.authorized', ['contract_id' => $w->contract_id, 'reference' => $reference], ['status' => 'approved']);
        }, 3);
    }

    private function estimate(int $job, int $estimate): object
    {
        $e = DB::table('workshop_estimates')->where('job_id', $job)->orderByDesc('revision')->first();
        abort_unless($e && $e->id === $estimate && $e->valid_until, 409, 'اختر أحدث تقدير مكتمل الشروط.');

        return $e;
    }

    public function approve(int $actor, int $job, int $version, int $estimate, string $reference): void
    {
        Validator::make(compact('reference'), ['reference' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $estimate, $reference) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'estimate_approve', $version);
            $a->project($actor, $w);
            $e = $this->estimate($job, $estimate);
            abort_unless($w->status === 'waiting_approval', 409);
            abort_if($e->proposed_by === $actor, 403, 'اعتماد السعر يتطلب شخصاً مستقلاً.');
            $old = DB::table('workshop_quote_approvals')->where('estimate_id', $estimate)->first();
            if ($old) {
                abort_unless($old->approved_by === $actor && $old->reference === $reference, 409, 'سجل اعتماد السعر ثابت.');

                return;
            }
            DB::table('workshop_quote_approvals')->insert(['estimate_id' => $estimate, 'approved_by' => $actor, 'reference' => $reference]);
            $a->touch($actor, $w, 'quote.pricing_approved', ['estimate_id' => $estimate, 'reference' => $reference]);
        }, 5);
    }

    public function issue(int $actor, int $job, int $version, int $estimate): int
    {
        return DB::transaction(function () use ($actor, $job, $version, $estimate) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'quote_issue');
            $e = $this->estimate($job, $estimate);
            $old = DB::table('workshop_quotes')->where('estimate_id', $estimate)->first();
            if ($old) {
                return $old->id;
            }
            abort_unless($w->version === $version && $w->status === 'waiting_approval'
                && DB::table('workshop_quote_approvals')->where('estimate_id', $estimate)->exists()
                && $e->valid_until >= now('Africa/Cairo')->toDateString(), 409);
            $tax = json_decode($e->tax_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $commercial = json_decode($e->commercial_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $doc = app(OfficialDocuments::class)->issue($actor, 'workshop_quote', (string) $job, $e->revision, $tax['approved_tax_date'], [
                'title' => 'عرض إصلاح معدة', 'fields' => ['أمر الإصلاح' => $w->number, 'العميل' => $w->customer_name, 'التشخيص' => $w->diagnosis,
                    'صالح حتى' => $e->valid_until, 'شروط الضمان' => $e->warranty_conditions, 'مدة الضمان بالأيام' => (string) $e->warranty_days],
                'columns' => ['البيان', 'السعر'], 'rows' => [['قطع الغيار', $commercial['selling']['parts_selling']], ['أجور الإصلاح', $commercial['selling']['labor_selling']], ['رسوم أخرى', $commercial['selling']['other_selling']], ['الخصم', $commercial['selling']['discount_amount']]],
                'totals' => ['الصافي' => $e->billable_amount, 'الضريبة' => $e->tax_amount, 'الإجمالي' => bcadd($e->billable_amount, $e->tax_amount, 2)]]);
            $id = DB::table('workshop_quotes')->insertGetId(['estimate_id' => $estimate, 'job_id' => $job, 'document_id' => $doc['id'], 'issued_by' => $actor]);
            $a->touch($actor, $w, 'quote.issued', ['estimate_id' => $estimate, 'document_id' => $doc['id']]);

            return $id;
        }, 5);
    }

    public function accept(int $actor, int $job, int $version, int $estimate, bool $accepted, string $evidence): void
    {
        Validator::make(compact('evidence'), ['evidence' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $estimate, $accepted, $evidence) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'customer_acceptance', $version);
            $e = $this->estimate($job, $estimate);
            abort_unless($w->customer_id !== null && $w->status === 'waiting_approval' && $e->valid_until >= now('Africa/Cairo')->toDateString()
                && DB::table('workshop_quotes')->where('estimate_id', $estimate)->exists(), 409);
            DB::table('workshop_estimate_approvals')->insert(['estimate_id' => $estimate, 'approved_by' => $actor, 'customer_accepted' => $accepted, 'evidence' => $evidence]);
            $a->touch($actor, $w, 'quote.customer_decision', ['estimate_id' => $estimate, 'accepted' => $accepted, 'evidence' => $evidence], ['status' => $accepted ? 'approved' : 'rejected']);
        }, 5);
    }

    public function nonbillable(int $actor, int $job, int $version, string $kind, ?int $sourceJob, string $reference): void
    {
        Validator::make(compact('kind', 'reference'), ['kind' => 'required|in:warranty,internal,goodwill', 'reference' => 'required|string|min:10|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $kind, $sourceJob, $reference) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'nonbillable_approve', $version);
            abort_if($w->created_by === $actor, 403);
            abort_unless(in_array($w->billing_mode, ['warranty', 'nonbillable']) && in_array($w->status, ['inspection', 'waiting_approval']), 409);
            $i = DB::table('workshop_intakes')->find($w->intake_id);
            abort_unless($w->diagnosis && collect(json_decode($w->required_tests, true))->contains('required', true), 409);
            if ($kind === 'internal') {
                abort_unless(! $i->customer_owned && ($i->company_serial_id || $i->company_movement_id) && $i->isolation_id && $w->billing_mode === 'nonbillable', 422);
            } elseif ($kind === 'warranty') {
                abort_unless($i->customer_owned && $w->billing_mode === 'warranty' && $sourceJob, 422);
                $prior = DB::table('workshop_jobs as j')->join('workshop_intakes as i', 'i.id', '=', 'j.intake_id')
                    ->join('workshop_deliveries as d', 'd.job_id', '=', 'j.id')->where('j.id', $sourceJob)->where('j.status', 'delivered')
                    ->where('i.equipment_identity', $i->equipment_identity)->where('j.customer_id', $w->customer_id)->first(['j.id', 'd.delivered_at']);
                $quote = $prior ? DB::table('workshop_estimates as e')->join('workshop_estimate_approvals as a', 'a.estimate_id', '=', 'e.id')->where('e.job_id', $sourceJob)->where('a.customer_accepted', true)->orderByDesc('e.revision')->first(['e.warranty_days']) : null;
                abort_unless($prior && $quote && $quote->warranty_days > 0 && CarbonImmutable::parse($prior->delivered_at)->addDays($quote->warranty_days)->gte(now()), 409, 'لا يوجد ضمان سابق ساري لنفس المعدة.');
            } else {
                abort_unless($i->customer_owned && $w->billing_mode === 'nonbillable' && $sourceJob === null, 422);
            }
            DB::table('workshop_nonbillable_authorizations')->insert(['job_id' => $job, 'kind' => $kind, 'source_job_id' => $sourceJob, 'reference' => $reference, 'approved_by' => $actor]);
            $a->touch($actor, $w, 'nonbillable.authorized', ['kind' => $kind, 'source_job_id' => $sourceJob, 'reference' => $reference], ['status' => 'approved']);
        }, 5);
    }

    public function authorized(object $job): void
    {
        if ($job->billing_mode === 'contract') {
            abort_unless($job->contract_id !== null && DB::table('workshop_activities')->where('job_id', $job->id)->where('action', 'contract.authorized')->exists(), 409, 'يلزم اعتماد الإصلاح التعاقدي.');
        } elseif (in_array($job->billing_mode, ['warranty', 'nonbillable'])) {
            abort_unless(DB::table('workshop_nonbillable_authorizations')->where('job_id', $job->id)->exists(), 409, 'يلزم أساس غير مدفوع معتمد بشكل مستقل.');
        } else {
            $e = DB::table('workshop_estimates')->where('job_id', $job->id)->orderByDesc('revision')->first();
            abort_unless($e && DB::table('workshop_quotes')->where('estimate_id', $e->id)->exists()
                && DB::table('workshop_estimate_approvals')->where('estimate_id', $e->id)->where('customer_accepted', true)->exists(), 409, 'يلزم قبول العميل لنفس العرض الصادر.');
        }
    }
}

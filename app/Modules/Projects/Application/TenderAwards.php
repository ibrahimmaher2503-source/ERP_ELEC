<?php

namespace App\Modules\Projects\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Sales\Application\Contracts\QuotationsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

final class TenderAwards
{
    public function result(int $actor, int $id, int $version, array $input): int
    {
        $d = Validator::make($input, ['outcome' => 'required|in:awarded,lost,cancelled,negotiating', 'decided_on' => 'required|date_format:Y-m-d', 'reference' => 'required|string|min:5|max:5000', 'evidence_id' => 'required|integer|min:1', 'award_amount' => 'nullable|string'])->validate();
        $d['award_amount'] = $d['outcome'] === 'awarded' ? Decimal::value($d['award_amount'] ?? null, 2) : null;
        abort_if($d['outcome'] === 'awarded' && bccomp($d['award_amount'], '0', 2) <= 0, 422);

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'award');
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            abort_unless($t->submitted_id && in_array($t->status, ['submitted', 'awaiting_result', 'negotiating']), 409);
            $sub = DB::table('project_tender_submissions')->where('tender_id', $id)->find($t->submitted_id);
            abort_unless($sub && $sub->financial_id === $t->financial_id, 409);
            abort_if($d['decided_on'] > now('Africa/Cairo')->toDateString() || $d['decided_on'] < substr($sub->submitted_at, 0, 10), 422);
            $doc = app(TenderDocuments::class)->evidence($id, (int) $d['evidence_id']);
            abort_unless($doc->classification === 'award', 422, 'يلزم دليل نتيجة مصنف.');
            $result = DB::table('project_tender_results')->insertGetId($d + ['tender_id' => $id, 'submission_id' => $sub->id, 'recorded_by' => $actor]);
            $a->touch($actor, $t, 'result.recorded', ['result_id' => $result, 'outcome' => $d['outcome'], 'submission_id' => $sub->id], ['status' => $d['outcome']]);

            return $result;
        }, 5);
    }

    public function source(int $actor, int $id, int $result): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(TenderActions::class)->lock();
        $t = DB::table('project_tenders')->lockForUpdate()->find($id);
        abort_unless($t, 404);
        $a = app(TenderAccess::class);
        $a->authorize($actor, $t->branch_id);
        $a->authorize($actor, $t->branch_id, 'handoff');
        $a->authorize($actor, $t->branch_id, 'financial_view');
        $r = DB::table('project_tender_results')->where('tender_id', $id)->orderByDesc('id')->first();
        abort_unless($t->status === 'awarded' && $t->customer_id && $r && $r->id === $result && $r->outcome === 'awarded' && $r->submission_id === $t->submitted_id, 409, 'يلزم ترسية حالية وعميل معروف.');
        $s = DB::table('project_tender_submissions')->find($r->submission_id);
        $f = DB::table('project_tender_financial')->find($s->financial_id);

        return ['tender_id' => $id, 'result_id' => $result, 'submission_id' => $s->id, 'number' => $t->number, 'customer_id' => $t->customer_id, 'branch_id' => $t->branch_id, 'financial_id' => $f->id, 'financial_sha256' => $f->sha256, 'financial' => json_decode($f->snapshot, true), 'award_amount' => $r->award_amount, 'title' => $t->title];
    }

    public function plan(int $actor, int $id, int $version, array $input): int
    {
        $destinations = $input['destinations'] ?? null;
        abort_unless(is_array($destinations) && count($destinations) >= 1 && count($destinations) <= 3 && ! array_diff(array_keys($destinations), ['project', 'quotation', 'contract']), 422, 'اختر وجهات تحويل مدعومة.');
        $d = Validator::make($input, ['result_id' => 'required|integer', 'reference' => 'required|string|min:5|max:5000', 'destinations' => 'required|array|min:1|max:3', 'destinations.project' => 'sometimes|array', 'destinations.quotation' => 'sometimes|array', 'destinations.contract' => 'sometimes|array'])->validate();
        $d['destinations'] = $destinations;
        abort_unless(! array_diff(array_keys($d['destinations']), ['project', 'quotation', 'contract']), 422);

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'handoff');
            $this->source($actor, $id, (int) $d['result_id']);
            $pid = DB::table('project_tender_handoff_plans')->insertGetId(['tender_id' => $id, 'result_id' => $d['result_id'], 'snapshot' => json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'proposed_by' => $actor]);
            $a->touch($actor, $t, 'handoff.planned', ['plan_id' => $pid, 'destinations' => array_keys($d['destinations'])]);

            return $pid;
        }, 5);
    }

    public function quotationSource(int $actor, int $id, int $result, array $mapping): array
    {
        $source = $this->source($actor, $id, $result);
        $plans = DB::table('project_tender_handoff_plans as p')->join('project_tender_handoff_approvals as a', 'a.plan_id', '=', 'p.id')->where('p.tender_id', $id)->where('p.result_id', $result)->get(['p.*']);
        foreach ($plans as $plan) {
            $d = json_decode($plan->snapshot, true);
            if (isset($d['destinations']['quotation']) && hash_equals(TenderActions::hash($d['destinations']['quotation']), TenderActions::hash($mapping))) {
                return $source + ['plan_id' => $plan->id];
            }
        }
        abort(409, 'تعيين عرض المبيعات يحتاج خطة تحويل معتمدة مطابقة.');
    }

    public function contractSource(int $actor, int $id, int $result, array $mapping): array
    {
        $source = $this->source($actor, $id, $result);
        $plans = DB::table('project_tender_handoff_plans as p')->join('project_tender_handoff_approvals as a', 'a.plan_id', '=', 'p.id')->where('p.tender_id', $id)->where('p.result_id', $result)->get(['p.*']);
        foreach ($plans as $plan) {
            $d = json_decode($plan->snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (isset($d['destinations']['contract']) && hash_equals(TenderActions::hash($d['destinations']['contract']), TenderActions::hash($mapping))) {
                return $source + ['plan_id' => (int) $plan->id];
            }
        }
        abort(409, 'إنشاء العقد يحتاج خطة تحويل معتمدة مطابقة لقرار الترسية.');
    }

    public function approve(int $actor, int $id, int $version, int $plan, string $reference): void
    {
        Validator::make(compact('reference'), ['reference' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $plan, $reference) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'handoff');
            $p = DB::table('project_tender_handoff_plans')->where('tender_id', $id)->find($plan);
            abort_unless($p, 404);
            $this->source($actor, $id, $p->result_id);
            abort_if($p->proposed_by === $actor, 403, 'اعتماد خطة التحويل مستقل.');
            abort_if(DB::table('project_tender_handoff_approvals')->where('plan_id', $plan)->exists(), 409);
            DB::table('project_tender_handoff_approvals')->insert(['plan_id' => $plan, 'approved_by' => $actor, 'reference' => $reference]);
            $a->touch($actor, $t, 'handoff.approved', ['plan_id' => $plan, 'reference' => $reference]);
        }, 5);
    }

    public function convert(int $actor, int $id, int $version, int $plan): array
    {
        return DB::transaction(function () use ($actor, $id, $version, $plan) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'handoff');
            $p = DB::table('project_tender_handoff_plans')->where('tender_id', $id)->find($plan);
            abort_unless($p && DB::table('project_tender_handoff_approvals')->where('plan_id', $plan)->exists(), 409, 'يلزم اعتماد الخطة المحددة.');
            $source = $this->source($actor, $id, $p->result_id);
            $d = json_decode($p->snapshot, true);
            $out = [];
            foreach ($d['destinations'] as $kind => $input) {
                $old = DB::table('project_tender_handoffs')->where('tender_id', $id)->where('destination', $kind)->first();
                if ($old) {
                    abort_unless($old->plan_id === $plan, 409, 'تم التحويل بخطة أخرى.');
                    $out[$kind] = $old->target_id;

                    continue;
                }
                if ($kind === 'project') {
                    abort_if(isset($input['branch_id']) && (int) $input['branch_id'] !== $t->branch_id, 422);
                    abort_if(isset($input['customer_id']) && (int) $input['customer_id'] !== $t->customer_id, 422);
                    $input['branch_id'] = $t->branch_id;
                    $input['customer_id'] = $t->customer_id;
                    $input['description'] = ($input['description'] ?? '')."\n".$t->number.' / submission '.$source['submission_id'].' / '.$source['financial_sha256'];
                    $target = app(ProjectActions::class)->save($actor, null, 0, Uuid::uuid5(Uuid::NAMESPACE_URL, 'tender-project:'.$id), $input)->id;
                } elseif ($kind === 'contract') {
                    $target = app(\App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi::class)->createTenderDraft($actor, $id, $p->result_id, $input);
                } else {
                    $target = app(QuotationsApi::class)->createTenderDraft($actor, $id, $p->result_id, $input);
                }
                DB::table('project_tender_handoffs')->insert(['tender_id' => $id, 'plan_id' => $plan, 'destination' => $kind, 'target_id' => $target, 'source_snapshot' => json_encode(array_diff_key($source, ['financial' => true]), JSON_THROW_ON_ERROR), 'created_by' => $actor]);
                $out[$kind] = $target;
            }
            $a->touch($actor, $t, 'handoff.converted', ['plan_id' => $plan, 'targets' => $out]);

            return $out;
        }, 5);
    }

    public function guarantee(int $actor, int $id, int $version, array $input): int
    {
        $d = Validator::make($input, ['reference' => 'required|string|max:200', 'kind' => 'required|in:fee,bid_bond,performance', 'guarantee_type' => 'required|string|max:100', 'bank' => 'nullable|string|max:200', 'amount' => 'required|string', 'issued_on' => 'required|date_format:Y-m-d', 'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:issued_on', 'evidence_id' => 'required|integer'])->validate();
        $d['amount'] = Decimal::value($d['amount'], 2);
        abort_unless(bccomp($d['amount'], '0', 2) > 0, 422);
        $d['bank'] ??= '';

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version);
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            $doc = app(TenderDocuments::class)->evidence($id, (int) $d['evidence_id']);
            abort_unless($doc->classification === 'guarantee', 422);
            abort_if(DB::table('project_tender_guarantees')->where('tender_id', $id)->where('reference', $d['reference'])->where('kind', $d['kind'])->exists(), 409, 'الضمان مسجل.');
            $gid = DB::table('project_tender_guarantees')->insertGetId($d + ['tender_id' => $id, 'recorded_by' => $actor]);
            $a->touch($actor, $t, 'guarantee.recorded', ['guarantee_id' => $gid, 'operational_only' => true]);

            return $gid;
        }, 5);
    }

    public function settleGuarantee(int $actor, int $id, int $version, int $guarantee, string $status, string $reference, int $evidence): void
    {
        Validator::make(compact('status', 'reference'), ['status' => 'required|in:released,returned,forfeited', 'reference' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $guarantee, $status, $reference, $evidence) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version);
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            abort_unless(DB::table('project_tender_guarantees')->where('tender_id', $id)->where('id', $guarantee)->exists(), 422);
            abort_if(DB::table('project_tender_guarantee_events')->where('guarantee_id', $guarantee)->exists(), 409, 'حالة الضمان محسومة.');
            $doc = app(TenderDocuments::class)->evidence($id, $evidence);
            abort_unless($doc->classification === 'guarantee', 422);
            DB::table('project_tender_guarantee_events')->insert(['guarantee_id' => $guarantee, 'status' => $status, 'reference' => $reference, 'evidence_id' => $evidence, 'recorded_by' => $actor]);
            $a->touch($actor, $t, 'guarantee.'.$status, ['guarantee_id' => $guarantee, 'reference' => $reference, 'operational_only' => true]);
        }, 5);
    }
}

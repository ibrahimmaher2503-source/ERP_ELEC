<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class WorkshopExecution
{
    public function state(int $actor, int $job, int $version, string $action, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $action, $reason) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'execute', $version);
            abort_if($w->pending_transfer_id !== null, 409);
            app(RepairOffers::class)->authorized($w);
            $a->project($actor, $w);
            $a->project($actor, $w);
            app(WorkshopAccess::class)->technician($actor, $w);
            $to = match ($action) {
                'start' => $w->status === 'approved' ? 'repair' : null,'waiting_parts' => in_array($w->status, ['repair', 'approved']) ? 'waiting_parts' : null,'resume' => $w->status === 'waiting_parts' ? 'repair' : ($w->status === 'on_hold' ? $w->hold_from : null),'hold' => in_array($w->status, ['approved', 'repair', 'waiting_parts']) ? 'on_hold' : null,'testing' => $w->status === 'repair' ? 'testing' : null,'repair' => $w->status === 'testing' ? 'repair' : null,default => null
            };
            abort_unless($to, 409);
            if ($action === 'start') {
                app(RepairOffers::class)->authorized($w);
            }
            if ($to === 'testing') {
                abort_unless(DB::table('workshop_work_records')->where('job_id', $job)->exists(), 409, 'سجل العمل الفعلي أولاً.');
            }
            $a->touch($actor, $w, 'job.'.$action, ['reason' => $reason], ['status' => $to, 'hold_from' => $action === 'hold' ? $w->status : null]);
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($w->intake_id);
            DB::table('workshop_intakes')->where('id', $i->id)->update(['status' => $to === 'approved' ? 'waiting_approval' : $to, 'version' => $i->version + 1, 'updated_at' => now()]);
            $a->custody($actor, $i, $i->location, $to, $reason, (string) Str::uuid(), $i->arrival_condition);
        }, 5);
    }

    public function record(int $actor, int $job, int $version, string $key, array $input): int
    {
        $d = Validator::make($input, ['started_at' => 'required|date', 'ended_at' => 'required|date|after:started_at', 'work_performed' => 'required|string|min:5|max:10000', 'observations' => 'required|string|min:3|max:10000', 'work_type' => 'required|string|max:200'])->validate();
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $job, $version, $key, $d) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'execute');
            abort_if($w->pending_transfer_id !== null, 409);
            app(RepairOffers::class)->authorized($w);
            app(WorkshopAccess::class)->technician($actor, $w);
            app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'labor_record');
            $start = CarbonImmutable::parse($d['started_at'])->utc();
            $end = CarbonImmutable::parse($d['ended_at'])->utc();
            $old = DB::table('workshop_work_records')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->job_id === $job && $old->technician_id === $actor && CarbonImmutable::parse($old->started_at)->equalTo($start) && CarbonImmutable::parse($old->ended_at)->equalTo($end) && $old->work_performed === $d['work_performed'] && $old->observations === $d['observations']
                    && DB::table('workshop_labor_entries')->where('work_record_id', $old->id)->where('work_type', $d['work_type'])->exists(), 409);

                return $old->id;
            }
            abort_unless($w->version === $version && $w->status === 'repair', 409);
            $a->project($actor, $w);
            abort_if($end->isFuture() || $start->second !== 0 || $end->second !== 0 || ! $end->gt($start), 422);
            $intake = DB::table('workshop_intakes')->find($w->intake_id);
            abort_unless($start->gte(CarbonImmutable::parse($intake->received_at)), 422);
            abort_if(DB::table('workshop_labor_entries')->where('technician_id', $actor)->where('started_at', '<', $end)->where('ended_at', '>', $start)->exists(), 409, 'وقت العمل مسجل بالفعل.');
            app(FieldMaintenanceApi::class)->assertLaborAvailable($actor, $start->toIso8601String(), $end->toIso8601String());
            $minutes = (int) $start->diffInMinutes($end);
            abort_unless($minutes > 0 && $minutes <= 1440, 422);
            $rate = DB::table('workshop_rates')->where('branch_id', $w->branch_id)->where('technician_id', $actor)->whereNotNull('approved_by')->where('effective_from', '<=', $start->setTimezone('Africa/Cairo')->toDateString())->where('effective_to', '>=', $end->setTimezone('Africa/Cairo')->toDateString())->first();
            $cost = $rate ? Decimal::round(bcdiv(bcmul($rate->hourly_cost, (string) $minutes, 16), '60', 16)) : null;
            $id = DB::table('workshop_work_records')->insertGetId(['job_id' => $job, 'creation_key' => $key, 'technician_id' => $actor, 'started_at' => $start, 'ended_at' => $end, 'work_performed' => $d['work_performed'], 'observations' => $d['observations'], 'created_by' => $actor]);
            DB::table('workshop_labor_entries')->insert(['job_id' => $job, 'work_record_id' => $id, 'technician_id' => $actor, 'started_at' => $start, 'ended_at' => $end, 'duration_minutes' => $minutes, 'rate_id' => $rate?->id, 'cost' => $cost, 'work_type' => $d['work_type'], 'created_by' => $actor]);
            $a->touch($actor, $w, 'work.recorded', ['work_record_id' => $id, 'minutes' => $minutes]);

            return $id;
        }, 5);
    }

    public function test(int $actor, int $job, int $version, array $input): int
    {
        $d = Validator::make($input, ['name' => 'required|string|max:200', 'outcome' => 'required|in:passed,failed,waived', 'results' => 'required|string|min:5|max:10000'])->validate();

        return DB::transaction(function () use ($actor, $job, $version, $d) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'execute', $version);
            abort_unless($w->status === 'testing', 409);
            app(WorkshopAccess::class)->technician($actor, $w);
            $plan = collect(json_decode($w->required_tests, true))->firstWhere('name', $d['name']);
            abort_unless($plan, 422, 'الفحص غير موجود في الخطة المعتمدة.');
            if ($d['outcome'] === 'waived') {
                abort_if($plan['required'], 422, 'الاختبار الإلزامي يحتاج نتيجة نجاح فعلية، ولا يقبل الإعفاء.');
                app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'verify');
            }
            $id = DB::table('workshop_tests')->insertGetId($d + ['job_id' => $job, 'required' => $plan['required'], 'performed_by' => $actor]);
            $a->touch($actor, $w, 'test.recorded', ['test_id' => $id, 'outcome' => $d['outcome']]);

            return $id;
        }, 5);
    }

    public function verify(int $actor, int $job, int $version, string $finalCondition, string $remainingFaults): void
    {
        Validator::make(compact('finalCondition', 'remainingFaults'), ['finalCondition' => 'required|string|min:5|max:5000', 'remainingFaults' => 'required|string|min:3|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $finalCondition, $remainingFaults) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'verify', $version);
            $a->project($actor, $w);
            abort_unless($w->status === 'testing', 409);
            abort_if($actor === $w->technician_id, 403, 'التحقق الفني يتطلب مشرفاً مستقلاً.');
            $plans = json_decode($w->required_tests, true);
            abort_unless($plans && collect($plans)->contains('required', true), 409);
            foreach ($plans as $plan) {
                if (! $plan['required']) {
                    continue;
                }$result = DB::table('workshop_tests')->where('job_id', $job)->where('name', $plan['name'])->orderByDesc('id')->first();
                abort_unless($result && $result->outcome === 'passed', 409, 'يوجد اختبار إلزامي غير محسوم.');
            }
            app(WorkshopParts::class)->reconciled($actor, $job);
            abort_unless(DB::table('workshop_work_records')->where('job_id', $job)->exists(), 409);
            if ($w->contract_id !== null) {
                app(ServiceContractsApi::class)->consumeWork($actor, 'WorkshopRepairs', $job);
            }
            $doc = app(OfficialDocuments::class)->issue($actor, 'workshop_report', (string) $job, 1, now('Africa/Cairo')->toDateString(), ['title' => 'تقرير إصلاح واختبار معدة', 'fields' => ['أمر الإصلاح' => $w->number, 'العميل' => $w->customer_name, 'التشخيص' => $w->diagnosis, 'الحالة النهائية' => $finalCondition, 'الأعطال المتبقية' => $remainingFaults], 'columns' => ['الفحص', 'النتيجة', 'الملاحظات'], 'rows' => DB::table('workshop_tests')->where('job_id', $job)->orderBy('id')->get()->map(fn ($r) => [$r->name, $r->outcome, $r->results])->all(), 'totals' => []]);
            DB::table('workshop_verifications')->insert(['job_id' => $job, 'verified_by' => $actor, 'final_condition' => $finalCondition, 'remaining_faults' => $remainingFaults, 'document_id' => $doc['id']]);
            $a->touch($actor, $w, 'job.verified', ['document_id' => $doc['id']], ['status' => 'verified']);
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($w->intake_id);
            DB::table('workshop_intakes')->where('id', $i->id)->update(['status' => 'ready', 'version' => $i->version + 1, 'updated_at' => now()]);
            $a->custody($actor, $i, $i->location, 'ready', 'تحقق فني مستقل', (string) Str::uuid(), $finalCondition);
        }, 5);
    }

    public function unrepairable(int $actor, int $job, int $version, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $reason) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'verify', $version);
            abort_unless(in_array($w->status, ['inspection', 'waiting_approval', 'rejected']), 409);
            app(WorkshopParts::class)->reconciled($actor, $job);
            $a->touch($actor, $w, 'job.unrepairable', ['reason' => $reason], ['status' => 'rejected']);
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($w->intake_id);
            DB::table('workshop_intakes')->where('id', $i->id)->update(['status' => 'not_repairable', 'version' => $i->version + 1, 'updated_at' => now()]);
            $a->custody($actor, $i, $i->location, 'not_repairable', $reason, (string) Str::uuid(), $i->arrival_condition);
        }, 5);
    }

    public function deliver(int $actor, int $job, int $version, string $key, array $input): int
    {
        $d = Validator::make($input, ['recipient' => 'required|string|min:3|max:200', 'accessories' => 'required|string|min:3|max:5000', 'final_condition' => 'required|string|min:5|max:5000', 'acknowledgment' => 'required|string|min:5|max:5000', 'delivered_at' => 'required|date', 'unrepaired' => 'required|boolean'])->validate();
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $job, $version, $key, $d) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'deliver');
            $old = DB::table('workshop_deliveries')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->job_id === $job && $old->delivered_by === $actor && $old->recipient === $d['recipient']
                    && $old->accessories === $d['accessories'] && $old->final_condition === $d['final_condition']
                    && $old->acknowledgment === $d['acknowledgment'] && $old->unrepaired === $d['unrepaired']
                    && CarbonImmutable::parse($old->delivered_at)->equalTo(CarbonImmutable::parse($d['delivered_at'])), 409);

                return $old->id;
            }
            abort_unless($w->version === $version && ($d['unrepaired'] ? $w->status === 'rejected' : $w->status === 'verified'), 409);
            abort_if($w->pending_transfer_id !== null, 409);
            if (! $d['unrepaired']) {
                app(RepairOffers::class)->authorized($w);
            }
            app(WorkshopParts::class)->reconciled($actor, $job);
            if (! $d['unrepaired'] && in_array($w->billing_mode, ['billable', 'partial'])) {
                abort_unless(app(WorkshopFinance::class)->invoice($actor, $job), 409, 'يلزم إظهار إحالة الفوترة قبل التسليم.');
            }
            $date = CarbonImmutable::parse($d['delivered_at'])->utc();
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($w->intake_id);
            abort_if($date->isFuture() || $date->lt(CarbonImmutable::parse($i->received_at)), 422);
            if (! $i->customer_owned && ! $d['unrepaired']) {
                abort_unless($i->isolation_id, 409);
                app(InventoryApi::class)->releaseIsolation($actor, $i->isolation_id, '1', $key, $i->company_serial_id ? [$i->serial_number] : []);
            }
            $d['delivered_at'] = $date;
            $id = DB::table('workshop_deliveries')->insertGetId($d + ['creation_key' => $key, 'intake_id' => $i->id, 'job_id' => $job, 'delivered_by' => $actor]);
            $doc = app(OfficialDocuments::class)->issue($actor, 'workshop_delivery', (string) $id, 1, $date->setTimezone('Africa/Cairo')->toDateString(), [
                'title' => $i->customer_owned ? 'محضر تسليم معدة' : 'محضر إعادة معدة الشركة', 'fields' => ['أمر الإصلاح' => $w->number, 'المعدة' => $i->equipment_description, 'هوية المعدة' => $i->equipment_identity, 'الرقم المسلسل' => $i->serial_number ?? 'غير مقروء', 'المستلم' => $d['recipient'], 'الملحقات' => $d['accessories'], 'الحالة النهائية' => $d['final_condition'], 'إقرار الاستلام' => $d['acknowledgment'], 'دون إصلاح' => $d['unrepaired'] ? 'نعم' : 'لا'], 'columns' => [], 'rows' => [], 'totals' => []]);
            DB::table('workshop_delivery_documents')->insert(['delivery_id' => $id, 'document_id' => $doc['id']]);
            $status = $d['unrepaired'] ? 'returned_unrepaired' : 'delivered';
            $a->touch($actor, $w, 'equipment.'.$status, ['delivery_id' => $id], ['status' => $status]);
            if ($status === 'returned_unrepaired' && $w->contract_id !== null) {
                app(ServiceContractsApi::class)->releaseWork($actor, 'WorkshopRepairs', $job);
            }
            $destination = ($i->customer_owned ? 'العميل: ' : ($d['unrepaired'] ? 'معدات الشركة غير المتاحة: ' : 'إعادة للخدمة: ')).$d['recipient'];
            DB::table('workshop_intakes')->where('id', $i->id)->update(['status' => $status, 'location' => $destination, 'version' => $i->version + 1, 'updated_at' => now()]);
            $a->custody($actor, $i, $destination, $status, $d['acknowledgment'], $key, $d['final_condition']);

            return $id;
        }, 5);
    }
}

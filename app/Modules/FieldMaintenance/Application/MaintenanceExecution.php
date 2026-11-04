<?php

namespace App\Modules\FieldMaintenance\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MaintenanceExecution
{
    public function reschedule(int $actor, int $work, int $visit, int $version, int $assignment, string $scheduledAt, string $reason): void
    {
        Validator::make(compact('scheduledAt', 'reason'), ['scheduledAt' => 'required|date', 'reason' => 'required|string|min:5|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();
        DB::transaction(function () use ($actor, $work, $visit, $version, $assignment, $scheduledAt, $reason) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'assign');
            $a->executable($w);
            $a->project($actor, $w);
            $v = DB::table('maintenance_visits')->where('work_order_id', $work)->lockForUpdate()->find($visit);
            abort_unless($v && $v->status === 'planned' && $v->version === $version, 409);
            $as = DB::table('maintenance_assignments')->where('work_order_id', $work)->where('active', true)->find($assignment);
            abort_unless($as, 422);
            $time = CarbonImmutable::parse($scheduledAt)->utc();
            abort_unless($time->gte(CarbonImmutable::parse($as->starts_at)) && $time->lt(CarbonImmutable::parse($as->ends_at)), 422);
            DB::table('maintenance_visits')->where('id', $visit)->update(['assignment_id' => $assignment, 'technician_id' => $as->technician_id, 'scheduled_at' => $time, 'version' => $version + 1]);
            $a->touch($actor, $w, 'visit.rescheduled', ['visit_id' => $visit, 'old_assignment' => $v->assignment_id, 'assignment_id' => $assignment, 'reason' => $reason]);
        }, 5);
    }

    public function endAssignment(int $actor, int $work, int $version, int $assignment, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();
        DB::transaction(function () use ($actor, $work, $version, $assignment, $reason) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'assign', $version);
            $a->executable($w);
            $as = DB::table('maintenance_assignments')->where('work_order_id', $work)->where('active', true)->find($assignment);
            abort_unless($as, 404);
            abort_if(DB::table('maintenance_visits')->where('assignment_id', $assignment)->where('status', 'planned')->exists(), 409, 'أعد جدولة الزيارات أو سجل إلغاءها أولًا.');
            DB::table('maintenance_assignments')->where('id', $assignment)->update(['active' => false, 'ended_at' => now()]);
            $a->touch($actor, $w, 'assignment.ended', ['assignment_id' => $assignment, 'reason' => $reason]);
        }, 5);
    }

    public function assign(int $actor, int $work, int $version, array $input): int
    {
        $d = Validator::make($input, ['technician_id' => 'required|integer|min:1', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'override_reason' => 'nullable|string|min:5|max:2000', 'replace_id' => 'nullable|integer|min:1'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $work, $version, $d) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'assign', $version);
            $a->executable($w);
            $a->project($actor, $w);
            abort_unless(in_array((int) $d['technician_id'], array_column(app(MaintenanceAccess::class)->technicians($actor, $w->branch_id), 'id'), true), 422, 'الفني غير مؤهل داخل الفرع.');
            $start = CarbonImmutable::parse($d['starts_at'])->utc();
            $end = CarbonImmutable::parse($d['ends_at'])->utc();
            $conflicts = DB::table('maintenance_assignments')->where('technician_id', $d['technician_id'])->where('active', true)->when(! empty($d['replace_id']), fn ($q) => $q->where('id', '<>', $d['replace_id']))->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if ($conflicts) {
                app(MaintenanceAccess::class)->authorize($actor, $w->branch_id, 'schedule_override');
                abort_unless(! empty($d['override_reason']), 422, 'تعارض موعد الفني. يلزم سبب تجاوز معتمد.');
            }
            if (! empty($d['replace_id'])) {
                abort_unless(mb_strlen(trim($d['override_reason'] ?? '')) >= 5, 422, 'إعادة التكليف تحتاج سببًا مسجلًا.');
                $old = DB::table('maintenance_assignments')->where('work_order_id', $work)->where('active', true)->find($d['replace_id']);
                abort_unless($old, 409);
                abort_if(DB::table('maintenance_visits')->where('assignment_id', $old->id)->where('status', 'planned')->exists(), 409, 'سجل أو ألغ الزيارات المخططة قبل إعادة التكليف.');
                DB::table('maintenance_assignments')->where('id', $old->id)->update(['active' => false, 'ended_at' => now()]);
            }
            $id = DB::table('maintenance_assignments')->insertGetId(['work_order_id' => $work, 'technician_id' => $d['technician_id'], 'starts_at' => $start, 'ends_at' => $end, 'override_reason' => $d['override_reason'] ?? null, 'assigned_by' => $actor]);
            $a->touch($actor, $w, 'assignment.created', ['assignment_id' => $id, 'conflict' => $conflicts, 'reason' => $d['override_reason'] ?? null], ['status' => $w->status === 'in_progress' ? 'in_progress' : 'scheduled']);

            return $id;
        }, 5);
    }

    public function saveTemplate(int $actor, ?int $id, int $version, array $input): int
    {
        $d = Validator::make($input, ['branch_id' => 'required|integer|min:1', 'code' => 'required|string|max:60', 'title' => 'required|string|max:200', 'items' => 'required|array|min:1|max:100', 'items.*.key' => 'required|string|max:80|distinct|regex:/^[a-z][a-z0-9_]*$/', 'items.*.label' => 'required|string|max:255', 'items.*.required' => 'required|boolean', 'active' => 'required|boolean'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $a = app(MaintenanceActions::class);
            $a->lock();
            app(MaintenanceAccess::class)->authorize($actor, (int) $d['branch_id'], 'checklist_manage');
            $old = $id ? DB::table('maintenance_checklist_templates')->lockForUpdate()->find($id) : null;
            abort_unless(! $id || ($old && $old->version === $version && $old->branch_id === $d['branch_id']), 409);
            $save = $d;
            $save['items'] = json_encode($d['items'], JSON_THROW_ON_ERROR);
            $save['version'] = $old ? $version + 1 : 1;
            if ($old) {
                DB::table('maintenance_checklist_templates')->where('id', $id)->update($save + ['updated_at' => now()]);
            } else {
                $id = DB::table('maintenance_checklist_templates')->insertGetId($save + ['created_by' => $actor]);
            }
            $a->audit($actor, 'maintenance_checklist_template', $id, 'checklist.saved', $old ? (array) $old : null, $d);

            return $id;
        }, 5);
    }

    public function schedule(int $actor, int $work, int $version, string $key, array $input): int
    {
        Validator::make(compact('key'), ['key' => 'required|uuid'], [], __('maintenance.fields', [], 'ar'))->validate();
        $d = Validator::make($input, ['assignment_id' => 'required|integer|min:1', 'scheduled_at' => 'required|date', 'template_id' => 'required|integer|min:1', 'mandatory' => 'required|boolean'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $work, $version, $key, $d) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'assign');
            $old = DB::table('maintenance_visits')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->work_order_id === $work && $old->assignment_id === (int) $d['assignment_id'] && CarbonImmutable::parse($old->scheduled_at)->equalTo(CarbonImmutable::parse($d['scheduled_at'])) && (bool) $old->mandatory === (bool) $d['mandatory'] && ($old->template_id === null || $old->template_id === (int) $d['template_id']), 409);

                return $old->id;
            }
            abort_unless($w->version === $version, 409);
            $a->executable($w);
            $a->project($actor, $w);
            $as = DB::table('maintenance_assignments')->where('work_order_id', $work)->where('active', true)->find($d['assignment_id']);
            abort_unless($as, 422);
            $time = CarbonImmutable::parse($d['scheduled_at'])->utc();
            abort_unless($time->gte(CarbonImmutable::parse($as->starts_at)) && $time->lt(CarbonImmutable::parse($as->ends_at)), 422, 'وقت الزيارة خارج موعد التكليف.');
            $template = DB::table('maintenance_checklist_templates')->where('branch_id', $w->branch_id)->where('active', true)->find($d['template_id']);
            abort_unless($template, 422);
            $id = DB::table('maintenance_visits')->insertGetId(['creation_key' => $key, 'work_order_id' => $work, 'assignment_id' => $as->id, 'technician_id' => $as->technician_id, 'scheduled_at' => $time, 'mandatory' => $d['mandatory'], 'template_id' => $template->id, 'template_snapshot' => $template->items]);
            $a->touch($actor, $w, 'visit.scheduled', ['visit_id' => $id], ['status' => $w->status === 'in_progress' ? 'in_progress' : 'assigned']);

            return $id;
        }, 5);
    }

    public function recordVisit(int $actor, int $work, int $visit, int $version, array $input, array $results): void
    {
        $d = Validator::make($input, ['arrival_at' => 'nullable|date', 'departure_at' => 'nullable|date|after:arrival_at', 'work_performed' => 'required_unless:outcome,cancelled|nullable|string|min:5|max:10000', 'diagnosis' => 'nullable|string|max:10000', 'observations' => 'nullable|string|max:10000', 'follow_up' => 'nullable|string|max:10000', 'acknowledgment' => 'nullable|string|max:2000', 'outcome' => 'required|in:completed,follow_up_required,waiting_for_parts,unable_to_access,cancelled', 'cancellation_reason' => 'nullable|string|min:5|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();
        DB::transaction(function () use ($actor, $work, $visit, $version, $d, $results) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'visit_record');
            $a->executable($w);
            $a->project($actor, $w);
            $v = DB::table('maintenance_visits')->where('work_order_id', $work)->lockForUpdate()->find($visit);
            abort_unless($v, 404);
            abort_unless($v->technician_id === $actor, 403, 'يسجل الزيارة الفني المكلف نفسه.');
            abort_unless($v->status === 'planned' && $v->version === $version, 409);
            abort_unless(DB::table('maintenance_assignments')->where('id', $v->assignment_id)->where('active', true)->exists(), 409);
            $data = $d;
            if ($d['outcome'] === 'cancelled') {
                abort_unless(! empty($d['cancellation_reason']), 422);
                abort_unless(empty($d['arrival_at']) && empty($d['departure_at']), 422, 'إلغاء الموعد لا يسجل ساعات عمل فعلية.');
            } else {
                abort_unless(! empty($d['arrival_at']) && ! empty($d['departure_at']), 422, 'أوقات الوصول والمغادرة الفعلية مطلوبة.');
                $start = CarbonImmutable::parse($d['arrival_at'])->utc();
                $end = CarbonImmutable::parse($d['departure_at'])->utc();
                abort_if($end->isFuture(), 422, 'لا تسجل عملًا مستقبليًا.');
                abort_if(DB::table('maintenance_visits')->where('technician_id', $actor)->where('status', 'recorded')->where('arrival_at', '<', $end)->where('departure_at', '>', $start)->exists(), 409, 'وقت الزيارة يتعارض مع زيارة فعلية.');
                $data['arrival_at'] = $start;
                $data['departure_at'] = $end;
            }
            if (in_array($d['outcome'], ['follow_up_required', 'waiting_for_parts'])) {
                abort_unless(! empty($d['follow_up']), 422, 'المتابعة مطلوبة.');
            }
            $template = json_decode($v->template_snapshot, true, 512, JSON_THROW_ON_ERROR);
            foreach ($template as $item) {
                $r = $results[$item['key']] ?? null;
                if (! $r) {
                    abort_unless(! $item['required'] || $d['outcome'] === 'cancelled', 422, 'قائمة الفحص غير مكتملة.');

                    continue;
                }
                $r = Validator::make($r, ['result' => 'required|in:complete,not_applicable,failed', 'notes' => 'nullable|string|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();
                if ($r['result'] !== 'complete') {
                    abort_unless(mb_strlen(trim($r['notes'] ?? '')) >= 5, 422, 'يلزم تفسير نتيجة الفحص.');
                }
                DB::table('maintenance_checklist_results')->insert(['visit_id' => $visit, 'item_key' => $item['key'], 'label' => $item['label'], 'required' => $item['required'], 'result' => $r['result'], 'notes' => $r['notes'] ?? null, 'created_by' => $actor]);
            }
            DB::table('maintenance_visits')->where('id', $visit)->update($data + ['status' => 'recorded', 'recorded_by' => $actor, 'recorded_at' => now()->format('Y-m-d H:i:s.uP'), 'version' => $version + 1]);
            $a->touch($actor, $w, 'visit.recorded', ['visit_id' => $visit, 'outcome' => $d['outcome']], ['status' => $d['outcome'] === 'cancelled' ? $w->status : 'in_progress']);
        }, 5);
    }

    public function labor(int $actor, int $work, string $key, array $input): int
    {
        Validator::make(compact('key'), ['key' => 'required|uuid'], [], __('maintenance.fields', [], 'ar'))->validate();
        $d = Validator::make($input, ['visit_id' => 'required|integer|min:1', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'work_type' => 'required|string|max:100', 'notes' => 'nullable|string|max:2000'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $work, $key, $d) {
            $a = app(MaintenanceActions::class);
            $w = $a->work($actor, $work, 'labor_record');
            $a->executable($w);
            $a->project($actor, $w);
            $old = DB::table('maintenance_labor_entries')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->work_order_id === $work && $old->created_by === $actor && $old->visit_id === (int) $d['visit_id'] && CarbonImmutable::parse($old->starts_at)->equalTo(CarbonImmutable::parse($d['starts_at'])) && CarbonImmutable::parse($old->ends_at)->equalTo(CarbonImmutable::parse($d['ends_at'])) && $old->work_type === $d['work_type'] && ($old->notes ?? '') === ($d['notes'] ?? ''), 409);

                return $old->id;
            }
            $v = DB::table('maintenance_visits')->where('work_order_id', $work)->find($d['visit_id']);
            abort_unless($v && $v->status === 'recorded' && $v->outcome !== 'cancelled', 422);
            abort_unless($v->technician_id === $actor, 403);
            $start = CarbonImmutable::parse($d['starts_at'])->utc();
            $end = CarbonImmutable::parse($d['ends_at'])->utc();
            abort_unless($start->gte(CarbonImmutable::parse($v->arrival_at)) && $end->lte(CarbonImmutable::parse($v->departure_at)), 422, 'ساعات العمل يجب أن تقع داخل الزيارة الفعلية.');
            $minutes = (int) $start->diffInMinutes($end);
            abort_unless($minutes > 0 && $start->second === 0 && $end->second === 0, 422, 'سجل الوقت بدقة الدقيقة.');
            abort_if(DB::table('maintenance_labor_entries')->where('technician_id', $actor)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 409, 'ساعات عمل متداخلة.');
            app(WorkshopApi::class)->assertLaborAvailable($actor, $start->toIso8601String(), $end->toIso8601String());
            $date = $start->setTimezone('Africa/Cairo')->toDateString();
            $rate = DB::table('maintenance_labor_rates')->where('branch_id', $w->branch_id)->where('technician_id', $actor)->where('effective_from', '<=', $date)->where('effective_to', '>=', $end->setTimezone('Africa/Cairo')->toDateString())->first();
            $cost = $rate ? Decimal::round(bcdiv(bcmul($rate->hourly_cost, (string) $minutes, 16), '60', 16)) : null;
            $id = DB::table('maintenance_labor_entries')->insertGetId(['creation_key' => $key, 'work_order_id' => $work, 'visit_id' => $v->id, 'technician_id' => $actor, 'starts_at' => $start, 'ends_at' => $end, 'duration_minutes' => $minutes, 'work_type' => $d['work_type'], 'rate_id' => $rate?->id, 'cost' => $cost, 'notes' => $d['notes'] ?? null, 'created_by' => $actor]);
            $a->touch($actor, $w, 'labor.recorded', ['labor_id' => $id, 'duration_minutes' => $minutes, 'rate_id' => $rate?->id]);

            return $id;
        }, 5);
    }

    public function approveRate(int $actor, int $proposer, array $input): int
    {
        $d = Validator::make($input, ['branch_id' => 'required|integer|min:1', 'technician_id' => 'required|integer|min:1', 'hourly_cost' => ['required', 'string', 'regex:/^[0-9]{1,16}(\.[0-9]{1,2})?$/'], 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'required|date_format:Y-m-d|after_or_equal:effective_from', 'reference' => 'required|string|min:5|max:255'], [], __('maintenance.fields', [], 'ar'))->validate();

        return DB::transaction(function () use ($actor, $proposer, $d) {
            $a = app(MaintenanceActions::class);
            $a->lock();
            app(MaintenanceAccess::class)->authorize($actor, (int) $d['branch_id'], 'rate_approve');
            app(MaintenanceAccess::class)->authorize($proposer, (int) $d['branch_id'], 'labor_record');
            abort_if($actor === $proposer, 403);
            $proposal = app(MaintenanceCostProposals::class)->matching($actor, $proposer, 'labor_rate', (int) $d['branch_id'], null, $d);
            abort_unless(bccomp($d['hourly_cost'], '0', 2) > 0 && in_array((int) $d['technician_id'], array_column(app(MaintenanceAccess::class)->technicians($actor, (int) $d['branch_id']), 'id'), true), 422);
            abort_if(DB::table('maintenance_labor_rates')->where('branch_id', $d['branch_id'])->where('technician_id', $d['technician_id'])->where('effective_from', '<=', $d['effective_to'])->where('effective_to', '>=', $d['effective_from'])->exists(), 409);
            $id = DB::table('maintenance_labor_rates')->insertGetId($d + ['created_by' => $proposer, 'approved_by' => $actor, 'proposal_id' => $proposal]);
            $a->audit($actor, 'maintenance_labor_rate', $id, 'rate.approved', null, ['reference' => $d['reference'], 'proposer' => $proposer]);

            return $id;
        }, 5);
    }

    public function completionReadiness(int $actor, int $work): array
    {
        $w = app(MaintenanceAccess::class)->query($actor)->find($work);
        abort_unless($w, 404);
        $checks = $this->completionChecks($w);
        $parts = ['label' => 'تسوية القطع المصروفة والحجوزات', 'tab' => 'parts', 'ready' => true, 'message' => 'تمت تسوية الاستخدام والمرتجع والحجوزات.'];
        try {
            app(MaintenanceParts::class)->reconciled($actor, $work);
        } catch (HttpException|ContractException $e) {
            $parts['ready'] = in_array($e->getCode(), [403, 404]) || ($e instanceof HttpException && in_array($e->getStatusCode(), [403, 404])) ? null : false;
            $parts['message'] = $parts['ready'] === null ? 'مراجعة تسوية المخزون تحتاج مسؤولًا مخولًا.' : ($e->getMessage() ?: 'يلزم مراجعة تسوية قطع الغيار.');
        }
        $checks['parts'] = $parts;

        return $checks;
    }

    public function completionGuards(int $actor, object $w): void
    {
        foreach ($this->completionChecks($w) as $check) {
            abort_unless($check['ready'], 409, $check['message']);
        }
        app(MaintenanceParts::class)->reconciled($actor, $w->id);
    }

    private function completionChecks(object $w): array
    {
        $visits = DB::table('maintenance_visits')->where('work_order_id', $w->id)->get();
        $mandatory = $replacement = $labor = $checklist = true;
        foreach ($visits as $v) {
            if ($v->mandatory && $v->status !== 'recorded') {
                $mandatory = false;
            }
            if ($v->mandatory && $v->outcome === 'cancelled' && ! $visits->contains(fn ($later) => $later->status === 'recorded' && $later->outcome === 'completed' && $later->recorded_at > $v->recorded_at && json_decode($later->template_snapshot, true) === json_decode($v->template_snapshot, true))) {
                $replacement = false;
            }
            if ($v->status === 'recorded' && $v->outcome !== 'cancelled' && ! DB::table('maintenance_labor_entries')->where('visit_id', $v->id)->exists()) {
                $labor = false;
            }
            foreach (json_decode($v->template_snapshot, true) as $item) {
                if (! $item['required'] || $v->outcome === 'cancelled') {
                    continue;
                }
                $r = DB::table('maintenance_checklist_results')->where('visit_id', $v->id)->where('item_key', $item['key'])->first();
                // Failed checks need a later completed visit with the same check identity.
                $resolved = $r && $r->result !== 'failed';
                if (! $resolved) {
                    $resolved = DB::table('maintenance_checklist_results as r')->join('maintenance_visits as v', 'v.id', '=', 'r.visit_id')->where('v.work_order_id', $w->id)->where('v.outcome', 'completed')->where('v.recorded_at', '>', $v->recorded_at)->where('r.item_key', $item['key'])->where('r.label', $item['label'])->whereIn('r.result', ['complete', 'not_applicable'])->exists();
                }
                if (! $resolved) {
                    $checklist = false;
                }
            }
        }
        $latest = $visits->where('status', 'recorded')->sortByDesc('departure_at')->first();
        $checks = [
            'completed_visit' => ['label' => 'تقرير زيارة مكتملة', 'ready' => $visits->where('status', 'recorded')->where('outcome', 'completed')->isNotEmpty(), 'message' => 'يلزم تقرير زيارة مكتملة.', 'tab' => 'visits'],
            'mandatory_visits' => ['label' => 'تنفيذ الزيارات الإلزامية', 'ready' => $mandatory, 'message' => 'توجد زيارة إلزامية غير منفذة.', 'tab' => 'visits'],
            'replacement_visits' => ['label' => 'بديل الزيارات الإلزامية الملغاة', 'ready' => $replacement, 'message' => 'زيارة إلزامية ملغاة تحتاج زيارة بديلة مكتملة بنفس إجراء الفحص.', 'tab' => 'visits'],
            'labor' => ['label' => 'وقت فعلي لكل زيارة منفذة', 'ready' => $labor, 'message' => 'يلزم سجل وقت فعلي لكل زيارة منفذة.', 'tab' => 'labor'],
            'checks' => ['label' => 'حسم الفحوص الإلزامية', 'ready' => $checklist, 'message' => 'يوجد فحص إلزامي غير محسوم.', 'tab' => 'visits'],
            'follow_up' => ['label' => 'حسم آخر متابعة بزيارة مكتملة', 'ready' => $latest && $latest->outcome === 'completed', 'message' => 'يلزم حسم المتابعة بزيارة مكتملة.', 'tab' => 'visits'],
        ];
        if ($w->acknowledgment_required) {
            $checks['acknowledgment'] = ['label' => 'إقرار العميل', 'ready' => $visits->where('outcome', 'completed')->contains(fn ($v) => mb_strlen(trim($v->acknowledgment ?? '')) >= 3), 'message' => 'إقرار العميل مطلوب.', 'tab' => 'visits'];
        }

        return $checks;
    }

    public function verify(int $actor, object $w, string $evidence): void
    {
        $revision = (int) DB::table('maintenance_completions')->where('work_order_id', $w->id)->max('revision') + 1;
        $doc = app(OfficialDocuments::class)->issue($actor, 'maintenance_report', (string) $w->id, $revision, now('Africa/Cairo')->toDateString(), ['title' => 'تقرير إتمام الصيانة', 'fields' => ['أمر العمل' => $w->number, 'العميل' => $w->customer_name, 'التحقق' => $evidence], 'columns' => ['الزيارة', 'التشخيص', 'العمل', 'النتيجة'], 'rows' => DB::table('maintenance_visits')->where('work_order_id', $w->id)->where('status', 'recorded')->orderBy('id')->get()->map(fn ($v) => [(string) $v->id, $v->diagnosis ?? '', $v->work_performed, $v->outcome])->all(), 'totals' => []]);
        DB::table('maintenance_completions')->insert(['work_order_id' => $w->id, 'revision' => $revision, 'evidence' => $evidence, 'verified_by' => $actor, 'official_document_id' => $doc['id']]);
    }
}

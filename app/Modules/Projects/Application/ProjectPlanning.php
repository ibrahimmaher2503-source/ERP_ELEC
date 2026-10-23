<?php

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProjectPlanning
{
    private function locked(int $actor, int $id, int $version, string $permission): Project
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(70701)');
        $p = Project::lockForUpdate()->findOrFail($id);
        app(ProjectAccess::class)->authorize($actor, $p, $permission);
        if ($p->version != $version) {
            $this->fail('أعد فتح المشروع للحصول على أحدث نسخة.');
        }

        return $p;
    }

    private function proposal(Project $p, array $input): array
    {
        $input['structure'] ??= DB::table('project_phases')->where('project_id', $p->id)->orderBy('id')->get(['id', 'title', 'start_date', 'end_date', 'cancelled'])->map(fn ($row) => (array) $row)->all();
        $d = Validator::make($input, ['structure' => 'array|max:100', 'structure.*.id' => 'nullable|integer|distinct', 'structure.*.title' => 'required|string|max:200', 'structure.*.start_date' => 'required|date_format:Y-m-d', 'structure.*.end_date' => 'required|date_format:Y-m-d', 'structure.*.cancelled' => 'required|boolean', 'currency' => 'required|regex:/^[A-Z]{3}$/', 'total' => ['required', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/'], 'scope' => 'required|string|max:5000', 'categories' => 'required|array|min:1|max:30', 'categories.*.name' => 'required|string|max:100|distinct', 'categories.*.amount' => ['required', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/'], 'phases' => 'array|max:100', 'phases.*.id' => 'required|integer|distinct', 'phases.*.amount' => ['required', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/']])->validate();
        $existing = DB::table('project_phases')->where('project_id', $p->id)->pluck('id')->all();
        $submitted = [];
        foreach ($d['structure'] as $phase) {
            if ($phase['start_date'] < $p->start_date || $phase['end_date'] > $p->end_date || $phase['end_date'] < $phase['start_date']) {
                $this->fail('تواريخ المرحلة خارج مدة المشروع.');
            }
            if (! empty($phase['id'])) {
                if (! in_array((int) $phase['id'], $existing, true)) {
                    $this->fail('المرحلة لا تتبع المشروع.');
                }$submitted[] = (int) $phase['id'];
                if (DB::table('project_tasks')->where('phase_id', $phase['id'])->where(fn ($q) => $q->where('start_date', '<', $phase['start_date'])->orWhere('end_date', '>', $phase['end_date']))->exists()) {
                    $this->fail('تواريخ المرحلة يجب أن تشمل مهامها المحفوظة.');
                }
            }
        }
        if (array_diff($existing, $submitted)) {
            $this->fail('لا تحذف المراحل؛ علّم المرحلة الملغاة لحفظ تاريخها.');
        }
        $sum = '0';
        foreach ($d['categories'] as $row) {
            $sum = bcadd($sum, $row['amount'], 8);
        }if (bccomp($sum, $d['total'], 8) !== 0) {
            $this->fail('مجموع فئات الميزانية يجب أن يساوي الإجمالي.');
        }
        if ($d['phases'] ?? []) {
            $sum = '0';
            foreach ($d['phases'] as $row) {
                $candidate = collect($d['structure'])->first(fn ($phase) => (int) ($phase['id'] ?? 0) === (int) $row['id']);
                if (! $candidate || $candidate['cancelled']) {
                    $this->fail('لا توزع ميزانية على مرحلة مقترح إلغاؤها.');
                }
                if (! DB::table('project_phases')->where('project_id', $p->id)->where('id', $row['id'])->where('cancelled', false)->exists()) {
                    $this->fail('مرحلة غير صالحة.');
                }$sum = bcadd($sum, $row['amount'], 8);
            }if (bccomp($sum, $d['total'], 8) !== 0) {
                $this->fail('توزيع المراحل يجب أن يساوي الميزانية، ولا يضاف إلى توزيع الفئات.');
            }
        }

        return $d;
    }

    public function propose(int $actor, int $id, int $version, string $key, string $reason, array $input): void
    {
        Validator::make(['key' => $key, 'reason' => $reason], ['key' => 'required|uuid', 'reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $key, $reason, $input) {
            $p = $this->locked($actor, $id, $version, 'budget_manage');
            if (! in_array($p->status, ['draft', 'planned', 'active'])) {
                $this->fail('حالة المشروع لا تسمح بطلب تغيير.');
            }if (DB::table('project_changes')->where('creation_key', $key)->exists()) {
                return;
            }if (DB::table('project_changes')->where('project_id', $id)->where('status', 'pending')->exists()) {
                $this->fail('يوجد طلب تغيير قيد المراجعة.');
            }$d = $this->proposal($p, $input);
            $revision = (int) DB::table('project_baselines')->where('project_id', $id)->max('revision');
            $baseline = DB::table('project_baselines')->where('project_id', $id)->orderByDesc('revision')->first();
            if ($baseline && $baseline->currency !== $d['currency']) {
                $this->fail('عملة خط الأساس ثابتة لتجنب خلط القيم.');
            }$change = DB::table('project_changes')->insertGetId(['project_id' => $id, 'creation_key' => $key, 'base_revision' => $revision, 'reason' => $reason, 'proposal' => json_encode($d, JSON_THROW_ON_ERROR), 'created_by' => $actor, 'created_at' => now()]);
            $p->increment('version');
            app(ProjectActions::class)->activity($actor, $p, 'change.proposed', null, ['change_id' => $change, 'base_revision' => $revision]);
        });
    }

    public function decide(int $actor, int $id, int $version, int $change, bool $approve, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $change, $approve, $reason) {
            $p = $this->locked($actor, $id, $version, 'project_view');
            abort_unless($p->manager_id == $actor, 403, 'مدير المشروع المعين هو جهة الاعتماد.');
            if (in_array($p->status, ['closed', 'cancelled', 'on_hold'])) {
                $this->fail('حالة المشروع لا تسمح بالاعتماد.');
            }$c = DB::table('project_changes')->where('project_id', $id)->where('id', $change)->lockForUpdate()->firstOrFail();
            if ($c->status !== 'pending') {
                $this->fail('سبق اتخاذ القرار.');
            }$current = (int) DB::table('project_baselines')->where('project_id', $id)->max('revision');
            if ($current != $c->base_revision) {
                $this->fail('خط الأساس تغير.');
            }$d = json_decode($c->proposal, true, 512, JSON_THROW_ON_ERROR);
            if ($approve) {
                $this->proposal($p, $d);
                DB::table('project_baselines')->insert(['project_id' => $id, 'revision' => $current + 1, 'currency' => $d['currency'], 'total' => $d['total'], 'snapshot' => json_encode($d, JSON_THROW_ON_ERROR), 'approved_by' => $actor, 'approved_at' => now()]);
                $p->description = $d['scope'];
                foreach ($d['structure'] ?? [] as $phase) {
                    $phaseId = $phase['id'] ?? null;
                    unset($phase['id']);
                    if ($phaseId) {
                        DB::table('project_phases')->where('project_id', $id)->where('id', $phaseId)->update($phase);
                    } else {
                        DB::table('project_phases')->insert($phase + ['project_id' => $id]);
                    }
                }

            }$p->version++;
            $p->save();
            DB::table('project_changes')->where('id', $change)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor, 'decided_at' => now(), 'decision_reason' => $reason]);
            app(ProjectActions::class)->activity($actor, $p, $approve ? 'change.approved' : 'change.rejected', ['revision' => $current], ['change_id' => $change, 'revision' => $approve ? $current + 1 : $current]);
        });
    }

    public function transition(int $actor, int $id, int $version, string $state, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $state, $reason) {
            $p = $this->locked($actor, $id, $version, 'project_view');
            abort_unless($p->manager_id == $actor, 403);
            $allowed = ['draft' => ['planned'], 'planned' => ['active'], 'active' => ['on_hold', 'completed'], 'on_hold' => ['active'], 'completed' => ['active']];
            if (! in_array($state, $allowed[$p->status] ?? [], true)) {
                $this->fail('الانتقال غير مسموح. الإغلاق والإلغاء يتطلبان تسوية الالتزامات من العقود المالكة.');
            }if ($state === 'planned' && ! DB::table('project_baselines')->where('project_id', $id)->exists()) {
                $this->fail('اعتمد خط الأساس قبل التخطيط.');
            }if ($state === 'completed') {
                $progress = app(ProjectAccess::class)->progress($p);
                foreach ($progress['phases']->where('cancelled', false) as $phase) {
                    if (! $phase->total || $phase->total !== $phase->complete) {
                        $this->fail('يجب إكمال جميع المهام والمراحل.');
                    }
                }if (! $progress['eligible']) {
                    $this->fail('لا توجد مراحل مكتملة.');
                }
            }if (DB::table('project_changes')->where('project_id', $id)->where('status', 'pending')->exists()) {
                $this->fail('يوجد طلب تغيير معلق.');
            }$before = $p->status;
            $p->update(['status' => $state, 'version' => $version + 1]);
            app(ProjectActions::class)->activity($actor, $p, 'lifecycle.changed', ['status' => $before], ['status' => $state, 'reason' => $reason]);
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['form' => $message]);
    }
}

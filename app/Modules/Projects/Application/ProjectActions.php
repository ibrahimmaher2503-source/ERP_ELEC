<?php

namespace App\Modules\Projects\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Projects\Domain\Project;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProjectActions
{
    public function metadata(int $actor, int $project, int $version, array $input, string $reason): void
    {
        $reason = trim($reason);
        $data = Validator::make($input, ['name' => 'required|string|max:200', 'project_type' => 'nullable|string|max:100', 'priority' => 'required|in:low,normal,high,urgent', 'notes' => 'nullable|string|max:2000'])->validate();
        $data['project_type'] = $data['project_type'] ?? '';
        $data['notes'] = $data['notes'] ?? '';
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000'])->validate();
        DB::transaction(function () use ($actor, $project, $version, $data, $reason) {
            $this->lock();
            $p = Project::lockForUpdate()->findOrFail($project);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_update');
            abort_unless($p->version === $version && ! in_array($p->status, ['closed', 'cancelled'], true), 409);
            $before = $p->toArray();
            $p->update($data + ['version' => $version + 1]);
            $this->activity($actor, $p, 'project.metadata', $before, $p->fresh()->toArray() + ['reason' => $reason]);
        });
    }

    public function activity(int $actor, Project $p, string $action, ?array $before, array $after): void
    {
        DB::table('project_activities')->insert(['project_id' => $p->id, 'actor_id' => $actor, 'action' => $action, 'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
        app(RecordAudit::class)->execute($actor, 'project', $p->id, $action, $before, $after, 'succeeded', 'Projects');
    }

    private function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(70701)');
    }

    public function save(int $actor, ?int $id, int $version, string $key, array $input): Project
    {
        $extra = Validator::make($input, ['project_type' => 'sometimes|nullable|string|max:100', 'priority' => 'sometimes|in:low,normal,high,urgent'])->validate();
        $d = Validator::make($input, ['code' => 'required|string|max:60|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:200', 'branch_id' => 'required|integer|min:1', 'customer_id' => 'required|integer|min:1', 'manager_id' => 'required|integer|min:1', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'description' => 'nullable|string|max:5000', 'notes' => 'nullable|string|max:2000', 'members' => 'array|max:100', 'members.*' => 'integer|distinct'])->validate();
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();

        $d = array_merge($d, $extra);
        foreach (['description', 'notes', 'project_type'] as $field) {
            $d[$field] ??= '';
        }

        return DB::transaction(function () use ($actor, $id, $version, $key, $d) {
            $this->lock();
            $access = app(ProjectAccess::class);
            $p = $id ? Project::lockForUpdate()->findOrFail($id) : Project::where('creation_key', $key)->lockForUpdate()->first();
            if ($p) {
                $access->authorize($actor, $p, 'project_update');
                if (! $id) {
                    abort_unless($p->created_by == $actor, 403);
                    throw ValidationException::withMessages(['form' => 'المشروع محفوظ بالفعل. افتحه من القائمة.']);
                } if ($p->version != $version) {
                    $this->fail('تم تعديل المشروع، أعد فتح الصفحة.');
                }if ($p->status !== 'draft' || DB::table('project_baselines')->where('project_id', $p->id)->exists()) {
                    $this->fail('البيانات الأساسية تحت تحكم خط الأساس بعد التخطيط.');
                } if ($p->branch_id != $d['branch_id']) {
                    $this->fail('الفرع ثابت.');
                }
            } else {
                abort_unless(in_array((int) $d['branch_id'], array_column($access->branches($actor, 'project_create'), 'id'), true), 403);
                abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, (int) $d['branch_id']), 403);
            }
            $users = array_column(app(ProjectAccess::class)->assignees($actor, (int) $d['branch_id']), 'id');
            foreach (array_merge([$d['manager_id']], $d['members'] ?? []) as $u) {
                if (! in_array((int) $u, $users, true)) {
                    $this->fail('اختر مديرًا وأعضاء نشطين وموثقين داخل نطاق الفرع.');
                }
            }
            if ($p && $p->customer_id != $d['customer_id'] && DB::table('project_source_links')->where('project_id', $p->id)->exists()) {
                $this->fail('العميل ثابت بعد ربط المستندات المصدرية.');
            }
            try {
                $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $d['customer_id']));
            } catch (CustomerContractException $e) {
                if ($e->getCode() === 403) {
                    abort(403, 'تحتاج صلاحية عرض العميل.');
                }$this->fail('اختر عميلًا موجودًا ونشطًا.');
            }
            if (Project::where('code', $d['code'])->when($p, fn ($q) => $q->where('id', '<>', $p->id))->exists()) {
                $this->fail('كود المشروع مستخدم.');
            }
            $before = $p?->toArray();
            if ($p) {
                $before['members'] = DB::table('project_members')->where('project_id', $p->id)->pluck('user_id')->all();
            }
            $members = $d['members'] ?? [];
            unset($d['members']);
            $d['customer_name'] = $customer->name;
            if ($p && ($handoff = DB::table('project_tender_handoffs')->where('destination', 'project')->where('target_id', $p->id)->first())) {
                $winning = json_decode($handoff->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
                abort_unless((int) $d['customer_id'] === $winning['customer_id'] && (int) $d['branch_id'] === $winning['branch_id'], 409, 'هوية العميل والفرع ثابتة في مشروع الترسية.');
            }
            $d['version'] = $p ? $p->version + 1 : 1;
            if ($p) {
                $p->update($d);
            } else {
                $p = Project::create($d + ['creation_key' => $key, 'created_by' => $actor]);
            }
            DB::table('project_members')->where('project_id', $p->id)->delete();
            foreach ($members as $u) {
                DB::table('project_members')->insert(['project_id' => $p->id, 'user_id' => $u]);
            }
            $this->activity($actor, $p, 'project.saved', $before, $p->fresh()->toArray() + ['members' => $members]);

            return $p->fresh();
        });
    }

    public function team(int $actor, int $project, int $version, array $members): void
    {
        Validator::make(['members' => $members], ['members' => 'array|max:100', 'members.*' => 'integer|distinct'])->validate();
        DB::transaction(function () use ($actor, $project, $version, $members) {
            $this->lock();
            $p = Project::lockForUpdate()->findOrFail($project);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_update');
            if ($p->version != $version) {
                $this->fail('المشروع تغير؛ أعد فتح الصفحة.');
            }if (in_array($p->status, ['closed', 'cancelled'])) {
                $this->fail('المشروع مقفل.');
            }$allowed = array_column(app(ProjectAccess::class)->assignees($actor, $p->branch_id), 'id');
            foreach ($members as $u) {
                if (! in_array((int) $u, $allowed, true)) {
                    $this->fail('عضو غير نشط أو خارج نطاق الفرع.');
                }
            }$keep = array_merge([$p->manager_id], array_map('intval', $members));
            if (DB::table('project_tasks')->where('project_id', $project)->whereIn('status', ['pending', 'in_progress', 'blocked'])->whereNotIn('assigned_to', $keep)->exists() || DB::table('project_milestones')->where('project_id', $project)->where('status', 'pending')->whereNotIn('responsible_id', $keep)->exists()) {
                $this->fail('أعد توزيع مهام العضو المفتوحة قبل إزالته من الفريق.');
            }$before = DB::table('project_members')->where('project_id', $project)->pluck('user_id')->all();
            DB::table('project_members')->where('project_id', $project)->delete();
            foreach ($members as $u) {
                DB::table('project_members')->insert(['project_id' => $project, 'user_id' => $u]);
            }$p->increment('version');
            $this->activity($actor, $p, 'team.saved', ['members' => $before], ['members' => $members]);
        });
    }

    public function phase(int $actor, int $project, int $version, array $input): void
    {
        $d = Validator::make($input, ['title' => 'required|string|max:200', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'])->validate();
        DB::transaction(function () use ($actor, $project, $version, $d) {
            $this->lock();
            $p = Project::lockForUpdate()->findOrFail($project);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_manage');
            if ($p->version != $version) {
                $this->fail('أعد تحميل المشروع.');
            }if ($p->status !== 'draft' || DB::table('project_baselines')->where('project_id', $p->id)->exists()) {
                $this->fail('تغيير هيكل المراحل بعد التخطيط يحتاج طلب تغيير.');
            }if ($d['start_date'] < $p->start_date || $d['end_date'] > $p->end_date) {
                $this->fail('تواريخ المرحلة خارج مدة المشروع.');
            }$id = DB::table('project_phases')->insertGetId($d + ['project_id' => $p->id]);
            $p->increment('version');
            $this->activity($actor, $p, 'phase.created', null, $d + ['id' => $id]);
        });
    }

    public function task(int $actor, int $project, int $version, array $input, ?int $taskId = null): void
    {
        $extra = Validator::make($input, ['priority' => 'sometimes|in:low,normal,high,urgent', 'required' => 'sometimes|boolean'])->validate();
        $d = Validator::make($input, ['phase_id' => 'required|integer', 'title' => 'required|string|max:200', 'assigned_to' => 'required|integer', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'notes' => 'nullable|string|max:2000'])->validate();
        $d = array_merge($d, $extra);
        $d['notes'] ??= '';
        DB::transaction(function () use ($actor, $project, $version, $d, $taskId) {
            $this->lock();
            $p = Project::lockForUpdate()->findOrFail($project);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_manage');
            if ($p->version != $version) {
                $this->fail('أعد تحميل المشروع.');
            }if (! in_array($p->status, ['draft', 'planned', 'active', 'in_progress'])) {
                $this->fail('المشروع لا يسمح بتعديل المهام.');
            }if (! in_array((int) $d['assigned_to'], array_column(app(ProjectAccess::class)->assignees($actor, $p->branch_id), 'id'), true)) {
                $this->fail('المكلف غير نشط أو خارج نطاق الفرع.');
            }
            $phase = DB::table('project_phases')->where('project_id', $p->id)->where('id', $d['phase_id'])->where('cancelled', false)->first();
            if (! $phase || $d['start_date'] < $phase->start_date || $d['end_date'] > $phase->end_date) {
                $this->fail('اختر مرحلة وتواريخ صحيحة.');
            }if ($d['assigned_to'] != $p->manager_id && ! DB::table('project_members')->where('project_id', $p->id)->where('user_id', $d['assigned_to'])->exists()) {
                $this->fail('المكلف يجب أن يكون ضمن الفريق.');
            }$before = null;
            if ($taskId) {
                $before = (array) DB::table('project_tasks')->where('project_id', $project)->where('id', $taskId)->lockForUpdate()->firstOrFail();
                DB::table('project_tasks')->where('id', $taskId)->update($d + ['version' => $before['version'] + 1]);
                $id = $taskId;
            } else {
                $id = DB::table('project_tasks')->insertGetId($d + ['project_id' => $p->id]);
            }
            $p->increment('version');
            $this->activity($actor, $p, $taskId ? 'task.updated' : 'task.created', $before, $d + ['id' => $id]);
        });
    }

    public function taskStatus(int $actor, int $project, int $task, int $version, string $status, string $reason = '', ?int $progress = null): void
    {
        $reason = trim($reason);
        if (! in_array($status, ['pending', 'in_progress', 'blocked', 'completed', 'cancelled'])) {
            $this->fail('حالة غير صحيحة.');
        }
        Validator::make(compact('reason', 'progress'), ['reason' => 'nullable|string|max:2000', 'progress' => 'nullable|integer|min:0|max:99'])->validate();
        if (in_array($status, ['blocked', 'cancelled'], true) && mb_strlen(trim($reason)) < 5) {
            $this->fail('أدخل سبب التعطيل أو الإعفاء من المهمة.');
        }
        DB::transaction(function () use ($actor, $project, $task, $version, $status, $reason, $progress) {
            $this->lock();
            $p = Project::lockForUpdate()->findOrFail($project);
            $a = app(ProjectAccess::class);
            $a->authorize($actor, $p);
            $t = DB::table('project_tasks')->where('project_id', $p->id)->where('id', $task)->lockForUpdate()->firstOrFail();
            abort_unless($a->allows($actor, $p, 'project_manage') || ($t->assigned_to == $actor && $a->member($actor, $p)), 403);
            if ($status === 'cancelled') {
                $a->authorize($actor, $p, 'project_close');
            }
            if (in_array($status, ['in_progress', 'completed'], true) && DB::table('project_task_dependencies as d')->join('project_tasks as t', 't.id', '=', 'd.depends_on_id')->where('d.task_id', $task)->whereNotIn('t.status', ['completed', 'cancelled'])->exists()) {
                $this->fail('أكمل المهام السابقة أولاً.');
            }
            if (in_array($status, ['pending', 'in_progress', 'blocked'], true) && $t->status === 'completed' && DB::table('project_task_dependencies as d')->join('project_tasks as t', 't.id', '=', 'd.task_id')->where('d.depends_on_id', $task)->where('t.status', 'completed')->exists()) {
                $this->fail('لا تعاد مهمة لها مهام تابعة مكتملة.');
            }
            if ($t->version != $version) {
                $this->fail('المهمة تغيرت. أعد التحميل.');
            }if (! in_array($p->status, ['draft', 'planned', 'active', 'in_progress'])) {
                $this->fail('المشروع لا يسمح بتحديث المهام.');
            }if (DB::table('project_phases')->where('id', $t->phase_id)->value('cancelled')) {
                $this->fail('المرحلة ملغاة.');
            }if ($status === $t->status && $progress === null) {
                return;
            }$after = ['status' => $status, 'version' => $version + 1, 'progress' => $status === 'completed' ? 100 : ($status === 'pending' ? 0 : ($progress ?? $t->progress)), 'blocked_reason' => in_array($status, ['blocked', 'cancelled'], true) ? $reason : '', 'completed_by' => $status === 'completed' ? $actor : null, 'completed_at' => $status === 'completed' ? now() : null];
            DB::table('project_tasks')->where('id', $task)->update($after);
            if ($status === 'in_progress' && $p->actual_start === null) {
                $p->update(['actual_start' => now('Africa/Cairo')->toDateString()]);
            }
            $p->increment('version');
            $this->activity($actor, $p, 'task.status', ['id' => $task, 'status' => $t->status], ['id' => $task, 'status' => $status, 'reason' => $reason, 'progress' => $after['progress']]);
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['form' => $message]);
    }
}

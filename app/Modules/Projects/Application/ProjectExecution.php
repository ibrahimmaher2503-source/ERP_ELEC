<?php

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProjectExecution
{
    public function dependency(int $actor, int $project, int $version, int $task, int $dependsOn): void
    {
        DB::transaction(function () use ($actor, $project, $version, $task, $dependsOn) {
            $p = $this->locked($actor, $project, $version, 'project_manage');
            abort_unless($task !== $dependsOn && DB::table('project_tasks')->where('project_id', $project)->whereIn('id', [$task, $dependsOn])->count() === 2, 422);
            abort_unless(DB::table('project_tasks')->where('id', $task)->value('status') === 'pending', 409);
            $cycle = DB::selectOne('WITH RECURSIVE ancestors(id) AS (SELECT ?::bigint UNION SELECT d.depends_on_id FROM project_task_dependencies d JOIN ancestors a ON d.task_id=a.id) SELECT exists(SELECT 1 FROM ancestors WHERE id=?) AS cycle', [$dependsOn, $task]);
            abort_if($cycle->cycle, 422, 'تبعية دائرية غير مسموحة.');
            if (DB::table('project_task_dependencies')->insertOrIgnore(['task_id' => $task, 'depends_on_id' => $dependsOn])) {
                $p->increment('version');
                app(ProjectActions::class)->activity($actor, $p, 'task.dependency', null, ['task_id' => $task, 'depends_on_id' => $dependsOn]);
            }
        });
    }

    private function locked(int $actor, int $project, int $version, string $permission): Project
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(70701)');
        $p = Project::lockForUpdate()->findOrFail($project);
        app(ProjectAccess::class)->authorize($actor, $p, $permission);
        abort_unless($p->version === $version && ! in_array($p->status, ['closed', 'cancelled', 'completed'], true), 409, 'المشروع مقفل أو تغير إصداره.');

        return $p;
    }

    public function milestone(int $actor, int $project, int $version, array $input): int
    {
        $data = Validator::make($input, ['title' => 'required|string|max:200', 'target_date' => 'required|date_format:Y-m-d', 'responsible_id' => 'required|integer', 'required' => 'boolean'])->validate();

        return DB::transaction(function () use ($actor, $project, $version, $data) {
            $p = $this->locked($actor, $project, $version, 'project_manage');
            abort_unless($data['target_date'] >= $p->start_date && $data['target_date'] <= $p->end_date, 422);
            abort_unless(in_array((int) $data['responsible_id'], array_column(app(ProjectAccess::class)->assignees($actor, $p->branch_id), 'id'), true), 422);
            abort_unless((int) $data['responsible_id'] === (int) $p->manager_id || DB::table('project_members')->where('project_id', $project)->where('user_id', $data['responsible_id'])->exists(), 422);
            $id = DB::table('project_milestones')->insertGetId($data + ['project_id' => $project]);
            $p->increment('version');
            app(ProjectActions::class)->activity($actor, $p, 'milestone.created', null, $data + ['id' => $id]);

            return $id;
        });
    }

    public function milestoneStatus(int $actor, int $project, int $milestone, int $version, string $status, string $evidence): void
    {
        $evidence = trim($evidence);
        Validator::make(compact('status', 'evidence'), ['status' => 'required|in:completed,waived', 'evidence' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $project, $milestone, $version, $status, $evidence) {
            $p = Project::findOrFail($project);
            $p = $this->locked($actor, $project, $p->version, $status === 'waived' ? 'project_close' : 'project_manage');
            $m = DB::table('project_milestones')->where('project_id', $project)->lockForUpdate()->find($milestone);
            abort_unless($m && (int) $m->version === $version && $m->status === 'pending', 409);
            DB::table('project_milestones')->where('id', $milestone)->update(['status' => $status, 'evidence' => $evidence, 'completed_at' => now(), 'completed_by' => $actor, 'version' => $version + 1]);
            $p->increment('version');
            app(ProjectActions::class)->activity($actor, $p, 'milestone.'.$status, (array) $m, ['id' => $milestone, 'evidence' => $evidence]);
        });
    }

    public function comment(int $actor, int $project, int $task, string $body): void
    {
        Validator::make(compact('body'), ['body' => 'required|string|min:1|max:5000'])->validate();
        DB::transaction(function () use ($actor, $project, $task, $body) {
            $p = Project::findOrFail($project);
            $p = $this->locked($actor, $project, $p->version, 'project_view');
            abort_unless(DB::table('project_tasks')->where('project_id', $project)->where('id', $task)->exists(), 404);
            DB::table('project_task_comments')->insert(['project_id' => $project, 'task_id' => $task, 'actor_id' => $actor, 'body' => $body]);
            app(ProjectActions::class)->activity($actor, $p, 'task.comment', null, ['task_id' => $task, 'body' => $body]);
        });
    }

    public function handover(int $actor, int $project, int $version, string $evidence): void
    {
        $evidence = trim($evidence);
        Validator::make(compact('evidence'), ['evidence' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $project, $version, $evidence) {
            $p = $this->locked($actor, $project, $version, 'project_close');
            DB::table('project_handovers')->insert(['project_id' => $project, 'evidence' => $evidence, 'recorded_by' => $actor]);
            $p->increment('version');
            app(ProjectActions::class)->activity($actor, $p, 'handover.recorded', null, ['evidence' => $evidence]);
        });
    }

    public function completionChecks(Project $p): array
    {
        $checks = [];
        $checks['handover'] = DB::table('project_handovers')->where('project_id', $p->id)->exists();
        $checks['milestones'] = ! DB::table('project_milestones')->where('project_id', $p->id)->where('required', true)->where('status', 'pending')->exists();
        $checks['tasks'] = ! DB::table('project_tasks')->where('project_id', $p->id)->where('required', true)->whereNotIn('status', ['completed', 'cancelled'])->exists();
        $checks['changes'] = ! DB::table('project_changes')->where('project_id', $p->id)->where('status', 'pending')->exists();
        $checks['documents'] = true;
        foreach (DB::table('project_documents')->where('project_id', $p->id)->where('required', true)->get() as $doc) {
            $checks['documents'] = $checks['documents'] && DB::table('project_document_revisions')->where('document_id', $doc->id)->orderByDesc('revision')->value('status') === 'approved';
        }

        return $checks;
    }

    public function requireCompletion(Project $p): void
    {
        if (in_array(false, $this->completionChecks($p), true)) {
            throw ValidationException::withMessages(['form' => 'أكمل المهام والمعالم والمستندات المطلوبة وسجل التسليم واحسم التغييرات.']);
        }
    }

    public function requireClosure(int $actor, Project $p): void
    {
        $this->requireCompletion($p);
        $financial = app(ProjectFinancials::class)->summary($actor, $p->id);
        $blocked = array_filter($financial['closure_blockers']);
        if ($blocked !== []) {
            throw ValidationException::withMessages(['form' => 'التزامات تمنع الإغلاق: '.implode('، ', array_keys($blocked))]);
        }
    }
}

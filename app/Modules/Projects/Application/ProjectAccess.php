<?php

namespace App\Modules\Projects\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;

final class ProjectAccess
{
    public function branches(int $actor, string $permission = 'project_view'): array
    {
        try {
            return app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M07.'.$permission));
        } catch (ContractException) {
            return [];
        }
    }

    public function assignees(int $actor, int $branch): array
    {
        try {
            return app(OrganizationApi::class)->projectAssignees($actor, $branch);
        } catch (ContractException $e) {
            abort($e->getCode(), 'نطاق الفرع غير متاح.');
        }
    }

    public function member(int $actor, Project $p): bool
    {
        return $p->manager_id == $actor || DB::table('project_members')->where('project_id', $p->id)->where('user_id', $actor)->exists();
    }

    public function allows(int $actor, Project $p, string $permission = 'project_view'): bool
    {
        try {
            if (! app(OrganizationApi::class)->projectBranchMember($actor, $p->branch_id)) {
                return false;
            }
            if ($p->manager_id == $actor || ($permission === 'project_view' && $this->member($actor, $p))) {
                return true;
            }
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M07.'.$permission, $p->branch_id));

            return true;
        } catch (ContractException) {
            return false;
        }
    }

    public function authorize(int $actor, Project $p, string $permission = 'project_view'): void
    {
        abort_unless($this->allows($actor, $p, $permission), 403);
    }

    public function query(int $actor)
    {
        $branches = array_column($this->branches($actor), 'id');

        return Project::query()->whereIn('branch_id', app(OrganizationApi::class)->projectScopedBranches($actor))->where(function ($q) use ($actor, $branches) {
            $q->whereIn('branch_id', $branches)->orWhere(function ($q) use ($actor) {
                $q->where('manager_id', $actor)->orWhereIn('id', DB::table('project_members')->where('user_id', $actor)->select('project_id'));
            });
        });
    }

    public function progress(Project $p): array
    {
        $phases = DB::table('project_phases as p')->leftJoin('project_tasks as t', fn ($join) => $join->on('t.phase_id', '=', 'p.id')->on('t.project_id', '=', 'p.project_id'))->where('p.project_id', $p->id)->select('p.*')->selectRaw("count(t.id) filter (where t.status <> 'cancelled') as total, count(t.id) filter (where t.status = 'completed') as complete")->groupBy('p.id')->orderBy('p.id')->get();
        $sum = '0';
        $eligible = 0;
        foreach ($phases as $phase) {
            $phase->total = (int) $phase->total;
            $phase->complete = (int) $phase->complete;
            $phase->progress = $phase->total ? bcdiv(bcmul((string) $phase->complete, '100', 8), (string) $phase->total, 8) : '0';
            if (! $phase->cancelled) {
                $sum = bcadd($sum, $phase->progress, 8);
                $eligible++;
            }
        }

        return ['phases' => $phases, 'percent' => $eligible ? bcdiv($sum, (string) $eligible, 2) : '0.00', 'eligible' => $eligible];
    }
}

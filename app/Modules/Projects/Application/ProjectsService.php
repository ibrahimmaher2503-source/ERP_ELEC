<?php

namespace App\Modules\Projects\Application;

use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;

final class ProjectsService implements ProjectsApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        $out = ['projects' => null, 'tenders' => null];
        $ids = array_column(app(ProjectAccess::class)->branches($actor), 'id');
        if ($ids && ($branch === null || in_array($branch, $ids, true))) {
            $q = DB::table('projects')->whereIn('branch_id', $branch === null ? $ids : [$branch])->where('start_date', '<=', $to)->where('end_date', '>=', $from);
            $out['projects'] = ['scope' => 'authorized_branches', 'metrics' => ['total' => (clone $q)->count(), 'active' => (clone $q)->where('status', 'active')->count()], 'basis' => 'مشروعات تتداخل مدتها مع الفترة، حسب الحالة الحالية.'];
        }
        $ids = array_column(app(TenderAccess::class)->branches($actor), 'id');
        if ($ids && ($branch === null || in_array($branch, $ids, true))) {
            $q = DB::table('project_tenders')->whereIn('branch_id', $branch === null ? $ids : [$branch])->whereBetween('published_on', [$from, $to]);
            $out['tenders'] = ['scope' => 'authorized_branches', 'metrics' => ['total' => (clone $q)->count(), 'submitted' => (clone $q)->whereNotNull('submitted_id')->count()], 'basis' => 'مناقصات حسب تاريخ الطرح؛ التقديم لا يعني الفوز أو الإيراد.'];
        }

        return $out;
    }

    public function contractTenderAward(int $actor, int $tender, int $result, array $mapping): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);

        return app(TenderAwards::class)->contractSource($actor, $tender, $result, $mapping);
    }

    public function tenderAward(int $actor, int $tender, int $result, array $mapping): array
    {
        return app(TenderAwards::class)->quotationSource($actor, $tender, $result, $mapping);
    }

    public function maintenancePlanning(int $actor, int $project, int $branch, int $customer): array
    {
        $this->maintenanceProject($actor, $project, $branch, $customer);

        return ['tasks' => DB::table('project_tasks')->where('project_id', $project)->where('status', '<>', 'cancelled')->orderBy('id')->get(['id', 'title'])->map(fn ($t) => (array) $t)->all(), 'milestones' => DB::table('project_milestones')->where('project_id', $project)->where('status', '<>', 'waived')->orderBy('id')->get(['id', 'title'])->map(fn ($m) => (array) $m)->all()];
    }

    public function maintenanceProjects(int $actor, int $branch, int $customer): array
    {
        return app(ProjectAccess::class)->query($actor)->where('branch_id', $branch)->where('customer_id', $customer)->whereIn('status', ['active', 'in_progress', 'completed'])->get(['id', 'code', 'name'])->toArray();
    }

    public function maintenanceProject(int $actor, int $project, int $branch, int $customer): void
    {
        $this->authorizeFinancialSource($actor, $project, $branch);
        abort_unless((int) Project::findOrFail($project)->customer_id === $customer, 422, 'عميل المشروع لا يطابق الصيانة.');
    }

    public function inventoryDemand(int $actor, string $project, string $requirement, string $permission = 'material_manage'): array
    {
        abort_unless(ctype_digit($project) && ctype_digit($requirement), 422);
        $p = Project::findOrFail((int) $project);
        abort_unless(in_array($permission, ['material_manage', 'material_issue'], true), 422);
        app(ProjectAccess::class)->authorize($actor, $p, $permission);
        $r = DB::table('project_requirements')->where('project_id', $p->id)->find((int) $requirement);
        abort_unless($r && in_array($p->status, ['active', 'in_progress'], true) && DB::table('project_baselines')->where('project_id', $p->id)->exists(), 409, 'يلزم مشروع نشط وخط أساس معتمد.');

        return ['eligible' => true, 'branch_id' => (int) $p->branch_id, 'product_id' => (int) $r->product_id, 'quantity' => $r->quantity];
    }

    public function authorizeMaterialIssue(int $actor, string $project, string $requirement, string $key, int $warehouse, string $quantity): array
    {
        abort_unless(DB::transactionLevel() > 0 && ctype_digit($project) && ctype_digit($requirement), 422);
        $p = Project::findOrFail((int) $project);
        app(ProjectAccess::class)->authorize($actor, $p, 'material_issue');
        $i = DB::table('project_material_issues')->where('project_id', $p->id)->where('requirement_id', (int) $requirement)->where('issue_key', $key)->first();
        abort_unless($i && (int) $i->created_by === $actor && in_array($p->status, ['active', 'in_progress'], true) && bccomp($i->quantity, $quantity, 8) === 0, 409, 'يلزم تفويض صرف مشروع مطابق.');

        return ['project_id' => (int) $p->id, 'branch_id' => (int) $p->branch_id, 'created_by' => (int) $p->created_by, 'approved_by' => $actor];
    }

    public function releaseAllowed(int $actor, string $project, string $requirement): bool
    {
        $p = Project::findOrFail((int) $project);
        app(ProjectAccess::class)->authorize($actor, $p, 'material_manage');

        return ! in_array($p->status, ['closed', 'cancelled'], true) && DB::table('project_requirements')->where('project_id', $p->id)->where('id', (int) $requirement)->exists();
    }

    public function sourceProject(int $actor, string $type, int $source, int $branch, ?int $customer = null): ?int
    {
        $link = DB::table('project_source_links')->where('source_type', $type)->where('source_id', $source)->first();
        if (! $link) {
            return null;
        }
        $this->authorizeFinancialSource($actor, (int) $link->project_id, $branch);
        if ($type === 'sales_order') {
            abort_unless($customer !== null && (int) Project::findOrFail($link->project_id)->customer_id === $customer, 409, 'عميل المصدر تغير بعد ربط المشروع.');
        }

        return (int) $link->project_id;
    }

    public function authorizeFinancialSource(int $actor, int $project, int $branch): void
    {
        $p = Project::query()->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->findOrFail($project);
        app(ProjectAccess::class)->authorize($actor, $p);
        abort_unless((int) $p->branch_id === $branch && in_array($p->status, ['active', 'in_progress', 'completed'], true), 409, 'المشروع لا يسمح بمصادر مالية جديدة.');
    }

    public function authorizeFinancialView(int $actor, int $project, int $branch): void
    {
        $p = Project::findOrFail($project);
        app(ProjectAccess::class)->authorize($actor, $p, 'financial_view');
        abort_unless((int) $p->branch_id === $branch, 403);
    }
}

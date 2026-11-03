<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WorkshopService implements WorkshopApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        $ids = array_column(app(WorkshopAccess::class)->branches($actor), 'id');
        abort_unless($ids && ($branch === null || in_array($branch, $ids, true)), 403);
        $q = DB::table('workshop_jobs')->whereIn('branch_id', $branch === null ? $ids : [$branch])->whereRaw("(created_at at time zone 'Africa/Cairo')::date between ?::date and ?::date", [$from, $to]);

        return ['scope' => 'authorized_branches', 'metrics' => ['total' => (clone $q)->count(), 'open' => (clone $q)->whereNotIn('status', ['closed', 'delivered', 'cancelled', 'returned_unrepaired'])->count()], 'basis' => 'إصلاحات أنشئت خلال الفترة بتوقيت القاهرة، حسب الحالة الحالية.'];
    }

    public function contractWork(int $actor, int $job): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, (int) $w->branch_id, 'view');
        $i = DB::table('workshop_intakes')->find($w->intake_id);
        $labor = DB::table('workshop_labor_entries')->where('job_id', $job)->get();
        $parts = [];
        foreach (DB::table('workshop_part_requirements')->where('job_id', $job)->get() as $r) {
            $totals = app(WorkshopParts::class)->totals($r->id);
            if (bccomp($totals['used'], '0', 8) > 0) {
                $parts[$r->product_id] = bcadd($parts[$r->product_id] ?? '0', $totals['used'], 8);
            }
        }

        return ['version' => (int) $w->version, 'contract_id' => $w->contract_id === null ? null : (int) $w->contract_id, 'customer_id' => (int) $w->customer_id, 'branch_id' => (int) $w->branch_id,
            'equipment_ref' => mb_strtoupper(trim($i->serial_number ?? '')), 'site_id' => $i->site_id, 'service_date' => $w->contract_service_date, 'kind' => 'corrective',
            'reservable' => $w->billing_mode === 'contract' && $i->customer_owned && $w->status === 'inspection', 'complete' => in_array($w->status, ['testing', 'verified', 'delivered']), 'cancelled' => $w->status === 'returned_unrepaired',
            'visits' => 1, 'labor_minutes' => (int) $labor->sum('duration_minutes'),
            'parts' => array_map(fn ($product) => ['product_id' => (int) $product, 'quantity' => $parts[$product]], array_keys($parts)),
            'work_dates' => $labor->flatMap(fn ($l) => [CarbonImmutable::parse($l->started_at)->setTimezone('Africa/Cairo')->toDateString(), CarbonImmutable::parse($l->ended_at)->setTimezone('Africa/Cairo')->toDateString()])->unique()->values()->all()];
    }

    public function fieldRepairs(int $actor, int $work, int $branch, int $customer): array
    {
        app(WorkshopAccess::class)->authorize($actor, $branch);

        return DB::table('workshop_intakes')->where('maintenance_work_order_id', $work)->where('branch_id', $branch)->where('customer_id', $customer)->orderBy('id')->get(['id', 'number', 'maintenance_visit_id', 'status'])->map(fn ($row) => (array) $row)->all();
    }

    public function assertLaborAvailable(int $technician, string $start, string $end): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        abort_if(DB::table('workshop_labor_entries')->where('technician_id', $technician)->where('started_at', '<', $end)->where('ended_at', '>', $start)->exists(), 409, 'وقت العمل مستخدم في إصلاح ورشة.');
    }

    public function inventoryDemand(int $actor, string $job, string $line, string $permission = 'material_manage'): array
    {
        abort_unless(ctype_digit($job) && ctype_digit($line), 422);
        $w = app(WorkshopActions::class)->work($actor, (int) $job, match ($permission) {
            'material_issue' => 'parts_issue', 'material_usage' => 'execute', 'material_return' => 'parts_return', default => 'parts_request',
        });
        app(WorkshopActions::class)->executable($w);
        app(WorkshopActions::class)->project($actor, $w);
        $r = DB::table('workshop_part_requirements')->where('job_id', $w->id)->find((int) $line);
        abort_unless($r, 404);
        abort_if(DB::table('workshop_part_alternatives')->where('original_requirement_id', $r->id)->exists(), 409, 'الطلب استبدل ببديل معتمد.');

        return ['eligible' => in_array($w->status, ['approved', 'repair', 'waiting_parts']), 'branch_id' => $w->branch_id, 'product_id' => $r->product_id, 'quantity' => $r->quantity, 'tracking' => $r->tracking];
    }

    public function validateUnusedPartReturn(int $actor, int $work, int $requirement, int $movement, string $quantity, string $key, array $serials): void
    {
        app(WorkshopParts::class)->validateReturn($actor, $work, $requirement, $movement, $quantity, $key, $serials);
    }

    public function authorizeIssue(int $actor, string $job, string $line, string $key, int $warehouse, string $quantity): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $d = $this->inventoryDemand($actor, $job, $line, 'material_issue');
        abort_unless($d['eligible'], 409);
        $w = DB::table('workshop_jobs')->find((int) $job);
        $r = DB::table('workshop_part_requirements')->find((int) $line);
        $i = DB::table('workshop_part_issues')->where('requirement_id', (int) $line)->where('issue_key', $key)->first();
        abort_unless($i && $i->created_by === $actor && $r->warehouse_id === $warehouse && bccomp($i->quantity, $quantity, 8) === 0, 409);

        return ['project_id' => $w->project_id, 'branch_id' => $w->branch_id, 'created_by' => $w->created_by, 'approved_by' => $actor, 'workshop_material' => true];
    }

    public function releaseAllowed(int $actor, string $job, string $line): bool
    {
        $this->inventoryDemand($actor, $job, $line);

        return true;
    }

    public function authorizeReconciliation(int $actor, int $job, int $branch): void
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w && $w->branch_id === $branch, 403);
        $a = app(WorkshopAccess::class);
        abort_unless($a->billingHandoff($actor, $branch), 403);
    }

    public function billingSource(int $actor, int $job): array
    {
        $w = app(WorkshopActions::class)->work($actor, $job, 'billing_request');
        app(RepairOffers::class)->authorized($w);
        app(WorkshopActions::class)->project($actor, $w);
        abort_unless(in_array($w->status, ['verified', 'delivered']) && in_array($w->billing_mode, ['billable', 'partial']), 409);
        $e = DB::table('workshop_estimates')->where('job_id', $job)->orderByDesc('revision')->first();
        $approval = $e ? DB::table('workshop_estimate_approvals')->where('estimate_id', $e->id)->where('customer_accepted', true)->first() : null;
        abort_unless($e && $approval && bccomp($e->billable_amount, '0', 2) > 0 && DB::table('workshop_verifications')->where('job_id', $job)->exists(), 409);

        return ['type' => 'workshop_repair', 'job_id' => $job, 'branch_id' => $w->branch_id, 'customer_id' => $w->customer_id, 'project_id' => $w->project_id, 'description' => $w->number.' — '.$w->diagnosis, 'approval_id' => $approval->id, 'product_id' => $e->product_id, 'net_amount' => $e->billable_amount, 'tax_amount' => $e->tax_amount, 'tax' => json_decode($e->tax_snapshot, true, 512, JSON_THROW_ON_ERROR)];
    }

    public function validateBillingEvidence(int $actor, int $job, int $branch, int $customer, int $approval): void
    {
        $allowed = false;
        foreach (['M12.customer_invoice_manage', 'M12.customer_invoice_post'] as $key) {
            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $key, $branch));
                $allowed = true;
                break;
            } catch (ContractException) {
            }
        }
        abort_unless($allowed, 403);
        app(WorkshopActions::class)->lock();
        $w = DB::table('workshop_jobs')->lockForUpdate()->find($job);
        abort_unless($w && $w->branch_id === $branch && $w->customer_id === $customer && in_array($w->status, ['verified', 'delivered']) && in_array($w->billing_mode, ['billable', 'partial']), 409);
        app(RepairOffers::class)->authorized($w);
        $e = DB::table('workshop_estimates')->where('job_id', $job)->orderByDesc('revision')->first();
        abort_unless($e && DB::table('workshop_estimate_approvals')->where('id', $approval)->where('estimate_id', $e->id)->where('customer_accepted', true)->exists() && DB::table('workshop_verifications')->where('job_id', $job)->exists(), 409);
    }

    public function projectJobs(int $actor, int $project, int $branch): array
    {
        app(ProjectsApi::class)->authorizeFinancialView($actor, $project, $branch);
        $rows = DB::table('workshop_jobs')->where('project_id', $project)->where('branch_id', $branch)->get(['id', 'status']);

        return ['ids' => $rows->pluck('id')->all(), 'open' => $rows->whereNotIn('status', ['delivered', 'returned_unrepaired'])->count()];
    }
}

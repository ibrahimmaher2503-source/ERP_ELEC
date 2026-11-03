<?php

namespace App\Modules\FieldMaintenance\Application;

use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MaintenanceService implements FieldMaintenanceApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        $ids = array_column(app(MaintenanceAccess::class)->branches($actor), 'id');
        abort_unless($ids && ($branch === null || in_array($branch, $ids, true)), 403);
        $q = DB::table('maintenance_work_orders')->whereIn('branch_id', $branch === null ? $ids : [$branch])->whereRaw("(created_at at time zone 'Africa/Cairo')::date between ?::date and ?::date", [$from, $to]);

        return ['scope' => 'authorized_branches', 'metrics' => ['total' => (clone $q)->count(), 'open' => (clone $q)->whereNotIn('status', ['closed', 'cancelled'])->count()], 'basis' => 'أوامر أنشئت خلال الفترة بتوقيت القاهرة، حسب الحالة الحالية.'];
    }

    public function createContractPreventiveDraft(int $actor, int $contract, string $key, array $data): int
    {
        return DB::transaction(function () use ($actor, $contract, $key, $data) {
            $actions = app(MaintenanceActions::class);
            $id = $actions->createWorkOrder($actor, null, 0, $key, $data + ['priority' => 'normal', 'billable' => false, 'acknowledgment_required' => true]);
            $actions->linkContract($actor, $id, 1, $contract, $data['equipment_ref'], $data['service_date'], 'preventive');

            return $id;
        }, 3);
    }

    public function contractWork(int $actor, int $work): array
    {
        $w = DB::table('maintenance_work_orders')->find($work);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize($actor, (int) $w->branch_id);
        abort_unless(app(MaintenanceAccess::class)->query($actor)->where('id', $work)->exists(), 403);
        $visits = DB::table('maintenance_visits')->where('work_order_id', $work)->where('status', 'recorded')->where('outcome', '<>', 'cancelled')->get();
        $labor = DB::table('maintenance_labor_entries')->where('work_order_id', $work)->get();
        $parts = DB::table('maintenance_part_usage as u')->join('maintenance_part_requirements as r', 'r.id', '=', 'u.requirement_id')->where('r.work_order_id', $work)->groupBy('r.product_id')->selectRaw('r.product_id, sum(u.quantity)::text as quantity')->get()->map(fn ($p) => ['product_id' => (int) $p->product_id, 'quantity' => $p->quantity])->all();

        return ['version' => (int) $w->version, 'contract_id' => $w->contract_id === null ? null : (int) $w->contract_id, 'customer_id' => (int) $w->customer_id, 'branch_id' => (int) $w->branch_id,
            'equipment_ref' => $w->contract_equipment_ref ?? '', 'site_id' => $w->site_id, 'service_date' => $w->contract_service_date, 'kind' => $w->contract_service_kind,
            'reservable' => $w->status === 'draft' && ! $w->billable, 'complete' => in_array($w->status, ['work_completed', 'verified', 'closed']), 'cancelled' => $w->status === 'cancelled',
            'visits' => $visits->count(), 'labor_minutes' => (int) $labor->sum('duration_minutes'), 'parts' => $parts,
            'work_dates' => $labor->flatMap(fn ($l) => [CarbonImmutable::parse($l->starts_at)->setTimezone('Africa/Cairo')->toDateString(), CarbonImmutable::parse($l->ends_at)->setTimezone('Africa/Cairo')->toDateString()])->unique()->values()->all()];
    }

    public function assertLaborAvailable(int $technician, string $start, string $end): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        abort_if(DB::table('maintenance_labor_entries')->where('technician_id', $technician)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists(), 409, 'وقت العمل مستخدم في زيارة ميدانية.');
    }

    public function workshopTransfer(int $actor, int $work, int $visit, int $branch, int $customer): array
    {
        $w = DB::table('maintenance_work_orders')->find($work);
        abort_unless($w && $w->branch_id === $branch && $w->customer_id === $customer, 422);
        app(MaintenanceAccess::class)->authorize($actor, $branch, 'view');
        abort_unless(app(MaintenanceAccess::class)->query($actor)->where('id', $work)->exists(), 403);
        $v = DB::table('maintenance_visits')->where('work_order_id', $work)->find($visit);
        abort_unless($v && $v->status === 'recorded' && in_array($v->outcome, ['follow_up_required', 'waiting_for_parts']), 409, 'يلزم تقرير زيارة فعلية يحيل المعدة للمتابعة.');

        return ['work_order_id' => $work, 'visit_id' => $visit, 'project_id' => $w->project_id, 'site_id' => $w->site_id];
    }

    public function validateBillingEvidence(int $actor, int $work, int $branch, int $customer, int $approval): void
    {
        $allowed = false;
        foreach (['M12.customer_invoice_manage', 'M12.customer_invoice_post'] as $permission) {
            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $permission, $branch));
                $allowed = true;
                break;
            } catch (ContractException) {
            }
        }
        abort_unless($allowed, 403);
        $a = app(MaintenanceActions::class);
        $a->lock();
        $w = DB::table('maintenance_work_orders')->lockForUpdate()->find($work);
        abort_unless($w && $w->branch_id === $branch && $w->customer_id === $customer && $w->billable && in_array($w->status, ['verified', 'closed']), 409, 'مصدر الخدمة يحتاج تحققًا فنيًا ساريًا.');
        abort_unless(DB::table('maintenance_commercial_approvals')->where('id', $approval)->where('work_order_id', $work)->exists() && DB::table('maintenance_completions')->where('work_order_id', $work)->exists(), 409, 'دليل الخدمة أو اعتماد السعر غير متاح.');
    }

    public function authorizeReconciliation(int $actor, int $work, int $branch): void
    {
        $w = DB::table('maintenance_work_orders')->find($work);
        abort_unless($w && $w->branch_id === $branch, 403);
        $access = app(MaintenanceAccess::class);
        abort_unless($access->allows($actor, $branch, 'workorder_manage') || $access->allows($actor, $branch, 'verify') || $access->allows($actor, $branch, 'close'), 403);
    }

    public function projectWorkOrders(int $actor, int $project, int $branch): array
    {
        app(ProjectsApi::class)->authorizeFinancialView($actor, $project, $branch);
        $rows = DB::table('maintenance_work_orders')->where('project_id', $project)->where('branch_id', $branch)->get(['id', 'status']);

        return ['ids' => $rows->pluck('id')->all(), 'open' => $rows->whereNotIn('status', ['closed', 'cancelled'])->count()];
    }

    public function inventoryDemand(int $actor, string $work, string $line, string $permission = 'material_manage'): array
    {
        abort_unless(ctype_digit($work) && ctype_digit($line), 422);
        $w = DB::table('maintenance_work_orders')->find((int) $work);
        abort_unless($w, 404);
        app(MaintenanceAccess::class)->authorize($actor, $w->branch_id, match ($permission) {
            'material_issue' => 'parts_issue', 'material_usage' => 'visit_record', 'material_return' => 'parts_return', default => 'parts_request',
        });
        app(MaintenanceActions::class)->executable($w);
        app(MaintenanceActions::class)->project($actor, $w);
        $r = DB::table('maintenance_part_requirements')->where('work_order_id', $w->id)->find((int) $line);
        abort_unless($r, 404);

        return ['eligible' => in_array($w->status, ['assigned', 'in_progress']), 'branch_id' => (int) $w->branch_id, 'product_id' => (int) $r->product_id, 'quantity' => $r->quantity, 'tracking' => $r->tracking];
    }

    public function validateUnusedPartReturn(int $actor, int $work, int $requirement, int $movement, string $quantity, string $key, array $serials): void
    {
        app(MaintenanceParts::class)->validateReturn($actor, $work, $requirement, $movement, $quantity, $key, $serials);
    }

    public function authorizeIssue(int $actor, string $work, string $line, string $key, int $warehouse, string $quantity): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $d = $this->inventoryDemand($actor, $work, $line, 'material_issue');
        abort_unless($d['eligible'], 409);
        $w = DB::table('maintenance_work_orders')->find((int) $work);
        $i = DB::table('maintenance_part_issues')->where('requirement_id', (int) $line)->where('issue_key', $key)->first();
        $r = DB::table('maintenance_part_requirements')->find((int) $line);
        abort_unless($i && $i->created_by === $actor && $r->warehouse_id === $warehouse && bccomp($i->quantity, $quantity, 8) === 0, 409, 'يلزم تفويض صرف صيانة مطابق.');

        return ['project_id' => $w->project_id, 'branch_id' => (int) $w->branch_id, 'created_by' => (int) $w->created_by, 'approved_by' => $actor, 'maintenance_material' => true];
    }

    public function releaseAllowed(int $actor, string $work, string $line): bool
    {
        $d = $this->inventoryDemand($actor, $work, $line);

        return $d['eligible'];
    }

    public function billingSource(int $actor, int $work): array
    {
        $w = app(MaintenanceActions::class)->work($actor, $work, 'billing_request');
        app(MaintenanceActions::class)->project($actor, $w);
        abort_unless($w->billable && in_array($w->status, ['verified', 'closed']), 409, 'فوترة الصيانة تحتاج خدمة مدفوعة ومتحققًا منها.');
        $approval = DB::table('maintenance_commercial_approvals')->where('work_order_id', $work)->first();
        abort_unless($approval, 409, 'يلزم سعر ومعاملة ضريبية معتمدان.');
        abort_unless(DB::table('maintenance_completions')->where('work_order_id', $work)->exists(), 409);

        return ['type' => 'maintenance_service', 'work_order_id' => $work, 'branch_id' => $w->branch_id, 'customer_id' => $w->customer_id, 'customer_name' => $w->customer_name, 'project_id' => $w->project_id, 'description' => $w->description, 'approval_id' => $approval->id, 'product_id' => $approval->product_id, 'net_amount' => $approval->net_amount, 'tax_amount' => $approval->tax_amount, 'tax' => json_decode($approval->tax_snapshot, true, 512, JSON_THROW_ON_ERROR)];
    }
}

<?php

namespace App\Modules\WorkshopRepairs\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkshopReports
{
    public function execution(int $actor, int $job): array
    {
        $w = DB::table('workshop_jobs')->find($job);
        abort_unless($w, 404);
        app(WorkshopAccess::class)->authorize($actor, $w->branch_id, 'reports_view');
        $intake = DB::table('workshop_intakes')->find($w->intake_id);
        $parts = DB::table('workshop_part_usage as u')->join('workshop_part_requirements as r', 'r.id', '=', 'u.requirement_id')
            ->where('r.job_id', $job)->groupBy('r.product_id')->selectRaw('r.product_id, sum(u.quantity)::numeric(24,8)::text quantity')->get();

        return ['minutes' => (int) DB::table('workshop_labor_entries')->where('job_id', $job)->sum('duration_minutes'),
            'parts' => $parts->map(fn ($p) => '#'.$p->product_id.': '.$p->quantity.' وحدة أساس')->implode('؛ '),
            'repeat_intakes' => $intake->equipment_identity ? app(WorkshopAccess::class)->query($actor)->where('equipment_identity', $intake->equipment_identity)->count() : null];
    }

    public function query(int $actor, array $filters = []): Builder
    {
        $d = Validator::make($filters, ['workshop_id' => 'nullable|integer', 'technician_id' => 'nullable|integer', 'customer_id' => 'nullable|integer', 'project_id' => 'nullable|integer', 'status' => 'nullable|string|max:40', 'billing_mode' => 'nullable|in:billable,partial,warranty,nonbillable,contract', 'overdue' => 'nullable|boolean', 'search' => 'nullable|string|max:200'])->validate();
        $q = app(WorkshopAccess::class)->query($actor, 'workshop_jobs');
        foreach (['workshop_id', 'technician_id', 'customer_id', 'project_id', 'status', 'billing_mode'] as $key) {
            $q->when($d[$key] ?? null, fn ($q, $value) => $q->where($key, $value));
        }
        $q->when($d['overdue'] ?? false, fn ($q) => $q->where('estimated_completion', '<', now())->whereNotIn('status', ['delivered', 'returned_unrepaired']));
        $q->when($d['search'] ?? '', fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'ilike', '%'.$s.'%')->orWhere('customer_name', 'ilike', '%'.$s.'%')));

        return $q;
    }

    public function equipmentHistory(int $actor, int $intake): array
    {
        $i = DB::table('workshop_intakes')->find($intake);
        abort_unless($i, 404);
        app(WorkshopAccess::class)->authorize($actor, $i->branch_id);
        if (! $i->equipment_identity) {
            return [];
        }

        return app(WorkshopAccess::class)->query($actor)->where('equipment_identity', $i->equipment_identity)->orderBy('id')->get(['id', 'number', 'received_at', 'status'])->map(fn ($row) => (array) $row)->all();
    }
}

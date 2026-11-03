<?php

namespace App\Modules\Projects\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ProjectMaterials
{
    private function locked(int $actor, int $project, string $permission): Project
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(70701)');
        $p = Project::lockForUpdate()->findOrFail($project);
        app(ProjectAccess::class)->authorize($actor, $p, $permission);

        return $p;
    }

    public function reserve(int $actor, int $project, int $requirement, int $warehouse, string $quantity, string $key): array
    {
        return DB::transaction(function () use ($actor, $project, $requirement, $warehouse, $quantity, $key) {
            $p = $this->locked($actor, $project, 'material_manage');
            $r = DB::table('project_requirements')->where('project_id', $project)->find($requirement);
            abort_unless($r, 404);
            $result = app(InventoryApi::class)->requestReservation($actor, ['warehouse_id' => $warehouse, 'product_id' => $r->product_id, 'quantity' => $quantity, 'source_module' => 'projects', 'source_id' => (string) $project, 'source_line' => (string) $requirement, 'idempotency_key' => $key]);
            if (! DB::table('project_material_reservations')->where('reservation_id', $result['id'])->exists()) {
                DB::table('project_material_reservations')->insert(['project_id' => $project, 'requirement_id' => $requirement, 'reservation_id' => $result['id']]);
                app(ProjectActions::class)->activity($actor, $p, 'material.reserved', null, ['reservation_id' => $result['id'], 'requested' => $quantity, 'reserved' => $result['reserved']]);
                $p->increment('version');
            }

            return $result;
        }, 5);
    }

    public function issue(int $actor, int $project, int $reservation, string $quantity, string $key): array
    {
        Validator::make(compact('key', 'quantity'), ['key' => 'required|uuid', 'quantity' => ['required', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/']])->validate();
        abort_unless(bccomp($quantity, '0', 8) > 0, 422);

        return DB::transaction(function () use ($actor, $project, $reservation, $quantity, $key) {
            $p = $this->locked($actor, $project, 'material_issue');
            $link = DB::table('project_material_reservations')->where('project_id', $project)->where('reservation_id', $reservation)->first();
            abort_unless($link, 404);
            $old = DB::table('project_material_issues')->where('issue_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->project_id === $project && (int) $old->reservation_id === $reservation && bccomp($old->quantity, $quantity, 8) === 0, 409);

                return app(InventoryApi::class)->reservationStatus($actor, $reservation);
            }
            $id = DB::table('project_material_issues')->insertGetId(['project_id' => $project, 'requirement_id' => $link->requirement_id, 'reservation_id' => $reservation, 'issue_key' => $key, 'quantity' => $quantity, 'created_by' => $actor]);
            $result = app(InventoryApi::class)->fulfillReservation($actor, $reservation, $quantity, $key);
            DB::table('project_material_issues')->where('id', $id)->update(['status' => 'posted', 'posted_at' => now()]);
            if ($p->actual_start === null) {
                $p->update(['actual_start' => now('Africa/Cairo')->toDateString()]);
            }
            $p->increment('version');
            app(ProjectActions::class)->activity($actor, $p, 'material.issued', null, ['issue_id' => $id, 'reservation_id' => $reservation, 'quantity' => $quantity]);

            return $result;
        }, 5);
    }

    public function release(int $actor, int $project, int $reservation, string $key): array
    {
        return DB::transaction(function () use ($actor, $project, $reservation, $key) {
            $p = $this->locked($actor, $project, 'material_manage');
            abort_unless(DB::table('project_material_reservations')->where('project_id', $project)->where('reservation_id', $reservation)->exists(), 404);
            $result = app(InventoryApi::class)->releaseReservation($actor, $reservation, $key);
            app(ProjectActions::class)->activity($actor, $p, 'material.released', null, ['reservation_id' => $reservation]);

            return $result;
        }, 5);
    }

    public function replenish(int $actor, int $project, int $reservation, string $quantity, string $key): array
    {
        return DB::transaction(function () use ($actor, $project, $reservation, $quantity, $key) {
            $p = $this->locked($actor, $project, 'material_manage');
            abort_unless(DB::table('project_material_reservations')->where('project_id', $project)->where('reservation_id', $reservation)->exists(), 404);
            $result = app(InventoryApi::class)->replenishReservation($actor, $reservation, $quantity, $key);
            app(ProjectActions::class)->activity($actor, $p, 'material.backorder_allocated', null, ['reservation_id' => $reservation, 'quantity' => $quantity]);

            return $result;
        }, 5);
    }

    public function reservations(int $actor, int $project): array
    {
        app(ProjectAccess::class)->authorize($actor, Project::findOrFail($project));

        return DB::table('project_material_reservations')->where('project_id', $project)->orderBy('id')->get()->map(fn ($r) => ['requirement_id' => $r->requirement_id] + app(InventoryApi::class)->reservationStatus($actor, $r->reservation_id))->all();
    }
}

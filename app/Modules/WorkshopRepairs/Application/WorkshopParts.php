<?php

namespace App\Modules\WorkshopRepairs\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkshopParts
{
    public function alternative(int $actor, int $job, int $version, int $original, int $product, string $reason): int
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:5000'])->validate();

        return DB::transaction(function () use ($actor, $job, $version, $original, $product, $reason) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'parts_alternative_approve', $version);
            $a->executable($w);
            $r = DB::table('workshop_part_requirements')->where('job_id', $job)->find($original);
            abort_unless($r && $r->product_id !== $product, 422);
            abort_if($r->created_by === $actor, 403, 'البديل يحتاج موافقة شخص مستقل عن طالب القطعة.');
            abort_if(DB::table('workshop_part_reservations')->where('requirement_id', $original)->exists()
                || DB::table('workshop_part_alternatives')->where('original_requirement_id', $original)->exists(), 409, 'البديل قبل تخصيص أي حجز أو صرف للطلب الأصلي.');
            $source = app(ProductsApi::class)->inventoryProduct($actor, $r->product_id);
            $replacement = app(ProductsApi::class)->inventoryProduct($actor, $product);
            abort_unless($source['base_unit_id'] === $replacement['base_unit_id'], 422, 'البديل يجب أن يستخدم وحدة الأساس نفسها.');
            $id = $this->requirement($actor, $job, $version, ['product_id' => $product, 'warehouse_id' => $r->warehouse_id, 'quantity' => $r->quantity]);
            DB::table('workshop_part_alternatives')->insert(['original_requirement_id' => $original, 'replacement_requirement_id' => $id, 'approved_by' => $actor, 'reason' => $reason]);

            return $id;
        }, 5);
    }

    public function customerPart(int $actor, int $job, int $version, array $input): int
    {
        $d = Validator::make($input, ['source_id' => 'nullable|integer', 'description' => 'required|string|min:3|max:5000', 'quantity' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,8})?$/'], 'condition' => 'required|string|min:3|max:5000', 'disposition' => 'required|in:received,used,returned', 'reference' => 'required|string|min:5|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $job, $version, $d) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'custody', $version);
            abort_if(in_array($w->status, ['verified', 'delivered', 'returned_unrepaired']), 409);
            abort_unless(bccomp($d['quantity'], '0', 8) > 0, 422);
            if ($d['disposition'] === 'received') {
                abort_if(! empty($d['source_id']), 422);
            } else {
                $source = DB::table('workshop_customer_parts')->where('job_id', $job)->where('disposition', 'received')->find((int) ($d['source_id'] ?? 0));
                abort_unless($source, 422, 'اختر مكون العميل المستلم أصلاً.');
                $prior = (string) DB::table('workshop_customer_parts')->where('source_id', $source->id)->sum('quantity');
                abort_unless(bccomp(bcadd($prior, $d['quantity'], 8), $source->quantity, 8) <= 0, 422, 'الاستخدام والإرجاع يتجاوزان مكون العميل المستلم.');
                $d['description'] = $source->description;
            }
            $id = DB::table('workshop_customer_parts')->insertGetId($d + ['job_id' => $job, 'created_by' => $actor]);
            $a->touch($actor, $w, 'customer_part.recorded', ['customer_part_id' => $id, 'disposition' => $d['disposition']]);

            return $id;
        }, 5);
    }

    public function requirement(int $actor, int $work, int $version, array $input): int
    {
        $d = Validator::make($input, ['product_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1', 'quantity' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,8})?$/']])->validate();

        return DB::transaction(function () use ($actor, $work, $version, $d) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $work, 'parts_request', $version);
            $a->executable($w);
            $a->project($actor, $w);
            $product = app(ProductsApi::class)->inventoryProduct($actor, (int) $d['product_id']);
            abort_unless($product['kind'] !== 'service' && $product['active'] && in_array($product['tracking'], ['quantity', 'serial'], true) && bccomp($d['quantity'], '0', 8) > 0, 422);
            abort_if($product['tracking'] === 'serial' && bccomp($d['quantity'], bcadd($d['quantity'], '0', 0), 8) !== 0, 422, 'القطعة المسلسلة تتطلب عدد وحدات صحيحًا.');
            $warehouse = app(InventoryApi::class)->warehouse((int) $d['warehouse_id']);
            abort_unless($warehouse && $warehouse['branch_id'] === $w->branch_id && $warehouse['active'], 422);
            app(InventoryApi::class)->getAvailability($actor, (int) $d['warehouse_id'], (int) $d['product_id']);
            $id = DB::table('workshop_part_requirements')->insertGetId($d + ['job_id' => $work, 'tracking' => $product['tracking'], 'created_by' => $actor]);
            $a->touch($actor, $w, 'parts.requested', ['requirement_id' => $id]);

            return $id;
        }, 5);
    }

    public function reserve(int $actor, int $work, int $requirement, string $quantity, string $key): array
    {
        return DB::transaction(function () use ($actor, $work, $requirement, $quantity, $key) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $work, 'parts_request');
            $a->executable($w);
            $r = DB::table('workshop_part_requirements')->where('job_id', $work)->find($requirement);
            abort_unless($r, 404);
            $out = app(InventoryApi::class)->requestReservation($actor, ['source_module' => 'workshop', 'source_id' => (string) $work, 'source_line' => (string) $requirement, 'warehouse_id' => $r->warehouse_id, 'product_id' => $r->product_id, 'quantity' => $quantity, 'idempotency_key' => $key]);
            if (! DB::table('workshop_part_reservations')->where('reservation_id', $out['id'])->exists()) {
                DB::table('workshop_part_reservations')->insert(['requirement_id' => $requirement, 'reservation_id' => $out['id']]);
                $a->touch($actor, $w, 'parts.reserved', ['reservation_id' => $out['id']]);
            }

            return $out;
        }, 5);
    }

    public function issue(int $actor, int $work, int $reservation, string $quantity, string $key): array
    {
        Validator::make(compact('key', 'quantity'), ['key' => 'required|uuid', 'quantity' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,8})?$/']])->validate();
        abort_unless(bccomp($quantity, '0', 8) > 0, 422);

        return DB::transaction(function () use ($actor, $work, $reservation, $quantity, $key) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $work, 'parts_issue');
            $link = DB::table('workshop_part_reservations')->whereIn('requirement_id', DB::table('workshop_part_requirements')->where('job_id', $work)->select('id'))->where('reservation_id', $reservation)->first();
            abort_unless($link, 404);
            $old = DB::table('workshop_part_issues')->where('issue_key', $key)->first();
            if ($old) {
                abort_unless($old->requirement_id === $link->requirement_id && $old->reservation_id === $reservation && bccomp($old->quantity, $quantity, 8) === 0, 409);
                app(InventoryApi::class)->fulfillReservation($actor, $reservation, $quantity, $key);

                return app(InventoryApi::class)->workshopIssueEvidence($actor, $key);
            }
            $a->executable($w);
            $a->project($actor, $w);
            $id = DB::table('workshop_part_issues')->insertGetId(['requirement_id' => $link->requirement_id, 'reservation_id' => $reservation, 'issue_key' => $key, 'quantity' => $quantity, 'created_by' => $actor]);
            app(InventoryApi::class)->fulfillReservation($actor, $reservation, $quantity, $key);
            $out = app(InventoryApi::class)->workshopIssueEvidence($actor, $key);
            DB::table('workshop_part_issues')->where('id', $id)->update(array_replace($out, ['serials' => json_encode($out['serials'], JSON_THROW_ON_ERROR)]));
            $a->touch($actor, $w, 'parts.issued', ['issue_id' => $id] + $out);

            return $out;
        }, 5);
    }

    public function release(int $actor, int $work, int $reservation, string $key): array
    {
        return DB::transaction(function () use ($actor, $work, $reservation, $key) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $work, 'parts_request');
            abort_unless(DB::table('workshop_part_reservations')->where('reservation_id', $reservation)->whereIn('requirement_id', DB::table('workshop_part_requirements')->where('job_id', $work)->select('id'))->exists(), 404);
            $out = app(InventoryApi::class)->releaseReservation($actor, $reservation, $key);
            $a->touch($actor, $w, 'parts.released', ['reservation_id' => $reservation]);

            return $out;
        }, 5);
    }

    public function returnUnused(int $actor, int $work, int $issue, string $quantity, string $key, string $reason, array $serials = []): array
    {
        Validator::make(compact('quantity'), ['quantity' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,8})?$/']])->validate();

        return DB::transaction(function () use ($actor, $work, $issue, $quantity, $key, $reason, $serials) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $work, 'parts_return');
            $a->executable($w);
            $i = DB::table('workshop_part_issues')->whereIn('requirement_id', DB::table('workshop_part_requirements')->where('job_id', $work)->select('id'))->find($issue);
            abort_unless($i, 404);
            $old = DB::table('workshop_part_returns')->where('return_key', $key)->first();
            if ($old) {
                abort_unless($old->issue_id === $issue && bccomp($old->quantity, $quantity, 8) === 0, 409);

                return app(InventoryApi::class)->returnWorkshopIssue($actor, $i->movement_id, $quantity, $key, $reason, $serials);
            }
            $totals = $this->totals($i->requirement_id);
            abort_unless(bccomp($quantity, bcsub(bcsub($totals['issued'], $totals['returned'], 8), $totals['used'], 8), 8) <= 0, 422, 'لا يجوز إرجاع قطع مستخدمة.');
            $out = app(InventoryApi::class)->returnWorkshopIssue($actor, $i->movement_id, $quantity, $key, $reason, $serials);
            DB::table('workshop_part_returns')->insert(['issue_id' => $issue, 'return_key' => $key, 'movement_id' => $out['movement_id'], 'quantity' => $quantity, 'amount' => $out['amount'], 'serials' => json_encode($out['serials'], JSON_THROW_ON_ERROR), 'created_by' => $actor]);
            $a->touch($actor, $w, 'parts.returned', ['issue_id' => $issue, 'quantity' => $quantity] + $out);

            return $out;
        }, 5);
    }

    public function validateReturn(int $actor, int $work, int $requirement, int $movement, string $quantity, string $key, array $serials): void
    {
        $a = app(WorkshopActions::class);
        $w = $a->work($actor, $work, 'parts_return');
        $a->executable($w);
        $r = DB::table('workshop_part_requirements')->where('job_id', $work)->find($requirement);
        $i = DB::table('workshop_part_issues')->where('requirement_id', $requirement)->where('movement_id', $movement)->first();
        abort_unless($r && $i, 404);
        $old = DB::table('workshop_part_returns')->where('return_key', $key)->first();
        if ($old) {
            abort_unless((int) $old->issue_id === (int) $i->id && bccomp($old->quantity, $quantity, 8) === 0 && json_decode($old->serials, true, 512, JSON_THROW_ON_ERROR) === $serials, 409);

            return;
        }
        $t = $this->totals($requirement);
        abort_unless(bccomp($quantity, '0', 8) > 0 && bccomp($quantity, bcsub(bcsub($t['issued'], $t['returned'], 8), $t['used'], 8), 8) <= 0, 422, 'لا يجوز إرجاع قطع مستخدمة.');
        $selected = $r->tracking === 'serial' ? app(InventoryApi::class)->servicePartSerials($actor, 'WorkshopRepairs', $work, $requirement, $quantity, $serials, 'return', $movement) : [];
        abort_unless($r->tracking === 'serial' || $serials === [], 422);
        abort_if(DB::table('workshop_part_serial_usage')->whereIn('serial_id', array_column($selected, 'id'))->exists(), 422, 'الرقم المسلسل مستخدم فعليًا ولا يقبل إرجاع غير المستخدم.');
    }

    public function usage(int $actor, int $work, int $visit, int $requirement, string $quantity, array $serials = []): void
    {
        Validator::make(compact('quantity'), ['quantity' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,8})?$/']])->validate();
        DB::transaction(function () use ($actor, $work, $visit, $requirement, $quantity, $serials) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $work, 'execute');
            app(WorkshopAccess::class)->technician($actor, $w);
            $a->executable($w);
            $v = DB::table('workshop_work_records')->where('job_id', $work)->find($visit);
            abort_unless($v && $v->technician_id === $actor, 403);
            $r = DB::table('workshop_part_requirements')->where('job_id', $work)->find($requirement);
            abort_unless($r, 404);
            $t = $this->totals($requirement);
            abort_unless(bccomp($quantity, '0', 8) > 0 && bccomp(bcadd(bcadd($t['used'], $t['returned'], 8), $quantity, 8), $t['issued'], 8) <= 0, 422, 'الاستهلاك يتجاوز الصرف الفعلي.');
            $selected = $r->tracking === 'serial' ? app(InventoryApi::class)->servicePartSerials($actor, 'WorkshopRepairs', $work, $requirement, $quantity, $serials, 'usage') : [];
            abort_unless($r->tracking === 'serial' || $serials === [], 422);
            abort_if(DB::table('workshop_part_serial_usage')->whereIn('serial_id', array_column($selected, 'id'))->exists(), 422, 'الرقم المسلسل مسجل كمستخدم بالفعل.');
            $usage = DB::table('workshop_part_usage')->insertGetId(['work_record_id' => $visit, 'requirement_id' => $requirement, 'quantity' => $quantity, 'serials' => json_encode(array_column($selected, 'serial'), JSON_THROW_ON_ERROR), 'created_by' => $actor]);
            foreach ($selected as $serial) {
                DB::table('workshop_part_serial_usage')->insert(['usage_id' => $usage, 'serial_id' => $serial['id'], 'movement_id' => $serial['movement_id']]);
            }
            $a->touch($actor, $w, 'parts.used', ['work_record_id' => $visit, 'requirement_id' => $requirement, 'quantity' => $quantity]);
        }, 5);
    }

    public function totals(int $requirement): array
    {
        return ['issued' => (string) DB::table('workshop_part_issues')->where('requirement_id', $requirement)->sum('quantity'), 'returned' => (string) DB::table('workshop_part_returns')->whereIn('issue_id', DB::table('workshop_part_issues')->where('requirement_id', $requirement)->select('id'))->sum('quantity'), 'used' => (string) DB::table('workshop_part_usage')->where('requirement_id', $requirement)->sum('quantity')];
    }

    public function reconciled(int $actor, int $work): void
    {
        $w = DB::table('workshop_jobs')->find($work);
        abort_unless($w, 404);
        foreach (DB::table('workshop_customer_parts')->where('job_id', $work)->where('disposition', 'received')->get() as $source) {
            $consumed = (string) DB::table('workshop_customer_parts')->where('source_id', $source->id)->sum('quantity');
            abort_unless(bccomp($source->quantity, $consumed, 8) === 0, 409, 'مكونات العميل المستلمة تحتاج تسوية الاستخدام أو الإرجاع.');
        }
        foreach (DB::table('workshop_part_requirements')->where('job_id', $work)->get() as $r) {
            $t = $this->totals($r->id);
            abort_unless(bccomp($t['issued'], bcadd($t['used'], $t['returned'], 8), 8) === 0, 409, 'قطع الغيار المصروفة غير مسواة بالاستخدام أو الإرجاع.');
            $ids = DB::table('workshop_part_reservations')->where('requirement_id', $r->id)->pluck('reservation_id')->all();
            abort_unless(app(InventoryApi::class)->workshopReservationsReconciled($actor, $work, $w->branch_id, $ids), 409, 'يلزم حسم الحجز أو إلغاء الباقي.');
        }
    }
}

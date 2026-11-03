<?php

namespace App\Modules\WorkshopRepairs\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WorkshopCustody
{
    public function dispatch(int $actor, int $job, int $version, int $destination, string $key, string $condition, string $reason): int
    {
        Validator::make(compact('key', 'condition', 'reason'), ['key' => 'required|uuid', 'condition' => 'required|string|min:5|max:5000', 'reason' => 'required|string|min:5|max:5000'])->validate();

        return DB::transaction(function () use ($actor, $job, $version, $destination, $key, $condition, $reason) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'custody');
            $old = DB::table('workshop_transfers')->where('creation_key', $key)->first();
            if ($old) {
                abort_unless($old->job_id === $job && $old->destination_workshop_id === $destination && $old->condition === $condition && $old->reason === $reason && $old->dispatched_by === $actor, 409);

                return $old->id;
            }
            abort_unless($w->version === $version && ! $w->pending_transfer_id && ! in_array($w->status, ['verified', 'delivered', 'returned_unrepaired', 'on_hold']), 409);
            $to = DB::table('workshops')->find($destination);
            abort_unless($to && $to->active && $to->branch_id === $w->branch_id && $to->id !== $w->workshop_id, 422);
            app(WorkshopAccess::class)->authorize($actor, $to->branch_id, 'custody');
            $id = DB::table('workshop_transfers')->insertGetId(['creation_key' => $key, 'job_id' => $job, 'source_workshop_id' => $w->workshop_id, 'destination_workshop_id' => $destination, 'reason' => $reason, 'condition' => $condition, 'dispatched_by' => $actor]);
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($w->intake_id);
            $a->custody($actor, $i, 'قيد النقل إلى '.$to->name, 'in_transit', $reason, $key, $condition);
            DB::table('workshop_intakes')->where('id', $i->id)->update(['location' => 'قيد النقل إلى '.$to->name, 'version' => $i->version + 1, 'updated_at' => now()]);
            $a->touch($actor, $w, 'custody.dispatched', ['transfer_id' => $id], ['pending_transfer_id' => $id, 'hold_from' => $w->status, 'status' => 'on_hold']);

            return $id;
        }, 5);
    }

    public function receive(int $actor, int $job, int $version, int $transfer, string $key, string $condition): void
    {
        Validator::make(compact('key', 'condition'), ['key' => 'required|uuid', 'condition' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $job, $version, $transfer, $key, $condition) {
            $a = app(WorkshopActions::class);
            $w = $a->work($actor, $job, 'custody');
            $t = DB::table('workshop_transfers')->where('job_id', $job)->find($transfer);
            abort_unless($t, 404);
            $old = DB::table('workshop_transfer_receipts')->where('transfer_id', $transfer)->first();
            if ($old) {
                abort_unless($old->receipt_key === $key && $old->received_by === $actor && $old->condition === $condition, 409);

                return;
            }
            abort_unless($w->version === $version && $w->pending_transfer_id === $transfer, 409);
            $to = DB::table('workshops')->find($t->destination_workshop_id);
            abort_unless($to && $to->active && $to->branch_id === $w->branch_id, 409);
            DB::table('workshop_transfer_receipts')->insert(['transfer_id' => $transfer, 'receipt_key' => $key, 'received_by' => $actor, 'condition' => $condition]);
            $i = DB::table('workshop_intakes')->lockForUpdate()->find($w->intake_id);
            $a->custody($actor, $i, $to->name, 'received_transfer', 'استلام نقل ورشة #'.$transfer, $key, $condition);
            DB::table('workshop_intakes')->where('id', $i->id)->update(['location' => $to->name, 'workshop_id' => $to->id, 'version' => $i->version + 1, 'updated_at' => now()]);
            $a->touch($actor, $w, 'custody.transfer_received', ['transfer_id' => $transfer], ['workshop_id' => $to->id, 'technician_id' => null, 'pending_transfer_id' => null, 'status' => $w->hold_from, 'hold_from' => null]);
        }, 5);
    }
}

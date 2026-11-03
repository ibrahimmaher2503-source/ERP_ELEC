<?php

namespace App\Modules\FieldMaintenance\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class MaintenanceCostProposals
{
    public function propose(int $actor, string $kind, ?int $work, array $input): int
    {
        abort_unless(in_array($kind, ['labor_rate', 'service']), 422);

        return DB::transaction(function () use ($actor, $kind, $work, $input) {
            $a = app(MaintenanceActions::class);
            $a->lock();
            $rules = $kind === 'service' ? ['product_id' => 'required|integer|min:1', 'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'], 'date' => 'required|date_format:Y-m-d', 'price_reference' => 'required|string|min:5|max:2000'] : ['branch_id' => 'required|integer|min:1', 'technician_id' => 'required|integer|min:1', 'hourly_cost' => ['required', 'string', 'regex:/^[0-9]{1,16}(\.[0-9]{1,2})?$/'], 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'required|date_format:Y-m-d|after_or_equal:effective_from', 'reference' => 'required|string|min:5|max:255'];
            $data = Validator::make($input, $rules, [], __('maintenance.fields', [], 'ar'))->validate();
            if ($kind === 'service') {
                $w = $a->work($actor, $work, 'billing_request');
                abort_unless($w->billable && in_array($w->status, ['work_completed', 'verified']), 409);
                $branch = $w->branch_id;
            } else {
                $branch = (int) $data['branch_id'];
                app(MaintenanceAccess::class)->authorize($actor, $branch, 'labor_record');
            }
            $id = DB::table('maintenance_cost_proposals')->insertGetId(['kind' => $kind, 'branch_id' => $branch, 'work_order_id' => $work, 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'payload_hash' => $this->hash($data), 'created_by' => $actor]);
            $a->audit($actor, 'maintenance_cost_proposal', $id, 'cost.proposed', null, ['kind' => $kind, 'work_order_id' => $work]);

            return $id;
        }, 5);
    }

    public function matching(int $actor, int $proposer, string $kind, int $branch, ?int $work, array $data): int
    {
        abort_if($actor === $proposer, 403, 'الاعتماد يتطلب شخصًا مستقلًا.');
        $proposal = DB::table('maintenance_cost_proposals')->where('kind', $kind)->where('branch_id', $branch)->where('work_order_id', $work)->where('created_by', $proposer)->where('payload_hash', $this->hash($data))->orderByDesc('id')->first();
        abort_unless($proposal, 409, 'يلزم تقديم مرجع مطابق من مقدمه الفعلي قبل الاعتماد.');

        return $proposal->id;
    }

    private function hash(array $data): string
    {
        foreach ($data as $key => $value) {
            if (str_ends_with($key, '_id')) {
                $data[$key] = (int) $value;
            }
        }
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}

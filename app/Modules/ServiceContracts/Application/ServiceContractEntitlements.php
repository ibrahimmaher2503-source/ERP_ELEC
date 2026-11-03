<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceContractEntitlements
{
    public function remaining(int $contract, array $terms, array $equipment, string $kind, ?int $except = null): array
    {
        $usage = DB::table('service_contract_work')->where('contract_id', $contract)->where('status', '<>', 'released')->when($except, fn ($q) => $q->where('id', '<>', $except));
        $visits = (clone $usage)->where('equipment_ref', $equipment['equipment_ref'])->where('site_id', $equipment['site_id'])->where('kind', $kind)->sum('visits');
        $minutes = (int) (clone $usage)->sum('labor_minutes');
        $usedParts = (clone $usage)->get(['parts_snapshot']);
        $parts = [];
        foreach ($terms['parts'] as $part) {
            $used = '0';
            foreach ($usedParts as $row) {
                foreach (json_decode($row->parts_snapshot, true, 512, JSON_THROW_ON_ERROR) as $usedPart) {
                    if ((int) $usedPart['product_id'] === (int) $part['product_id']) {
                        $used = bcadd($used, $usedPart['quantity'], 8);
                    }
                }
            }
            $parts[] = ['product_id' => (int) $part['product_id'], 'quantity' => bcsub($part['quantity'], $used, 8)];
        }

        return ['remaining_visits' => max(0, $equipment[$kind.'_visits'] - (int) $visits), 'remaining_labor_minutes' => max(0, $terms['covered_labor_minutes'] - $minutes), 'remaining_parts' => $parts];
    }

    private function source(int $actor, string $owner, int $source): array
    {
        return match ($owner) {
            'FieldMaintenance' => app(FieldMaintenanceApi::class)->contractWork($actor, $source),
            'WorkshopRepairs' => app(WorkshopApi::class)->contractWork($actor, $source),
            default => throw ValidationException::withMessages(['owner' => 'مصدر عمل تعاقدي غير معتمد.']),
        };
    }

    public function sync(int $actor, string $owner, int $source): void
    {
        DB::transaction(function () use ($actor, $owner, $source) {
            $a = app(ServiceContractActions::class);
            $a->lock();
            $usage = DB::table('service_contract_work')->where('owner', $owner)->where('source_id', $source)->lockForUpdate()->first();
            abort_unless($usage && $usage->status === 'reserved', 409);
            $c = $a->contract($actor, (int) $usage->contract_id, 'coverage_use');
            abort_unless($c->status === 'active', 409, 'العقد موقوف أو منتهٍ.');
            $work = $this->source($actor, $owner, $source);
            abort_unless($work['contract_id'] === (int) $c->id, 409);
            $r = DB::table('service_contract_revisions')->find($usage->revision_id);
            $d = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
            $equipment = collect($d['coverage'])->first(fn ($i) => $i['equipment_ref'] === $usage->equipment_ref && $i['site_id'] === $usage->site_id);
            $left = $this->remaining((int) $c->id, $d, $equipment, $usage->kind, (int) $usage->id);
            if (max(1, $work['visits']) > $left['remaining_visits'] || $work['labor_minutes'] > $left['remaining_labor_minutes']) {
                throw ValidationException::withMessages(['coverage' => 'التنفيذ يتجاوز المتبقي من زيارات أو وقت العقد.']);
            }
            foreach ($work['work_dates'] as $date) {
                if ($date < $d['starts_on'] || $date > $d['ends_on']) {
                    throw ValidationException::withMessages(['coverage' => 'التنفيذ خارج مدة التغطية.']);
                }
            }
            foreach ($work['parts'] as $part) {
                $limit = collect($left['remaining_parts'])->firstWhere('product_id', $part['product_id']);
                if (! $limit || in_array($part['product_id'], $d['excluded_parts']) || bccomp($part['quantity'], $limit['quantity'], 8) > 0) {
                    throw ValidationException::withMessages(['coverage' => 'القطعة مستثناة أو تتجاوز كمية التغطية.']);
                }
            }
            DB::table('service_contract_work')->where('id', $usage->id)->update(['visits' => max(1, $work['visits']), 'labor_minutes' => $work['labor_minutes'],
                'parts_snapshot' => json_encode($work['parts'], JSON_THROW_ON_ERROR), 'source_version' => $work['version']]);
            $a->audit($actor, $c, 'entitlement.usage_recorded', ['work_id' => $usage->id, 'source_version' => $work['version'], 'labor_minutes' => $work['labor_minutes'], 'parts' => $work['parts']]);
        }, 3);
    }

    public function reserve(int $actor, int $contract, string $owner, int $source): int
    {
        return DB::transaction(function () use ($actor, $contract, $owner, $source) {
            $a = app(ServiceContractActions::class);
            $c = $a->contract($actor, $contract, 'coverage_use');
            $work = $this->source($actor, $owner, $source);
            abort_unless($work['contract_id'] === $contract, 409);
            $old = DB::table('service_contract_work')->where('owner', $owner)->where('source_id', $source)->first();
            if ($old) {
                abort_unless((int) $old->contract_id === $contract && $old->status !== 'released', 409);

                return (int) $old->id;
            }
            abort_unless($work['reservable'], 409);
            $coverage = app(ServiceContractsService::class)->coverage($actor, $contract, $work['customer_id'], $work['branch_id'], $work['equipment_ref'], $work['site_id'], $work['service_date'], $work['kind']);
            $id = DB::table('service_contract_work')->insertGetId(['contract_id' => $contract, 'revision_id' => $coverage['revision_id'], 'owner' => $owner, 'source_id' => $source,
                'equipment_ref' => $coverage['equipment_ref'], 'site_id' => $work['site_id'], 'service_date' => $work['service_date'], 'kind' => $work['kind'], 'created_by' => $actor]);
            $a->audit($actor, $c, 'entitlement.reserved', ['work_id' => $id, 'owner' => $owner, 'source_id' => $source]);

            return $id;
        }, 3);
    }

    public function consume(int $actor, string $owner, int $source): int
    {
        return DB::transaction(function () use ($actor, $owner, $source) {
            $a = app(ServiceContractActions::class);
            $a->lock();
            $usage = DB::table('service_contract_work')->where('owner', $owner)->where('source_id', $source)->lockForUpdate()->first();
            abort_unless($usage, 409, 'يلزم حجز استحقاق العقد قبل التنفيذ.');
            $c = $a->contract($actor, (int) $usage->contract_id, 'coverage_use');
            $work = $this->source($actor, $owner, $source);
            abort_unless($work['contract_id'] === (int) $c->id && $work['complete'] && $usage->status !== 'released', 409);
            if ($usage->status === 'consumed') {
                return (int) $usage->id;
            }
            $r = DB::table('service_contract_revisions')->find($usage->revision_id);
            $d = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
            $equipment = collect($d['coverage'])->first(fn ($i) => $i['equipment_ref'] === $usage->equipment_ref && $i['site_id'] === $usage->site_id);
            $left = $this->remaining((int) $c->id, $d, $equipment, $usage->kind, (int) $usage->id);
            if ($work['visits'] < 1 || $work['visits'] > $left['remaining_visits'] || $work['labor_minutes'] > $left['remaining_labor_minutes']) {
                throw ValidationException::withMessages(['coverage' => 'الزيارات أو الوقت الفعلي يتجاوز المتبقي من العقد.']);
            }
            foreach ($work['work_dates'] as $date) {
                if ($date < $d['starts_on'] || $date > $d['ends_on']) {
                    throw ValidationException::withMessages(['coverage' => 'تاريخ تنفيذ فعلي خارج مدة التغطية.']);
                }
            }
            foreach ($work['parts'] as $part) {
                $limit = collect($left['remaining_parts'])->firstWhere('product_id', $part['product_id']);
                if (! $limit || in_array($part['product_id'], $d['excluded_parts']) || bccomp($part['quantity'], $limit['quantity'], 8) > 0) {
                    throw ValidationException::withMessages(['coverage' => 'قطعة مستثناة أو غير مغطاة أو تتجاوز الرصيد التعاقدي.']);
                }
                if (bccomp($part['quantity'], '0', 8) > 0) {
                    DB::table('service_contract_work_parts')->insert(['work_id' => $usage->id, 'product_id' => $part['product_id'], 'quantity' => $part['quantity']]);
                }
            }
            DB::table('service_contract_work')->where('id', $usage->id)->update(['status' => 'consumed', 'visits' => $work['visits'], 'labor_minutes' => $work['labor_minutes'],
                'parts_snapshot' => json_encode($work['parts'], JSON_THROW_ON_ERROR), 'source_version' => $work['version'], 'consumed_at' => now()]);
            $a->audit($actor, $c, 'entitlement.consumed', ['work_id' => $usage->id, 'owner' => $owner, 'source_id' => $source, 'visits' => $work['visits'], 'labor_minutes' => $work['labor_minutes']]);

            return (int) $usage->id;
        }, 3);
    }

    public function release(int $actor, string $owner, int $source): void
    {
        DB::transaction(function () use ($actor, $owner, $source) {
            $a = app(ServiceContractActions::class);
            $a->lock();
            $usage = DB::table('service_contract_work')->where('owner', $owner)->where('source_id', $source)->lockForUpdate()->first();
            abort_unless($usage, 409);
            $c = $a->contract($actor, (int) $usage->contract_id, 'coverage_use');
            $work = $this->source($actor, $owner, $source);
            abort_unless($work['cancelled'] && $work['labor_minutes'] === 0 && $work['parts'] === [], 409);
            if ($usage->status === 'released') {
                return;
            }
            abort_unless($usage->status === 'reserved', 409);
            DB::table('service_contract_work')->where('id', $usage->id)->update(['status' => 'released', 'released_at' => now()]);
            $a->audit($actor, $c, 'entitlement.released', ['work_id' => $usage->id]);
        }, 3);
    }
}

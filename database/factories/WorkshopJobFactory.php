<?php

namespace Database\Factories;

use App\Modules\WorkshopRepairs\Domain\WorkshopJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkshopJobFactory extends Factory
{
    protected $model = WorkshopJob::class;

    public function configure(): static
    {
        return $this->afterCreating(function (WorkshopJob $job): void {
            DB::transaction(function () use ($job): void {
                $intake = DB::table('workshop_intakes')->lockForUpdate()->find($job->intake_id);
                if (! $intake || $intake->status !== 'received'
                    || ! DB::table('workshop_custody_events')->where('intake_id', $intake->id)->where('status', 'received')->exists()) {
                    throw new \RuntimeException('WorkshopJobFactory requires an intake received through a real test action.');
                }
                $workshop = DB::table('workshops')->find($job->workshop_id);
                if (! $workshop || ! $workshop->active || $workshop->branch_id !== $intake->branch_id) {
                    throw new \RuntimeException('WorkshopJobFactory workshop must be active in the received intake branch.');
                }

                DB::table('workshop_intakes')->where('id', $intake->id)->update([
                    'workshop_id' => $workshop->id,
                    'location' => $workshop->name,
                    'status' => 'inspection',
                    'version' => $intake->version + 1,
                    'updated_at' => now(),
                ]);
                DB::table('workshop_custody_events')->insert([
                    'intake_id' => $intake->id,
                    'event_key' => $job->creation_key,
                    'source_location' => $intake->location,
                    'destination_location' => $workshop->name,
                    'responsible_id' => $job->created_by,
                    'condition' => $intake->arrival_condition,
                    'reason' => 'Synthetic test factory job assignment.',
                    'status' => 'inspection',
                ]);
            });
        });
    }

    public function definition(): array
    {
        $intake = DB::table('workshop_intakes as i')
            ->where('i.status', 'received')
            ->whereNotNull('i.received_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('workshop_custody_events as e')->whereColumn('e.intake_id', 'i.id')->where('e.status', 'received'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('workshop_jobs as j')->whereColumn('j.intake_id', 'i.id')->whereNotIn('j.status', ['delivered', 'returned_unrepaired']))
            ->orderByDesc('i.id')
            ->first();
        $workshop = $intake ? DB::table('workshops')->where('branch_id', $intake->branch_id)->where('active', true)->orderBy('id')->first() : null;
        if (! $intake || ! $workshop) {
            throw new \RuntimeException('WorkshopJobFactory requires a genuinely received test intake and an active workshop in its branch.');
        }

        return [
            'creation_key' => (string) Str::uuid(),
            'number' => 'DEMO-EG-WJ-'.Str::upper(Str::random(10)),
            'intake_id' => $intake->id,
            'branch_id' => $intake->branch_id,
            'customer_id' => $intake->customer_id,
            'customer_name' => $intake->customer_name,
            'project_id' => $intake->project_id,
            'workshop_id' => $workshop->id,
            'technician_id' => null,
            'priority' => 'normal',
            'diagnosis' => null,
            'planned_work' => null,
            'required_tests' => [],
            'estimated_minutes' => null,
            'estimated_completion' => null,
            'billing_mode' => 'nonbillable',
            'status' => 'inspection',
            'hold_from' => null,
            'version' => 1,
            'created_by' => $intake->created_by,
        ];
    }
}

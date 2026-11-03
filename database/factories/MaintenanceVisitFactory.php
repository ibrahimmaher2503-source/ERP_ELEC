<?php

namespace Database\Factories;

use App\Modules\FieldMaintenance\Domain\MaintenanceVisit;
use App\Modules\FieldMaintenance\Domain\MaintenanceWorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MaintenanceVisitFactory extends Factory
{
    protected $model = MaintenanceVisit::class;

    public function definition(): array
    {
        return ['creation_key' => (string) Str::uuid(), 'work_order_id' => MaintenanceWorkOrder::factory(), 'assignment_id' => function (array $a) {
            $w = MaintenanceWorkOrder::findOrFail($a['work_order_id']);

            return DB::table('maintenance_assignments')->insertGetId(['work_order_id' => $w->id, 'technician_id' => $w->responsible_id, 'starts_at' => now()->addWeek()->startOfHour(), 'ends_at' => now()->addWeek()->startOfHour()->addHours(2), 'active' => false, 'assigned_by' => $w->created_by, 'override_reason' => 'DEMO-EG: اقتراح موعد غير مفعل، لا يثبت تكليفًا فعليًا.']);
        }, 'technician_id' => fn (array $a) => MaintenanceWorkOrder::findOrFail($a['work_order_id'])->responsible_id, 'scheduled_at' => now()->addWeek()->startOfHour(), 'template_snapshot' => '[]', 'status' => 'planned'];
    }
}

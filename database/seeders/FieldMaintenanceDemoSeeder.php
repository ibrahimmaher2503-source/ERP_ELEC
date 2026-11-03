<?php

namespace Database\Seeders;

use App\Modules\FieldMaintenance\Domain\MaintenanceChecklistTemplate;
use App\Modules\FieldMaintenance\Domain\MaintenanceRequest;
use App\Modules\FieldMaintenance\Domain\MaintenanceVisit;
use App\Modules\FieldMaintenance\Domain\MaintenanceWorkOrder;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FieldMaintenanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $expected) {
            throw new \RuntimeException('Local/testing PostgreSQL demo only.');
        }
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(80801)');
            foreach ([MaintenanceRequest::class => 'DEMO-EG-MR-001', MaintenanceWorkOrder::class => 'DEMO-EG-WO-001', MaintenanceChecklistTemplate::class => 'DEMO-EG-CHK-001'] as $model => $identity) {
                $field = $model === MaintenanceChecklistTemplate::class ? 'code' : 'number';
                if (! $model::where($field, $identity)->exists()) {
                    $r = $model::factory()->create([$field => $identity]);
                    app(RecordAudit::class)->execute(null, 'maintenance_demo', $r->id, 'demo.created', null, ['identity' => $identity], 'succeeded', 'FieldMaintenance');
                }
            }
            $w = MaintenanceWorkOrder::where('number', 'DEMO-EG-WO-001')->firstOrFail();
            if (! MaintenanceVisit::where('work_order_id', $w->id)->exists()) {
                $visit = MaintenanceVisit::factory()->create(['work_order_id' => $w->id]);
                app(RecordAudit::class)->execute(null, 'maintenance_demo', $visit->id, 'demo.created', null, ['identity' => 'DEMO-EG-VISIT-001', 'work_order_id' => $w->id, 'state' => 'planned'], 'succeeded', 'FieldMaintenance');
            }
        });
    }
}

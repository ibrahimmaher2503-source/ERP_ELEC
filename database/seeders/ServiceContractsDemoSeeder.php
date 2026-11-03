<?php

namespace Database\Seeders;

use App\Modules\ServiceContracts\Domain\ServiceContract;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ServiceContractsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $expected) {
            throw new \RuntimeException('Service contract demo is restricted to local/testing PostgreSQL.');
        }
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(10401)');
            DB::select('select pg_advisory_xact_lock(101001)');
            foreach ([1, 2, 3] as $i) {
                $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-demo-eg-service-contract-'.$i)->toString();
                if (! ServiceContract::where('creation_key', $key)->exists()) {
                    $contract = ServiceContract::factory()->create(['creation_key' => $key]);
                    app(RecordAudit::class)->execute(null, 'service_contract', $contract->id, 'demo.created', null,
                        ['identity' => 'DEMO-EG-SC-00'.$i, 'state' => 'draft'], 'succeeded', 'ServiceContracts');
                }
            }
        });
    }
}

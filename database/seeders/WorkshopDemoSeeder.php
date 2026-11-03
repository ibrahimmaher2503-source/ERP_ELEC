<?php

namespace Database\Seeders;

use App\Modules\System\Application\RecordAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkshopDemoSeeder extends Seeder
{
    public function run(): void
    {
        $expectedDatabase = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $expectedDatabase) {
            throw new \RuntimeException('Workshop demo data is limited to the local/testing PostgreSQL database.');
        }

        DB::transaction(function (): void {
            DB::select('select pg_advisory_xact_lock(90901)');
            $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->first();
            $customer = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->first();
            $disabledUser = DB::table('users')->where('active', false)->whereNull('email_verified_at')
                ->where(function ($query): void {
                    $query->where('email', 'like', 'demo.user%@example.test')
                        ->orWhere('email', 'like', 'staff.%@egypt-market.example.test');
                })->orderBy('id')->first();
            if (! $branch || ! $customer || ! $disabledUser) {
                throw new \RuntimeException('WorkshopDemoSeeder requires the Egyptian demo branch, customer and disabled demo account.');
            }

            foreach ([
                ['DEMO-EG-WS-001', 'ورشة القاهرة التجريبية'],
                ['DEMO-EG-WS-002', 'ورشة الجيزة التجريبية'],
                ['DEMO-EG-WS-003', 'ورشة الإسكندرية التجريبية'],
                ['DEMO-EG-WS-004', 'ورشة المنصورة التجريبية'],
            ] as [$code, $name]) {
                if (DB::table('workshops')->where('code', $code)->exists()) {
                    continue;
                }
                $workshopId = DB::table('workshops')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'specialty' => 'تجهيز تجريبي لفحص القواطع الكهربائية',
                    'branch_id' => $branch->id,
                    'supervisor_id' => $disabledUser->id,
                    'warehouse_id' => null,
                    'capacity' => 4,
                    'schedule' => json_encode([['day' => 0, 'start' => '08:00', 'end' => '16:00']], JSON_THROW_ON_ERROR),
                    'active' => false,
                    'version' => 1,
                    'created_by' => $disabledUser->id,
                ]);
                app(RecordAudit::class)->execute(null, 'workshop_demo', $workshopId, 'demo.created', null, ['identity' => $code], 'succeeded', 'WorkshopRepairs');
            }

            if (! DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->exists()) {
                $intakeId = DB::table('workshop_intakes')->insertGetId([
                    'creation_key' => '2f31a242-0e7d-4d01-9111-57a8f2ae9001',
                    'number' => 'DEMO-EG-WI-001',
                    'branch_id' => $branch->id,
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'equipment_description' => 'قاطع كهربائي مقدم للفحص وفق مسودة تجريبية.',
                    'model' => 'DEMO-EG-BREAKER',
                    'serial_number' => null,
                    'accessories' => 'لم تسجل ملحقات؛ المسودة لم تستلم فعلياً.',
                    'reported_fault' => 'بلاغ تجريبي عن فصل متكرر، دون تشخيص أو اختبار.',
                    'arrival_condition' => 'لم تسجل حالة وصول فعلية؛ المسودة غير مستلمة.',
                    'customer_owned' => true,
                    'received_at' => null,
                    'received_by' => null,
                    'acknowledgment' => null,
                    'location' => 'مسودة استلام الورشة',
                    'status' => 'draft',
                    'version' => 1,
                    'created_by' => $disabledUser->id,
                ]);
                app(RecordAudit::class)->execute(null, 'workshop_demo', $intakeId, 'demo.created', null, ['identity' => 'DEMO-EG-WI-001', 'state' => 'draft'], 'succeeded', 'WorkshopRepairs');
            }
        });
    }
}

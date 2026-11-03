<?php

namespace Database\Factories;

use App\Modules\WorkshopRepairs\Domain\WorkshopIntake;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkshopIntakeFactory extends Factory
{
    protected $model = WorkshopIntake::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->first();
        $customer = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->first();
        $creatorId = DB::table('users')->where('active', false)->value('id');
        if (! $branch || ! $customer || ! $creatorId) {
            throw new \RuntimeException('WorkshopIntakeFactory requires an Egyptian demo branch, customer and disabled demo creator.');
        }

        return [
            'creation_key' => (string) Str::uuid(),
            'number' => 'DEMO-EG-WI-'.Str::upper(Str::random(10)),
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'site_id' => null,
            'contact_id' => null,
            'product_id' => null,
            'project_id' => null,
            'maintenance_work_order_id' => null,
            'maintenance_visit_id' => null,
            'equipment_description' => 'قاطع كهربائي وارد للفحص التجريبي',
            'model' => 'DEMO-EG-BREAKER',
            'serial_number' => null,
            'accessories' => 'لا توجد ملحقات مسجلة في هذا البلاغ التجريبي.',
            'reported_fault' => 'بلاغ تجريبي عن فصل متكرر، دون تنفيذ أو فحص فعلي.',
            'arrival_condition' => 'حالة وصول افتراضية محفوظة في مسودة غير مستلمة.',
            'customer_owned' => true,
            'received_at' => null,
            'received_by' => null,
            'acknowledgment' => null,
            'location' => 'مسودة استلام الورشة',
            'workshop_id' => null,
            'status' => 'draft',
            'version' => 1,
            'created_by' => $creatorId,
        ];
    }
}

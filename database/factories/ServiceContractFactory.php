<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Employee;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ServiceContracts\Domain\ServiceContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServiceContractFactory extends Factory
{
    protected $model = ServiceContract::class;

    public function definition(): array
    {
        return ['creation_key' => (string) Str::uuid(), 'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(), 'responsible_employee_id' => fn (array $a) => Employee::factory()->create(['branch_id' => $a['branch_id']])->id,
            'status' => 'draft', 'version' => 1, 'created_by' => User::factory()->disabledDemo()];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ServiceContract $contract): void {
            $service = Product::factory()->create(['kind' => 'service', 'tracking' => 'none', 'name' => 'DEMO-EG: صيانة دورية للوحات كهربائية']);
            $today = now('Africa/Cairo')->toImmutable();
            $data = ['branch_id' => (int) $contract->branch_id, 'customer_id' => (int) $contract->customer_id,
                'responsible_employee_id' => (int) $contract->responsible_employee_id,
                'title' => 'DEMO-EG: مسودة عقد صيانة محل بمدينة نصر', 'starts_on' => $today->toDateString(), 'ends_on' => $today->addYear()->toDateString(),
                'value' => '12000.00', 'service_product_id' => $service->id, 'billing_mode' => 'installments',
                'terms' => 'مسودة تجريبية: معاينة ربع سنوية للوحة الجهد المنخفض؛ لا توقيع أو تفعيل أو تنفيذ أو فوترة.',
                'sla_response_minutes' => 240, 'sla_resolution_minutes' => 1440, 'covered_labor_minutes' => 960,
                'coverage' => [['equipment_ref' => 'DEMO-EG-PANEL-001', 'site_id' => null, 'preventive_visits' => 4, 'corrective_visits' => 2]],
                'parts' => [], 'excluded_parts' => [], 'boq' => [], 'schedule' => []];
            for ($i = 0; $i < 4; $i++) {
                $data['schedule'][] = ['code' => 'Q'.($i + 1), 'due_date' => $today->addMonths($i * 3)->toDateString(), 'amount' => '3000.00', 'description' => 'قسط صيانة تجريبي ربع سنوي'];
            }
            $revision = DB::table('service_contract_revisions')->insertGetId(['contract_id' => $contract->id, 'revision' => 1,
                'creation_key' => $contract->creation_key, 'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_by' => $contract->created_by]);
            $contract->update(['current_revision_id' => $revision]);
        });
    }
}

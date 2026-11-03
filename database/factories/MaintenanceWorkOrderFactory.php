<?php

namespace Database\Factories;

use App\Modules\FieldMaintenance\Domain\MaintenanceWorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MaintenanceWorkOrderFactory extends Factory
{
    protected $model = MaintenanceWorkOrder::class;

    public function definition(): array
    {
        $data = (new MaintenanceRequestFactory)->definition();
        unset($data['reported_at']);
        $data['number'] = 'DEMO-EG-WO-'.Str::upper(Str::random(10));

        return $data + ['instructions' => 'معاينة تجريبية مخططة فقط؛ لا تكليف معتمد أو ساعات عمل أو قطع غيار مصروفة.', 'billable' => false];
    }
}

<?php

namespace Database\Factories;

use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return ['code' => 'DEMO-EG-EMP-'.Str::upper(Str::random(10)), 'name' => fake('ar_EG')->name(),
            'branch_id' => Branch::factory(), 'user_id' => null, 'starts_on' => now('Africa/Cairo')->subYear()->toDateString(),
            'ends_on' => null, 'active' => true, 'job_title' => 'فني صيانة كهربائية — سجل تجريبي'];
    }
}

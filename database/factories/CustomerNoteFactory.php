<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerNote> */
class CustomerNoteFactory extends Factory
{
    protected $model = CustomerNote::class;

    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'author_id' => null, 'body' => 'ملاحظة تجريبية: يرجى مراجعة بيان الكميات وأكواد الأصناف مع مهندس المشروع قبل التوريد.'];
    }
}

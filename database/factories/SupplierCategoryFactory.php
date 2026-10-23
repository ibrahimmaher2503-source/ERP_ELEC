<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\SupplierCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SupplierCategory> */
class SupplierCategoryFactory extends Factory
{
    protected $model = SupplierCategory::class;

    public function definition(): array
    {
        $code = 'DEMO-SCAT-'.Str::upper(Str::random(8));

        return [
            'code' => $code,
            'name' => fake()->randomElement(['موردو القواطع والحماية', 'موردو الكابلات والأسلاك', 'مصنعو لوحات الكهرباء', 'خدمات هندسية وصيانة']).' - تجريبي '.$code,
            'active' => true,
        ];
    }
}

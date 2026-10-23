<?php

namespace Database\Factories;

use App\Modules\Products\Domain\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Brand> */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    public function definition(): array
    {
        $suffix = Str::upper(Str::random(6));

        return [
            'code' => 'BR-'.Str::upper(Str::random(10)),
            'name' => 'النور للتجهيزات الكهربائية - تجريبي '.$suffix,
            'active' => true,
            'version' => 1,
        ];
    }
}

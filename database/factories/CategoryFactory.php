<?php

namespace Database\Factories;

use App\Modules\Products\Domain\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'code' => 'CAT-'.Str::upper(Str::random(10)),
            'name' => fake()->randomElement(['كابلات وأسلاك', 'القواطع والحماية', 'اللوحات الكهربائية', 'مستلزمات التركيبات']),
            'active' => true,
            'version' => 1,
            'parent_id' => null,
        ];
    }
}

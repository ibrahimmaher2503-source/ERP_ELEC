<?php

namespace Database\Factories;

use App\Modules\Products\Domain\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Unit> */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        $units = [
            ['name' => 'قطعة', 'dimension' => 'count'],
            ['name' => 'متر', 'dimension' => 'length'],
            ['name' => 'كيلوجرام', 'dimension' => 'mass'],
            ['name' => 'لتر', 'dimension' => 'volume'],
            ['name' => 'ساعة', 'dimension' => 'time'],
        ];
        $unit = fake()->randomElement($units);

        return [
            'code' => 'UOM-'.Str::upper(Str::random(10)),
            'name' => $unit['name'].' '.Str::upper(Str::random(5)),
            'dimension' => $unit['dimension'],
            'active' => true,
            'version' => 1,
        ];
    }
}

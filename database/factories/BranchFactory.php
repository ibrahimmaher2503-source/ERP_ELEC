<?php

namespace Database\Factories;

use App\Modules\Organization\Domain\Branch;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @extends Factory<Model> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        $place = EgyptianMarket::location(fake()->numberBetween(1, 1000));

        return [
            'company_id' => 1, 'code' => 'DEMO-EG-BR-'.Str::upper(Str::random(8)),
            'name' => 'فرع '.$place['city'], 'address' => $place['line'], 'active' => true, 'version' => 1,
        ];
    }
}

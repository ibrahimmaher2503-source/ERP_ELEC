<?php

namespace Database\Factories;

use App\Modules\Sales\Domain\QuotationTerm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<QuotationTerm> */
class QuotationTermFactory extends Factory
{
    protected $model = QuotationTerm::class;

    /**
     * The factory default is inactive so a synthetic template cannot masquerade as approved commercial wording.
     */
    public function definition(): array
    {
        $code = 'DEMO-TERM-'.Str::upper(Str::random(8));

        return [
            'code' => $code,
            'kind' => 'payment',
            'revision' => 1,
            'name' => 'Synthetic test term '.$code,
            'body' => 'Synthetic internal test wording. Not an approved commercial term.',
            'active' => false,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Modules\Sales\Domain\QuotationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotationPolicy> */
class QuotationPolicyFactory extends Factory
{
    protected $model = QuotationPolicy::class;

    /**
     * Isolated test fixture only. Never pass these synthetic limits to a demo seeder or live configuration.
     */
    public function definition(): array
    {
        return [
            'settings' => [
                'validity_default' => 30,
                'validity_min' => 1,
                'validity_max' => 365,
                'line_percent' => '0.00000000',
                'line_fixed' => '0.00000000',
                'quotation_percent' => '0.00000000',
                'quotation_fixed' => '0.00000000',
            ],
            'version' => 1,
            'updated_by' => null,
        ];
    }
}

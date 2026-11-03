<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Accounting\Domain\TaxRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** Explicit synthetic fixture only. Never composed into company/demo tax configuration. */
final class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    public function definition(): array
    {
        abort_unless(app()->environment('testing') && DB::connection()->getDriverName() === 'pgsql'
            && str_ends_with(DB::connection()->getDatabaseName(), '_test'), 403);

        return ['code' => 'DEMO-EG-TAX-'.fake()->unique()->numerify('######'), 'name' => 'قاعدة حساب اصطناعية غير صالحة للإنتاج',
            'transaction_type' => 'purchase', 'product_id' => null, 'rate' => '7.25000', 'starts_on' => '2000-01-01', 'ends_on' => '2000-12-31',
            'treatment' => 'percentage', 'tax_scale' => 5, 'purchase_recoverable' => true, 'legal_reference' => 'SYNTHETIC ONLY: deliberately fictitious arithmetic fixture, not Egyptian tax treatment.',
            'created_by' => User::factory()->disabledDemo()];
    }
}

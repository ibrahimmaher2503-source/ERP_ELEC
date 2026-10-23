<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Sales\Domain\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Quotation> */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        return [
            'draft_key' => (string) Str::uuid(),
            'number' => null,
            'customer_id' => Customer::factory(),
            'customer_snapshot' => [],
            'project_name' => null,
            'rfq_reference' => null,
            'quotation_date' => now('Africa/Cairo')->toDateString(),
            'valid_until' => null,
            'currency' => 'EGP',
            'status' => 'draft',
            'revision' => 1,
            'version' => 1,
            'created_by' => null,
            'updated_by' => null,
            'copied_from_id' => null,
            'discount_mode' => 'percent',
            'discount_value' => '0.00000000',
            'payment_terms' => null,
            'delivery_terms' => null,
            'notes' => 'Synthetic draft quotation fixture.',
            'exception_reason' => null,
            'calculation' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Quotation $quotation): void {
            $customer = Customer::query()->findOrFail($quotation->customer_id);
            $quotation->update(['customer_snapshot' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'entityType' => $customer->entity_type,
                'status' => $customer->status,
            ]]);
        });
    }
}

<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRfq;
use App\Modules\Purchasing\Domain\PurchaseRfqLine;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseRfqLineFactory extends Factory
{
    protected $model = PurchaseRfqLine::class;

    public function definition(): array
    {
        return ['rfq_id' => PurchaseRfq::factory(), 'quantity' => '1.00000000'];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PurchaseRfqLine $line) {
            $rfq = PurchaseRfq::findOrFail($line->rfq_id);
            $source = PurchaseRequisition::findOrFail($rfq->requisition_id)->lines()->firstOrFail();
            $line->requisition_line_id = $source->id;
            $line->quantity = $source->quantity;
        });
    }
}

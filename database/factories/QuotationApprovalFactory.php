<?php

namespace Database\Factories;

use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotationApproval> */
class QuotationApprovalFactory extends Factory
{
    protected $model = QuotationApproval::class;

    /**
     * Synthetic test fixture only. This request must never be used as seeded approval or authority history.
     */
    public function definition(): array
    {
        $snapshot = ['quotation' => ['status' => 'draft', 'version' => 1], 'lines' => []];

        return [
            'quotation_id' => Quotation::factory(),
            'requested_by' => 1,
            'target_version' => 1,
            'policy_version' => 1,
            'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'exceptions' => [['type' => 'price', 'line' => 1, 'requested' => '101.00000000', 'approved' => '100.00000000', 'reason' => 'Synthetic test fixture; not a commercial decision.']],
            'quotation_snapshot' => $snapshot,
            'status' => 'pending',
            'decided_by' => null,
            'decision_reason' => null,
            'decided_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (QuotationApproval $approval): void {
            $quotation = Quotation::query()->with('lines')->findOrFail($approval->quotation_id);
            $quotation->update(['status' => 'pending_approval', 'version' => $approval->target_version]);
            $snapshot = [
                'quotation' => $quotation->only(['customer_id', 'customer_snapshot', 'project_name', 'rfq_reference', 'quotation_date', 'valid_until', 'currency', 'revision', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'payment_term_id', 'delivery_term_id', 'notes', 'exception_reason']),
                'lines' => $quotation->lines->map(fn ($line) => $line->only(['position', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'suggested_price', 'requested_price', 'price_source', 'discount_mode', 'discount_value', 'override_reason']))->all(),
            ];
            $approval->forceFill([
                'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'quotation_snapshot' => $snapshot,
            ]);
        });
    }
}

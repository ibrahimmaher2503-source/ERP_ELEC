<?php

namespace Database\Factories;

use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesOrderReview> */
class SalesOrderReviewFactory extends Factory
{
    protected $model = SalesOrderReview::class;

    public function configure(): static
    {
        return $this->afterMaking(function (SalesOrderReview $review): void {
            if ($review->status !== 'pending') {
                return;
            }

            $order = SalesOrder::with('lines')->findOrFail($review->sales_order_id);
            $snapshot = app(SalesOrderActions::class)->snapshot($order);
            $targetVersion = $order->version + 1;

            // Runtime submission snapshots the draft before advancing its version and status.
            $order->update(['status' => 'pending_approval', 'version' => $targetVersion]);
            $review->forceFill([
                'target_version' => $targetVersion,
                'content_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'snapshot' => $snapshot,
            ]);
        });
    }

    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'target_version' => 2,
            'content_hash' => hash('sha256', '{}'),
            'snapshot' => [],
            'status' => 'pending',
            'requested_by' => null,
            'decided_by' => null,
            'reason' => null,
            'decided_at' => null,
        ];
    }
}

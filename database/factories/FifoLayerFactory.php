<?php

namespace Database\Factories;

use App\Application\PostPurchaseReceipt;
use App\Modules\Inventory\Domain\FifoLayer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

final class FifoLayerFactory extends Factory
{
    protected $model = FifoLayer::class;

    public function definition(): array
    {
        throw new \LogicException('FIFO layers must be produced by an authorized opening or actual purchase receipt. Use createThroughReceipt with synthetic approved source fixtures.');
    }

    public function createThroughReceipt(int $actor, int $receipt, int $version, int $line): FifoLayer
    {
        abort_unless(app()->environment('testing') && DB::connection()->getDriverName() === 'pgsql' && str_ends_with(DB::connection()->getDatabaseName(), '_test'), 403);
        app(PostPurchaseReceipt::class)->execute($actor, $receipt, $version);

        return FifoLayer::where('source_key', 'purchase-receipt:'.$receipt.':'.$line)->firstOrFail();
    }
}

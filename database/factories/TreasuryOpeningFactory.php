<?php

namespace Database\Factories;

use App\Application\ActivateFinancialPosting;
use App\Modules\Treasury\Domain\OpeningBalance;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

final class TreasuryOpeningFactory extends Factory
{
    protected $model = OpeningBalance::class;

    public function definition(): array
    {
        throw new \LogicException('Create actual synthetic cash/bank opening evidence through ActivateFinancialPosting in a disposable PostgreSQL database.');
    }

    public function createThroughActivation(int $actor, int $batch, int $version, string $kind, string $reference): OpeningBalance
    {
        abort_unless(app()->environment('testing') && DB::connection()->getDriverName() === 'pgsql'
            && str_ends_with(DB::connection()->getDatabaseName(), '_test'), 403);
        $existing = OpeningBalance::where('source_key', 'opening:'.$batch.':'.$kind.':'.$reference)->first();
        if ($existing) {
            return $existing;
        }
        app(ActivateFinancialPosting::class)->execute($actor, $batch, $version);

        return OpeningBalance::where('source_key', 'opening:'.$batch.':'.$kind.':'.$reference)->firstOrFail();
    }
}

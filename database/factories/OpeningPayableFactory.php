<?php

namespace Database\Factories;

use App\Application\ActivateFinancialPosting;
use App\Modules\ReceivablesPayables\Domain\OpeningPayable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** A posted opening is produced only by the real activation workflow, never fabricated here. */
final class OpeningPayableFactory extends Factory
{
    protected $model = OpeningPayable::class;

    public function definition(): array
    {
        throw new \LogicException('Use an approved synthetic opening batch through ActivateFinancialPosting in a disposable PostgreSQL database, then inspect its real OpeningPayable relationship.');
    }

    public function createThroughActivation(int $actor, int $batch, int $version, string $reference): OpeningPayable
    {
        abort_unless(app()->environment('testing') && DB::connection()->getDriverName() === 'pgsql'
            && str_ends_with(DB::connection()->getDatabaseName(), '_test'), 403);
        $existing = OpeningPayable::where('source_key', 'opening:'.$batch.':'.$reference)->first();
        if ($existing) {
            return $existing;
        }
        app(ActivateFinancialPosting::class)->execute($actor, $batch, $version);

        return OpeningPayable::where('source_key', 'opening:'.$batch.':'.$reference)->firstOrFail();
    }
}

<?php

namespace App\Application;

use App\Modules\Accounting\Application\Contracts\FinancialSetup;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\ReceivablesPayables\Application\Contracts\OpeningPayables;
use App\Modules\ReceivablesPayables\Application\Contracts\OpeningReceivables;
use App\Modules\Treasury\Application\Contracts\OpeningTreasury;
use Illuminate\Support\Facades\DB;

/** Root composition: no owner queries or writes outside its published contract. */
final class ActivateFinancialPosting
{
    public function execute(int $actor, int $batch, int $version): void
    {
        DB::transaction(function () use ($actor, $batch, $version) {
            $source = app(FinancialSetup::class)->prepareActivation($actor, $batch, $version);
            $lines = collect($source['lines']);
            $date = $source['setup']['go_live_date'];
            app(InventoryApi::class)->postOpening($actor, $batch, $date, $lines->where('kind', 'inventory')->values()->all());
            app(OpeningPayables::class)->postOpening($actor, $batch, $date, $lines->where('kind', 'ap')->values()->all());
            app(OpeningReceivables::class)->postOpening($actor, $batch, $date, $lines->where('kind', 'ar')->values()->all());
            app(OpeningTreasury::class)->postOpening($actor, $batch, $date, $lines->whereIn('kind', ['cash', 'bank'])->values()->all());
            app(FinancialSetup::class)->completeActivation($actor, $source);
        });
    }
}

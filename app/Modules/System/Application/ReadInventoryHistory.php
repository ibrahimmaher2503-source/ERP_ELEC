<?php

namespace App\Modules\System\Application;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Inventory\Domain\InventoryHistoryReader;
use Illuminate\Support\Facades\DB;

final class ReadInventoryHistory implements InventoryHistoryReader
{
    public function read(int $actor, int $document): array
    {
        app(InventoryApi::class)->document($actor, $document);

        return DB::table('audit_entries')->where('source_module', 'Inventory')->where('source_type', 'inventory_document')->where('source_id', $document)->orderByDesc('id')->limit(100)->get(['action', 'actor_id', 'occurred_at'])->map(fn ($r) => (array) $r)->all();
    }
}

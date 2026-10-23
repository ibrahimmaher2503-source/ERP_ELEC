<?php

namespace App\Modules\System\Application;

use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Domain\SalesOrderHistoryReader;
use Illuminate\Support\Facades\DB;

final class ReadSalesOrderHistory implements SalesOrderHistoryReader
{
    public function read(int $actorId, int $orderId): array
    {
        $r = app(SalesOrdersApi::class)->getOrder($actorId, $orderId);
        app(SalesOrderAccess::class)->authorize($actorId, 'history', $r['branchId']);

        return DB::table('audit_entries')->where('source_module', 'Sales')->where('source_type', 'sales_order')->where('source_id', $orderId)->orderByDesc('id')->limit(100)->get(['action', 'actor_id', 'occurred_at', 'safe_after'])->map(fn ($r) => (array) $r)->all();
    }
}

<?php

namespace App\Modules\System\Application;

use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Domain\QuotationHistoryReader;
use Illuminate\Support\Facades\DB;

final class ReadQuotationHistory implements QuotationHistoryReader
{
    public function read(int $actorId, int $quotationId): array
    {
        app(QuotationAccess::class)->authorize($actorId);
        app(QuotationAccess::class)->authorize($actorId, 'history');

        return DB::table('audit_entries')->where('source_module', 'Sales')->where('source_type', 'quotation')->where('source_id', $quotationId)->orderByDesc('id')->limit(100)->get(['action', 'actor_id', 'occurred_at', 'safe_after'])->map(fn ($r) => (array) $r)->all();
    }
}

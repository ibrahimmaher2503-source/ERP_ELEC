<?php

namespace App\Modules\System\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordAudit
{
    public function execute(?int $actorId, string $type, int $id, string $action, ?array $before, array $after, string $outcome = 'succeeded', string $sourceModule = 'Organization'): void
    {
        DB::table('audit_entries')->insert([
            'actor_id' => $actorId, 'source_module' => $sourceModule, 'source_type' => $type,
            'source_id' => $id, 'action' => $action, 'safe_before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'safe_after' => json_encode($after, JSON_THROW_ON_ERROR), 'correlation_id' => (string) Str::uuid(), 'occurred_at' => now(), 'outcome' => $outcome,
        ]);
    }
}

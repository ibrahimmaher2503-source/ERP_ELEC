<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE service_contract_work ADD COLUMN parts_snapshot jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(parts_snapshot)='array'), ADD COLUMN source_version integer NOT NULL DEFAULT 1 CHECK(source_version>0);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain source-linked entitlement usage; use forward migrations.');
    }
};

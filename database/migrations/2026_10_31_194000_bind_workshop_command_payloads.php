<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE workshop_intakes ADD COLUMN creation_payload_hash char(64);
ALTER TABLE workshop_jobs ADD COLUMN creation_payload_hash char(64);
ALTER TABLE workshop_jobs ADD COLUMN fault_classification varchar(200);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Workshop command evidence requires forward migration.');
    }
};

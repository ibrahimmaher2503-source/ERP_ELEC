<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE sales_tender_sources (
 quotation_id bigint PRIMARY KEY REFERENCES sales_quotations, tender_id bigint NOT NULL UNIQUE,
 result_id bigint NOT NULL, submission_id bigint NOT NULL, branch_id bigint NOT NULL REFERENCES branches,
 customer_id bigint NOT NULL REFERENCES customers, source_hash char(64) NOT NULL, source_snapshot jsonb NOT NULL,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER sales_tender_sources_immutable BEFORE UPDATE OR DELETE ON sales_tender_sources FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Winning bid traceability requires forward migration.');
    }
};

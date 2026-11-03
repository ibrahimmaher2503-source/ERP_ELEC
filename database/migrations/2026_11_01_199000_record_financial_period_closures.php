<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE accounting_period_closures (
 id bigserial PRIMARY KEY, period_id bigint NOT NULL REFERENCES accounting_periods(id),
 period_version integer NOT NULL CHECK(period_version>0), cutoff date NOT NULL,
 closed_by bigint NOT NULL REFERENCES users(id), evidence json NOT NULL,
 sha256 char(64) NOT NULL CHECK(sha256 ~ '^[0-9a-f]{64}$'), created_at timestamptz NOT NULL,
 UNIQUE(period_id,period_version), CHECK(json_typeof(evidence)='object'),
 CHECK(sha256=encode(sha256(convert_to(evidence::text,'UTF8')),'hex'))
);
CREATE TRIGGER accounting_period_closure_immutable BEFORE UPDATE OR DELETE ON accounting_period_closures
 FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Financial closure evidence requires forward migrations.');
    }
};

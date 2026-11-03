<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE sales_order_exception_decisions (
 review_id bigint PRIMARY KEY REFERENCES sales_order_reviews(id), policy_version integer NOT NULL CHECK(policy_version>0),
 content_hash char(64) NOT NULL, exceptions jsonb NOT NULL CHECK(jsonb_typeof(exceptions)='array' AND jsonb_array_length(exceptions)>0), decided_by bigint NOT NULL REFERENCES users(id), reason varchar(2000) NOT NULL CHECK(length(trim(reason))>=3),
 decided_at timestamptz NOT NULL
);
CREATE TRIGGER sales_order_exception_decision_immutable BEFORE UPDATE OR DELETE ON sales_order_exception_decisions FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Commercial approval history requires forward migrations.');
    }
};

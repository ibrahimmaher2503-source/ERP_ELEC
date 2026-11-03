<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE rp_party_credits (
 id bigserial PRIMARY KEY, credit_note_id bigint NOT NULL UNIQUE REFERENCES rp_credit_notes(id), branch_id bigint NOT NULL REFERENCES branches(id),
 party_type varchar(10) NOT NULL CHECK(party_type IN ('supplier','customer')), party_id bigint NOT NULL,
 original_amount numeric(20,2) NOT NULL CHECK(original_amount>0), allocated_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(allocated_amount>=0 AND allocated_amount<=original_amount),
 remaining_amount numeric(20,2) GENERATED ALWAYS AS (original_amount-allocated_amount) STORED, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX rp_party_credit_remaining ON rp_party_credits(party_type,party_id) WHERE remaining_amount>0;
CREATE TRIGGER rp_party_credit_no_delete BEFORE DELETE ON rp_party_credits FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Credit balance evidence requires forward migrations.');
    }
};

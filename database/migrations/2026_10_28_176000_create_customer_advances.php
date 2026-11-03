<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE rp_customer_advances (
 id bigserial PRIMARY KEY, movement_id bigint NOT NULL UNIQUE REFERENCES treasury_movements(id),
 branch_id bigint NOT NULL REFERENCES branches(id), customer_id bigint NOT NULL REFERENCES customers(id),
 original_amount numeric(20,2) NOT NULL CHECK(original_amount>0), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX rp_customer_advances_party ON rp_customer_advances(branch_id,customer_id);
CREATE TRIGGER rp_customer_advances_no_change BEFORE UPDATE OR DELETE ON rp_customer_advances FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Posted customer advance evidence requires a forward correction.');
    }
};

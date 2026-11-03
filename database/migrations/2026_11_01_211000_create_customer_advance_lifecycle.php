<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE rp_customer_advance_applications (
 id bigserial PRIMARY KEY, advance_id bigint NOT NULL REFERENCES rp_customer_advances(id), creation_key uuid NOT NULL UNIQUE,
 effective_date date NOT NULL, amount numeric(20,2) NOT NULL CHECK(amount>0), journal_id bigint NOT NULL UNIQUE REFERENCES accounting_journals(id),
 payload_hash char(64) NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE rp_customer_advance_application_lines (
 id bigserial PRIMARY KEY, application_id bigint NOT NULL REFERENCES rp_customer_advance_applications(id), open_item_id bigint NOT NULL REFERENCES rp_open_items(id),
 amount numeric(20,2) NOT NULL CHECK(amount>0), UNIQUE(application_id,open_item_id)
);
CREATE TABLE rp_customer_advance_refunds (
 id bigserial PRIMARY KEY, advance_id bigint NOT NULL REFERENCES rp_customer_advances(id), movement_id bigint NOT NULL UNIQUE REFERENCES treasury_movements(id),
 amount numeric(20,2) NOT NULL CHECK(amount>0), refund_date date NOT NULL, creation_key uuid NOT NULL UNIQUE,
 created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE treasury_movements DROP CONSTRAINT treasury_movements_kind_check;
ALTER TABLE treasury_movements ALTER COLUMN kind TYPE varchar(32);
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_movements_kind_check CHECK(kind IN ('supplier_payment','customer_collection','transfer_out','transfer_in','customer_advance_refund'));
ALTER TABLE treasury_movements ADD COLUMN customer_advance_id bigint REFERENCES rp_customer_advances(id);
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_advance_refund_identity CHECK((kind='customer_advance_refund')=(customer_advance_id IS NOT NULL));
SQL);
        foreach (['applications', 'application_lines', 'refunds'] as $suffix) {
            DB::unprepared("CREATE TRIGGER rp_customer_advance_{$suffix}_history BEFORE UPDATE OR DELETE ON rp_customer_advance_{$suffix} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
        foreach (['M12.advance_apply' => 'تطبيق مقدم عميل على ذمم فعلية', 'M13.advance_refund' => 'رد رصيد مقدم عميل من الخزينة'] as $code => $name) {
            DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $name, 'scope' => 'branch', 'module' => substr($code, 0, 3), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain customer advance application and refund history.');
    }
};

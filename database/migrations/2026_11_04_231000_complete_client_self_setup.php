<?php

use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DROP INDEX treasury_one_operational_kind;

ALTER TABLE accounting_opening_lines ADD COLUMN customer_id bigint;
ALTER TABLE accounting_opening_lines DROP CONSTRAINT accounting_opening_lines_kind_check;
ALTER TABLE accounting_opening_lines ADD CONSTRAINT accounting_opening_lines_kind_check CHECK(kind IN ('gl','ap','ar','cash','bank','inventory'));
ALTER TABLE rp_open_items DROP CONSTRAINT rp_open_items_source_type_check;
ALTER TABLE rp_open_items ADD CONSTRAINT rp_open_items_source_type_check CHECK(source_type IN ('supplier_invoice','customer_invoice','opening_payable','opening_receivable'));
CREATE TABLE receivable_opening_items (
 id bigserial PRIMARY KEY, source_key varchar(200) NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id),
 customer_id bigint NOT NULL, reference varchar(160) NOT NULL, opening_date date NOT NULL,
 amount numeric(20,2) NOT NULL CHECK(amount>=0), actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER receivable_opening_immutable BEFORE UPDATE OR DELETE ON receivable_opening_items FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION accounting_guard_receivable_opening() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE b accounting_opening_batches%ROWTYPE; setup accounting_activation%ROWTYPE;
BEGIN
 SELECT * INTO b FROM accounting_opening_batches WHERE id=split_part(NEW.source_key,':',2)::bigint;
 SELECT * INTO setup FROM accounting_activation WHERE id=1;
 IF b.id IS NULL OR b.status<>'approved' OR b.configuration_version<>setup.version OR setup.activated_at IS NOT NULL OR NEW.opening_date IS DISTINCT FROM setup.go_live_date THEN RAISE EXCEPTION 'Approved current opening required'; END IF;
 IF NOT EXISTS(SELECT 1 FROM accounting_opening_lines WHERE batch_id=b.id AND kind='ar' AND branch_id=NEW.branch_id AND reference=NEW.reference AND customer_id=NEW.customer_id AND amount=NEW.amount) THEN RAISE EXCEPTION 'Customer opening differs from approved detail'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER receivable_opening_source_guard BEFORE INSERT ON receivable_opening_items FOR EACH ROW EXECUTE FUNCTION accounting_guard_receivable_opening();
CREATE CONSTRAINT TRIGGER receivable_opening_commit AFTER INSERT ON receivable_opening_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION accounting_require_opening_commit();

ALTER TABLE customers ADD COLUMN credit_limit numeric(20,2) CHECK(credit_limit>=0), ADD COLUMN payment_terms varchar(500);
ALTER TABLE suppliers ADD COLUMN payment_terms varchar(500);
ALTER TABLE branches ADD COLUMN manager_name varchar(200);
ALTER TABLE inventory_warehouses ADD COLUMN storekeeper_name varchar(200);
SQL);
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        // Retain opening receivables, credit limits and setup fields; correct through new documents instead.
        throw new RuntimeException('Retain posted financial evidence: this migration cannot be rolled back.');
    }
};

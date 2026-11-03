<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE maintenance_work_orders ADD COLUMN estimated_duration_minutes integer CHECK(estimated_duration_minutes>0);
CREATE TABLE maintenance_cost_proposals (
 id bigserial PRIMARY KEY, kind varchar(12) NOT NULL CHECK(kind IN ('labor_rate','service')),
 branch_id bigint NOT NULL REFERENCES branches, work_order_id bigint REFERENCES maintenance_work_orders,
 payload jsonb NOT NULL, payload_hash char(64) NOT NULL, created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER maintenance_cost_proposals_immutable BEFORE UPDATE OR DELETE ON maintenance_cost_proposals FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
ALTER TABLE maintenance_labor_rates ADD COLUMN proposal_id bigint UNIQUE REFERENCES maintenance_cost_proposals;
ALTER TABLE maintenance_commercial_approvals ADD COLUMN proposal_id bigint UNIQUE REFERENCES maintenance_cost_proposals;
CREATE TABLE maintenance_expense_sources (
 id bigserial PRIMARY KEY, work_order_id bigint NOT NULL REFERENCES maintenance_work_orders, journal_id bigint NOT NULL UNIQUE REFERENCES accounting_journals,
 amount numeric(20,2) NOT NULL CHECK(amount>0), reference text NOT NULL, created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_labor_recognitions (
 labor_entry_id bigint PRIMARY KEY REFERENCES maintenance_labor_entries, journal_id bigint NOT NULL UNIQUE REFERENCES accounting_journals,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER maintenance_expense_source_immutable BEFORE UPDATE OR DELETE ON maintenance_expense_sources FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER maintenance_labor_recognition_immutable BEFORE UPDATE OR DELETE ON maintenance_labor_recognitions FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION accounting_guard_mapping() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM accounting_activation WHERE id=1 AND activated_at IS NOT NULL) THEN
  IF TG_OP='INSERT' AND NEW.purpose IN ('customer_advance','maintenance_labor','maintenance_labor_accrual') THEN RETURN NEW; END IF;
  RAISE EXCEPTION 'Activated account mappings frozen';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Maintenance costing history requires forward migrations.');
    }
};

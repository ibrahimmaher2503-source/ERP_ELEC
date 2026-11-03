<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE rp_customer_invoices ADD COLUMN workshop_job_id bigint UNIQUE REFERENCES workshop_jobs;
ALTER TABLE rp_customer_invoices ADD CONSTRAINT rp_invoice_service_source_exclusive CHECK(maintenance_work_order_id IS NULL OR workshop_job_id IS NULL);
ALTER TABLE rp_customer_invoice_lines ADD COLUMN workshop_approval_id bigint UNIQUE REFERENCES workshop_estimate_approvals;
ALTER TABLE rp_customer_invoice_lines DROP CONSTRAINT rp_customer_source_kind;
ALTER TABLE rp_customer_invoice_lines ADD CONSTRAINT rp_customer_source_kind CHECK
 ((maintenance_approval_id IS NOT NULL AND workshop_approval_id IS NULL AND sales_order_line_id IS NULL AND delivery_line_id IS NULL) OR
 (workshop_approval_id IS NOT NULL AND maintenance_approval_id IS NULL AND sales_order_line_id IS NULL AND delivery_line_id IS NULL) OR
 (maintenance_approval_id IS NULL AND workshop_approval_id IS NULL AND sales_order_line_id IS NOT NULL AND delivery_line_id IS NOT NULL));
CREATE OR REPLACE FUNCTION workshop_rate_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR OLD.approved_by IS NOT NULL OR NEW.approved_by IS NULL OR ROW(NEW.branch_id,NEW.technician_id,NEW.hourly_cost,NEW.effective_from,NEW.effective_to,NEW.reference,NEW.proposed_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.branch_id,OLD.technician_id,OLD.hourly_cost,OLD.effective_from,OLD.effective_to,OLD.reference,OLD.proposed_by,OLD.created_at) THEN RAISE EXCEPTION 'Approved workshop rate is immutable'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER workshop_rate_history BEFORE UPDATE OR DELETE ON workshop_rates FOR EACH ROW EXECUTE FUNCTION workshop_rate_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only workshop billing.');
    }
};

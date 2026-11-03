<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION accounting_guard_mapping() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF EXISTS(SELECT 1 FROM accounting_activation WHERE id=1 AND activated_at IS NOT NULL) THEN
  IF TG_OP='INSERT' AND NEW.purpose IN ('customer_advance','maintenance_labor','maintenance_labor_accrual','service_contract_deferred') THEN RETURN NEW; END IF;
  RAISE EXCEPTION 'Activated account mappings frozen';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TABLE service_contract_measurements (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL REFERENCES service_contract_revisions(id),
 work_id bigint NOT NULL REFERENCES service_contract_work(id), boq_code varchar(50) NOT NULL, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 rate numeric(20,2) NOT NULL CHECK(rate>0), product_id bigint NOT NULL REFERENCES products(id), description varchar(500) NOT NULL,
 reference text NOT NULL, creation_key uuid NOT NULL UNIQUE, source_snapshot jsonb NOT NULL,
 status varchar(12) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','rejected')), version integer NOT NULL DEFAULT 1,
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(work_id,boq_code), CHECK(approved_by IS NULL OR approved_by<>created_by)
);
CREATE TABLE service_contract_claims (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL REFERENCES service_contract_revisions(id),
 period_from date NOT NULL, period_to date NOT NULL CHECK(period_to>=period_from), invoice_date date NOT NULL, due_date date NOT NULL CHECK(due_date>=invoice_date),
 tax_mode varchar(12) NOT NULL CHECK(tax_mode IN ('inclusive','exclusive')), reference text NOT NULL, creation_key uuid NOT NULL UNIQUE,
 status varchar(12) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','approved','rejected')), version integer NOT NULL DEFAULT 1,
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), CHECK(approved_by IS NULL OR approved_by<>created_by)
);
CREATE TABLE service_contract_claim_lines (
 id bigserial PRIMARY KEY, claim_id bigint NOT NULL REFERENCES service_contract_claims(id), measurement_id bigint NOT NULL REFERENCES service_contract_measurements(id),
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), amount numeric(20,2) NOT NULL CHECK(amount>0), UNIQUE(claim_id,measurement_id)
);
CREATE TABLE service_contract_billing_sources (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL REFERENCES service_contract_revisions(id),
 kind varchar(16) NOT NULL CHECK(kind IN ('installment','claim')), source_code varchar(50), claim_id bigint UNIQUE REFERENCES service_contract_claims(id),
 amount numeric(20,2) NOT NULL CHECK(amount>0), product_id bigint NOT NULL REFERENCES products(id), description varchar(500) NOT NULL,
 invoice_date date NOT NULL, due_date date NOT NULL CHECK(due_date>=invoice_date), tax_mode varchar(12) NOT NULL CHECK(tax_mode IN ('inclusive','exclusive')),
 net_amount numeric(20,2), tax_amount numeric(20,2), total_amount numeric(20,2), tax_snapshot jsonb,
 revenue_purpose varchar(40) NOT NULL CHECK(revenue_purpose IN ('sales','service_contract_deferred')),
 reference text NOT NULL, creation_key uuid NOT NULL UNIQUE, status varchar(12) NOT NULL DEFAULT 'proposed' CHECK(status IN ('proposed','approved','invoiced','rejected')),
 version integer NOT NULL DEFAULT 1, created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), CHECK(approved_by IS NULL OR approved_by<>created_by),
 CHECK((kind='installment' AND source_code IS NOT NULL AND claim_id IS NULL AND revenue_purpose='service_contract_deferred') OR (kind='claim' AND source_code IS NULL AND claim_id IS NOT NULL AND revenue_purpose='sales'))
);
CREATE TABLE service_contract_billing_allocations (
 source_id bigint PRIMARY KEY REFERENCES service_contract_billing_sources(id), invoice_id bigint NOT NULL UNIQUE REFERENCES rp_customer_invoices(id),
 created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE service_contract_recognitions (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), amount numeric(20,2) NOT NULL CHECK(amount>0),
 recognition_date date NOT NULL, work_ids jsonb NOT NULL CHECK(jsonb_typeof(work_ids)='array'), reference text NOT NULL, creation_key uuid NOT NULL UNIQUE,
 status varchar(12) NOT NULL DEFAULT 'proposed' CHECK(status IN ('proposed','posted','rejected')), version integer NOT NULL DEFAULT 1,
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 journal_id bigint UNIQUE REFERENCES accounting_journals(id), created_at timestamptz NOT NULL DEFAULT now(), CHECK(approved_by IS NULL OR approved_by<>created_by)
);
CREATE TABLE service_contract_recognition_reversals (
 id bigserial PRIMARY KEY, recognition_id bigint NOT NULL UNIQUE REFERENCES service_contract_recognitions(id), contract_id bigint NOT NULL REFERENCES service_contracts(id),
 amount numeric(20,2) NOT NULL CHECK(amount>0), reversal_date date NOT NULL, reason text NOT NULL, creation_key uuid NOT NULL UNIQUE,
 status varchar(12) NOT NULL DEFAULT 'proposed' CHECK(status IN ('proposed','posted','rejected')), version integer NOT NULL DEFAULT 1,
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 journal_id bigint UNIQUE REFERENCES accounting_journals(id), created_at timestamptz NOT NULL DEFAULT now(), CHECK(approved_by IS NULL OR approved_by<>created_by)
);
CREATE TABLE service_contract_billing_corrections (
 id bigserial PRIMARY KEY, source_id bigint NOT NULL REFERENCES service_contract_billing_sources(id), amount numeric(20,2) NOT NULL CHECK(amount>0),
 note_date date NOT NULL, reason text NOT NULL, creation_key uuid NOT NULL UNIQUE, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE rp_credit_notes ADD COLUMN contract_correction_id bigint UNIQUE REFERENCES service_contract_billing_corrections(id);
ALTER TABLE rp_customer_invoices ADD COLUMN service_contract_source_id bigint UNIQUE REFERENCES service_contract_billing_sources(id);
ALTER TABLE rp_customer_invoices ADD COLUMN service_contract_id bigint REFERENCES service_contracts(id);
ALTER TABLE rp_customer_invoices ADD CONSTRAINT rp_contract_source_identity CHECK((service_contract_id IS NULL)=(service_contract_source_id IS NULL));
ALTER TABLE rp_customer_invoices ADD CONSTRAINT rp_customer_header_source CHECK(num_nonnulls(maintenance_work_order_id,workshop_job_id,service_contract_source_id)<=1);
ALTER TABLE rp_customer_invoice_lines ADD COLUMN service_contract_source_id bigint UNIQUE REFERENCES service_contract_billing_sources(id);
ALTER TABLE rp_customer_invoice_lines DROP CONSTRAINT rp_customer_source_kind;
ALTER TABLE rp_customer_invoice_lines ADD CONSTRAINT rp_customer_source_kind CHECK
 ((sales_order_line_id IS NULL AND delivery_line_id IS NULL AND num_nonnulls(maintenance_approval_id,workshop_approval_id,service_contract_source_id)=1) OR
 (sales_order_line_id IS NOT NULL AND delivery_line_id IS NOT NULL AND num_nonnulls(maintenance_approval_id,workshop_approval_id,service_contract_source_id)=0));
CREATE OR REPLACE FUNCTION service_contract_guard_billing() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Contract billing history must be retained'; END IF;
 IF OLD.status IN ('posted','invoiced','rejected') THEN RAISE EXCEPTION 'Resolved contract billing is immutable'; END IF;
 IF (to_jsonb(NEW)-ARRAY['status','version','approved_by','approved_at','journal_id','net_amount','tax_amount','total_amount','tax_snapshot']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','version','approved_by','approved_at','journal_id','net_amount','tax_amount','total_amount','tax_snapshot']) THEN RAISE EXCEPTION 'Billing source identity and amounts are immutable'; END IF;
 IF OLD.approved_by IS NOT NULL AND NEW.approved_by IS DISTINCT FROM OLD.approved_by THEN RAISE EXCEPTION 'Contract billing approval is immutable'; END IF;
 IF OLD.status='approved' AND (to_jsonb(NEW)-ARRAY['status','version']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','version']) THEN RAISE EXCEPTION 'Approved commercial snapshot is immutable'; END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['measurements', 'claims', 'billing_sources', 'recognitions', 'recognition_reversals'] as $table) {
            DB::unprepared("CREATE TRIGGER service_contract_{$table}_history BEFORE UPDATE OR DELETE ON service_contract_{$table} FOR EACH ROW EXECUTE FUNCTION service_contract_guard_billing()");
        }
        foreach (['claim_lines', 'billing_allocations', 'billing_corrections'] as $table) {
            DB::unprepared("CREATE TRIGGER service_contract_{$table}_history BEFORE UPDATE OR DELETE ON service_contract_{$table} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Contract invoice and earned-revenue history are forward-only.');
    }
};

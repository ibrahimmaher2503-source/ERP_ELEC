<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE workshops (
 id bigserial PRIMARY KEY, code varchar(40) NOT NULL UNIQUE, name varchar(200) NOT NULL,
 specialty text NOT NULL, branch_id bigint NOT NULL REFERENCES branches, supervisor_id bigint NOT NULL REFERENCES users,
 warehouse_id bigint REFERENCES inventory_warehouses, capacity integer NOT NULL CHECK(capacity>0),
 schedule jsonb NOT NULL, active boolean NOT NULL DEFAULT true, version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_members (
 id bigserial PRIMARY KEY, workshop_id bigint NOT NULL REFERENCES workshops, technician_id bigint NOT NULL REFERENCES users,
 active boolean NOT NULL DEFAULT true, created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(workshop_id,technician_id)
);
CREATE TABLE workshop_intakes (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, number varchar(50) NOT NULL UNIQUE,
 branch_id bigint NOT NULL REFERENCES branches, customer_id bigint NOT NULL REFERENCES customers, customer_name varchar(200) NOT NULL,
 site_id bigint REFERENCES customer_addresses, contact_id bigint REFERENCES customer_contacts, product_id bigint REFERENCES products,
 project_id bigint REFERENCES projects, maintenance_work_order_id bigint REFERENCES maintenance_work_orders,
 maintenance_visit_id bigint UNIQUE REFERENCES maintenance_visits,
 equipment_description text NOT NULL, model varchar(200), serial_number varchar(200), accessories text NOT NULL,
 reported_fault text NOT NULL, arrival_condition text NOT NULL, customer_owned boolean NOT NULL,
 received_at timestamptz, received_by bigint REFERENCES users, acknowledgment text,
 location varchar(300) NOT NULL, workshop_id bigint REFERENCES workshops,
 status varchar(40) NOT NULL DEFAULT 'draft' CHECK(status IN('draft','received','assigned','inspection','waiting_approval','repair','waiting_parts','on_hold','testing','ready','not_repairable','returned_unrepaired','delivered')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_by bigint NOT NULL REFERENCES users,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX workshop_serial_in_custody ON workshop_intakes(customer_id,serial_number) WHERE serial_number IS NOT NULL AND status NOT IN('draft','delivered','returned_unrepaired');
CREATE TABLE workshop_custody_events (
 id bigserial PRIMARY KEY, intake_id bigint NOT NULL REFERENCES workshop_intakes, event_key uuid NOT NULL UNIQUE,
 source_location varchar(300) NOT NULL, destination_location varchar(300) NOT NULL,
 responsible_id bigint NOT NULL REFERENCES users, condition text NOT NULL, reason text NOT NULL,
 status varchar(40) NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_jobs (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, number varchar(50) NOT NULL UNIQUE,
 intake_id bigint NOT NULL REFERENCES workshop_intakes, branch_id bigint NOT NULL REFERENCES branches,
 customer_id bigint NOT NULL REFERENCES customers, customer_name varchar(200) NOT NULL, project_id bigint REFERENCES projects,
 workshop_id bigint NOT NULL REFERENCES workshops, technician_id bigint REFERENCES users,
 priority varchar(20) NOT NULL CHECK(priority IN('low','normal','high','urgent')),
 diagnosis text, planned_work text, required_tests jsonb NOT NULL DEFAULT '[]', estimated_minutes integer CHECK(estimated_minutes>0), estimated_completion timestamptz,
 billing_mode varchar(20) NOT NULL CHECK(billing_mode IN('billable','partial','warranty','nonbillable','contract')),
 status varchar(30) NOT NULL DEFAULT 'inspection' CHECK(status IN('inspection','waiting_approval','approved','rejected','repair','waiting_parts','on_hold','testing','verified','delivered','returned_unrepaired')),
 hold_from varchar(30), version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX workshop_one_active_job ON workshop_jobs(intake_id) WHERE status NOT IN('delivered','returned_unrepaired');
CREATE TABLE workshop_estimates (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, revision integer NOT NULL CHECK(revision>0),
 parts_amount numeric(18,2) NOT NULL CHECK(parts_amount>=0), labor_amount numeric(18,2) NOT NULL CHECK(labor_amount>=0),
 other_amount numeric(18,2) NOT NULL CHECK(other_amount>=0), billable_amount numeric(18,2) NOT NULL CHECK(billable_amount>=0),
 product_id bigint NOT NULL REFERENCES products, tax_amount numeric(18,2) NOT NULL CHECK(tax_amount>=0), tax_snapshot jsonb NOT NULL,
 pricing_reference text NOT NULL, proposed_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(job_id,revision)
);
CREATE TABLE workshop_estimate_approvals (
 id bigserial PRIMARY KEY, estimate_id bigint NOT NULL UNIQUE REFERENCES workshop_estimates, approved_by bigint NOT NULL REFERENCES users,
 customer_accepted boolean NOT NULL, evidence text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_work_records (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, creation_key uuid NOT NULL UNIQUE,
 technician_id bigint NOT NULL REFERENCES users, started_at timestamptz NOT NULL, ended_at timestamptz NOT NULL CHECK(ended_at>started_at),
 work_performed text NOT NULL, observations text NOT NULL, created_by bigint NOT NULL REFERENCES users,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_rates (
 id bigserial PRIMARY KEY, branch_id bigint NOT NULL REFERENCES branches, technician_id bigint NOT NULL REFERENCES users,
 hourly_cost numeric(18,2) NOT NULL CHECK(hourly_cost>0), effective_from date NOT NULL, effective_to date NOT NULL CHECK(effective_to>=effective_from),
 reference text NOT NULL, proposed_by bigint NOT NULL REFERENCES users, approved_by bigint REFERENCES users CHECK(approved_by<>proposed_by), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_labor_entries (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, work_record_id bigint NOT NULL UNIQUE REFERENCES workshop_work_records,
 technician_id bigint NOT NULL REFERENCES users, started_at timestamptz NOT NULL, ended_at timestamptz NOT NULL CHECK(ended_at>started_at),
 duration_minutes integer NOT NULL CHECK(duration_minutes>0), rate_id bigint REFERENCES workshop_rates,
 cost numeric(18,2) CHECK(cost>=0), work_type varchar(200) NOT NULL, notes text,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), CHECK((rate_id IS NULL)=(cost IS NULL))
);
CREATE TABLE workshop_labor_recognitions (
 labor_entry_id bigint PRIMARY KEY REFERENCES workshop_labor_entries, journal_id bigint NOT NULL UNIQUE REFERENCES accounting_journals,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_part_requirements (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, product_id bigint NOT NULL REFERENCES products,
 warehouse_id bigint NOT NULL REFERENCES inventory_warehouses, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_part_reservations (
 id bigserial PRIMARY KEY, requirement_id bigint NOT NULL REFERENCES workshop_part_requirements, reservation_id bigint NOT NULL UNIQUE REFERENCES inventory_reservations
);
CREATE TABLE workshop_part_issues (
 id bigserial PRIMARY KEY, requirement_id bigint NOT NULL REFERENCES workshop_part_requirements, reservation_id bigint NOT NULL REFERENCES inventory_reservations,
 issue_key uuid NOT NULL UNIQUE, quantity numeric(24,8) NOT NULL CHECK(quantity>0), movement_id bigint UNIQUE REFERENCES inventory_movements,
 journal_id bigint REFERENCES accounting_journals, amount numeric(18,2) CHECK(amount>=0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_part_returns (
 id bigserial PRIMARY KEY, issue_id bigint NOT NULL REFERENCES workshop_part_issues, return_key uuid NOT NULL UNIQUE,
 movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements, quantity numeric(24,8) NOT NULL CHECK(quantity>0), amount numeric(18,2) NOT NULL CHECK(amount>=0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_part_usage (
 id bigserial PRIMARY KEY, work_record_id bigint NOT NULL REFERENCES workshop_work_records, requirement_id bigint NOT NULL REFERENCES workshop_part_requirements,
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_customer_parts (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, description text NOT NULL, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 condition text NOT NULL, disposition varchar(20) NOT NULL CHECK(disposition IN('received','used','returned')),
 reference text NOT NULL, created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_tests (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, name varchar(200) NOT NULL, required boolean NOT NULL,
 outcome varchar(20) NOT NULL CHECK(outcome IN('passed','failed','waived')), results text NOT NULL,
 performed_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_verifications (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL UNIQUE REFERENCES workshop_jobs, verified_by bigint NOT NULL REFERENCES users,
 final_condition text NOT NULL, remaining_faults text NOT NULL, document_id uuid NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_deliveries (
 id bigserial PRIMARY KEY, intake_id bigint NOT NULL UNIQUE REFERENCES workshop_intakes, job_id bigint UNIQUE REFERENCES workshop_jobs,
 creation_key uuid NOT NULL UNIQUE, recipient varchar(200) NOT NULL, accessories text NOT NULL, final_condition text NOT NULL,
 acknowledgment text NOT NULL, delivered_at timestamptz NOT NULL, delivered_by bigint NOT NULL REFERENCES users,
 unrepaired boolean NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_expense_sources (
 id bigserial PRIMARY KEY, job_id bigint NOT NULL REFERENCES workshop_jobs, journal_id bigint NOT NULL UNIQUE REFERENCES accounting_journals,
 amount numeric(18,2) NOT NULL CHECK(amount>0), reference text NOT NULL, created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_documents (
 id bigserial PRIMARY KEY, intake_id bigint NOT NULL REFERENCES workshop_intakes, creation_key uuid NOT NULL UNIQUE,
 path text NOT NULL, original_name varchar(200) NOT NULL, mime varchar(100) NOT NULL, sha256 char(64) NOT NULL,
 uploaded_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_activities (
 id bigserial PRIMARY KEY, intake_id bigint REFERENCES workshop_intakes, job_id bigint REFERENCES workshop_jobs,
 actor_id bigint NOT NULL REFERENCES users, action varchar(100) NOT NULL, details jsonb NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE OR REPLACE FUNCTION workshop_guard_intake() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Workshop history cannot be deleted'; END IF;
 IF OLD.status IN('delivered','returned_unrepaired') OR ROW(NEW.creation_key,NEW.number,NEW.branch_id,NEW.customer_id,NEW.customer_owned,NEW.maintenance_work_order_id,NEW.maintenance_visit_id,NEW.project_id,NEW.created_by) IS DISTINCT FROM ROW(OLD.creation_key,OLD.number,OLD.branch_id,OLD.customer_id,OLD.customer_owned,OLD.maintenance_work_order_id,OLD.maintenance_visit_id,OLD.project_id,OLD.created_by) THEN RAISE EXCEPTION 'Workshop custody identity is immutable'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER workshop_intake_guard BEFORE UPDATE OR DELETE ON workshop_intakes FOR EACH ROW EXECUTE FUNCTION workshop_guard_intake();
CREATE OR REPLACE FUNCTION workshop_guard_job() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Workshop jobs cannot be deleted'; END IF;
 IF OLD.status IN('delivered','returned_unrepaired') OR ROW(NEW.creation_key,NEW.number,NEW.intake_id,NEW.branch_id,NEW.customer_id,NEW.project_id,NEW.created_by) IS DISTINCT FROM ROW(OLD.creation_key,OLD.number,OLD.intake_id,OLD.branch_id,OLD.customer_id,OLD.project_id,OLD.created_by) THEN RAISE EXCEPTION 'Workshop job identity is immutable'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER workshop_job_guard BEFORE UPDATE OR DELETE ON workshop_jobs FOR EACH ROW EXECUTE FUNCTION workshop_guard_job();
SQL);
        foreach (['custody_events', 'estimates', 'estimate_approvals', 'work_records', 'labor_entries', 'labor_recognitions', 'part_requirements', 'part_reservations', 'part_returns', 'part_usage', 'customer_parts', 'tests', 'verifications', 'deliveries', 'expense_sources', 'documents', 'activities'] as $table) {
            DB::unprepared("CREATE TRIGGER workshop_{$table}_immutable BEFORE UPDATE OR DELETE ON workshop_{$table} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Workshop custody requires forward migrations.');
    }
};

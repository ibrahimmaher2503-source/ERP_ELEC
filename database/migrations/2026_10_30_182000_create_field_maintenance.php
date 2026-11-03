<?php

use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE maintenance_requests (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, number varchar(80) NOT NULL UNIQUE,
 branch_id bigint NOT NULL REFERENCES branches, customer_id bigint NOT NULL REFERENCES customers, customer_name varchar(255) NOT NULL,
 site_id bigint REFERENCES customer_addresses, contact_id bigint REFERENCES customer_contacts, product_id bigint REFERENCES products,
 project_id bigint REFERENCES projects, responsible_id bigint NOT NULL REFERENCES users,
 description text NOT NULL, priority varchar(12) NOT NULL CHECK(priority IN ('low','normal','high','urgent')),
 reported_at timestamptz NOT NULL, preferred_date date, status varchar(25) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','accepted','work_order_created','rejected','cancelled')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_by bigint NOT NULL REFERENCES users,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_work_orders (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, number varchar(80) NOT NULL UNIQUE, request_id bigint UNIQUE REFERENCES maintenance_requests,
 branch_id bigint NOT NULL REFERENCES branches, customer_id bigint NOT NULL REFERENCES customers, customer_name varchar(255) NOT NULL,
 site_id bigint REFERENCES customer_addresses, contact_id bigint REFERENCES customer_contacts, product_id bigint REFERENCES products, project_id bigint REFERENCES projects,
 responsible_id bigint NOT NULL REFERENCES users, description text NOT NULL, instructions text NOT NULL DEFAULT '', priority varchar(12) NOT NULL CHECK(priority IN ('low','normal','high','urgent')),
 status varchar(25) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','scheduled','assigned','in_progress','work_completed','verified','closed','on_hold','cancelled')),
 hold_from varchar(25), version integer NOT NULL DEFAULT 1 CHECK(version>0), billable boolean NOT NULL DEFAULT false, acknowledgment_required boolean NOT NULL DEFAULT false,
 estimated_cost numeric(20,2) CHECK(estimated_cost>=0), completion_notes text, created_by bigint NOT NULL REFERENCES users, closed_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX maintenance_work_scope ON maintenance_work_orders(branch_id,status);
CREATE TABLE maintenance_assignments (
 id bigserial PRIMARY KEY, work_order_id bigint NOT NULL REFERENCES maintenance_work_orders, technician_id bigint NOT NULL REFERENCES users,
 starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL CHECK(ends_at>starts_at), active boolean NOT NULL DEFAULT true,
 override_reason text, assigned_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), ended_at timestamptz
);
CREATE INDEX maintenance_schedule ON maintenance_assignments(technician_id,starts_at,ends_at) WHERE active;
CREATE TABLE maintenance_checklist_templates (
 id bigserial PRIMARY KEY, code varchar(60) NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches, title varchar(200) NOT NULL,
 items jsonb NOT NULL CHECK(jsonb_typeof(items)='array'), active boolean NOT NULL DEFAULT true, version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_visits (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, work_order_id bigint NOT NULL REFERENCES maintenance_work_orders, assignment_id bigint NOT NULL REFERENCES maintenance_assignments,
 technician_id bigint NOT NULL REFERENCES users, scheduled_at timestamptz NOT NULL, mandatory boolean NOT NULL DEFAULT true,
 template_snapshot jsonb NOT NULL DEFAULT '[]', status varchar(15) NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','recorded')),
 arrival_at timestamptz, departure_at timestamptz, work_performed text, diagnosis text, observations text, follow_up text,
 acknowledgment text, outcome varchar(25) CHECK(outcome IN ('completed','follow_up_required','waiting_for_parts','unable_to_access','cancelled')),
 cancellation_reason text, recorded_by bigint REFERENCES users, recorded_at timestamptz, version integer NOT NULL DEFAULT 1 CHECK(version>0),
 CHECK((status='recorded')=(recorded_at IS NOT NULL)), CHECK(departure_at IS NULL OR departure_at>arrival_at)
);
CREATE TABLE maintenance_checklist_results (
 id bigserial PRIMARY KEY, visit_id bigint NOT NULL REFERENCES maintenance_visits, item_key varchar(80) NOT NULL, label varchar(255) NOT NULL,
 required boolean NOT NULL, result varchar(20) NOT NULL CHECK(result IN ('complete','not_applicable','failed')), notes text,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(visit_id,item_key)
);
CREATE TABLE maintenance_part_requirements (
 id bigserial PRIMARY KEY, work_order_id bigint NOT NULL REFERENCES maintenance_work_orders, product_id bigint NOT NULL REFERENCES products,
 warehouse_id bigint NOT NULL REFERENCES inventory_warehouses, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_part_reservations (
 id bigserial PRIMARY KEY, requirement_id bigint NOT NULL REFERENCES maintenance_part_requirements, reservation_id bigint NOT NULL UNIQUE REFERENCES inventory_reservations
);
CREATE TABLE maintenance_part_issues (
 id bigserial PRIMARY KEY, requirement_id bigint NOT NULL REFERENCES maintenance_part_requirements, reservation_id bigint NOT NULL REFERENCES inventory_reservations,
 issue_key uuid NOT NULL UNIQUE, quantity numeric(24,8) NOT NULL CHECK(quantity>0), created_by bigint NOT NULL REFERENCES users,
 movement_id bigint UNIQUE REFERENCES inventory_movements, journal_id bigint REFERENCES accounting_journals, amount numeric(20,2) CHECK(amount>=0), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_part_returns (
 id bigserial PRIMARY KEY, issue_id bigint NOT NULL REFERENCES maintenance_part_issues, return_key uuid NOT NULL UNIQUE,
 movement_id bigint NOT NULL UNIQUE REFERENCES inventory_movements, quantity numeric(24,8) NOT NULL CHECK(quantity>0), amount numeric(20,2) NOT NULL CHECK(amount>=0),
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_part_usage (
 id bigserial PRIMARY KEY, visit_id bigint NOT NULL REFERENCES maintenance_visits, requirement_id bigint NOT NULL REFERENCES maintenance_part_requirements,
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(visit_id,requirement_id)
);
CREATE TABLE maintenance_labor_rates (
 id bigserial PRIMARY KEY, branch_id bigint NOT NULL REFERENCES branches, technician_id bigint NOT NULL REFERENCES users,
 hourly_cost numeric(20,2) NOT NULL CHECK(hourly_cost>0), effective_from date NOT NULL, effective_to date NOT NULL CHECK(effective_to>=effective_from),
 reference varchar(255) NOT NULL, created_by bigint NOT NULL REFERENCES users, approved_by bigint NOT NULL REFERENCES users CHECK(approved_by<>created_by), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_labor_entries (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, work_order_id bigint NOT NULL REFERENCES maintenance_work_orders, visit_id bigint NOT NULL REFERENCES maintenance_visits,
 technician_id bigint NOT NULL REFERENCES users, starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL CHECK(ends_at>starts_at), duration_minutes integer NOT NULL CHECK(duration_minutes>0),
 work_type varchar(100) NOT NULL, rate_id bigint REFERENCES maintenance_labor_rates, cost numeric(20,2) CHECK(cost>=0), notes text,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_completions (
 id bigserial PRIMARY KEY, work_order_id bigint NOT NULL REFERENCES maintenance_work_orders, revision integer NOT NULL CHECK(revision>0),
 evidence text NOT NULL, verified_by bigint NOT NULL REFERENCES users, verified_at timestamptz NOT NULL DEFAULT now(),
 official_document_id uuid REFERENCES system_official_documents, UNIQUE(work_order_id,revision)
);
CREATE TABLE maintenance_activities (
 id bigserial PRIMARY KEY, entity_type varchar(30) NOT NULL, entity_id bigint NOT NULL, actor_id bigint NOT NULL REFERENCES users,
 action varchar(80) NOT NULL, before_snapshot jsonb, after_snapshot jsonb NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_commercial_approvals (
 id bigserial PRIMARY KEY, work_order_id bigint NOT NULL UNIQUE REFERENCES maintenance_work_orders, product_id bigint NOT NULL REFERENCES products,
 net_amount numeric(20,2) NOT NULL CHECK(net_amount>0), tax_amount numeric(20,2) NOT NULL CHECK(tax_amount>=0), tax_snapshot jsonb NOT NULL,
 price_reference text NOT NULL, proposed_by bigint NOT NULL REFERENCES users, approved_by bigint NOT NULL REFERENCES users CHECK(approved_by<>proposed_by), approved_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE maintenance_documents (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, request_id bigint REFERENCES maintenance_requests, work_order_id bigint REFERENCES maintenance_work_orders,
 visit_id bigint REFERENCES maintenance_visits, path varchar(255) NOT NULL, original_name varchar(200) NOT NULL, mime varchar(100) NOT NULL,
 sha256 char(64) NOT NULL, uploaded_by bigint NOT NULL REFERENCES users, uploaded_at timestamptz NOT NULL DEFAULT now(),
 CHECK((request_id IS NOT NULL)::integer+(work_order_id IS NOT NULL)::integer=1)
);
CREATE OR REPLACE FUNCTION maintenance_guard_visit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='recorded' THEN RAISE EXCEPTION 'Maintenance execution evidence is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER maintenance_visit_guard BEFORE UPDATE OR DELETE ON maintenance_visits FOR EACH ROW EXECUTE FUNCTION maintenance_guard_visit();
SQL);
        foreach (['maintenance_activities', 'maintenance_checklist_results', 'maintenance_part_usage', 'maintenance_labor_entries', 'maintenance_labor_rates', 'maintenance_completions', 'maintenance_part_returns', 'maintenance_documents', 'maintenance_commercial_approvals'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M08.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => 'M08 · الصيانة الميدانية', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Maintenance evidence requires forward migrations.');
    }
};

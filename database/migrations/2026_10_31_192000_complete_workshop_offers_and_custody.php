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
ALTER TABLE workshop_intakes ALTER COLUMN customer_id DROP NOT NULL;
ALTER TABLE workshop_jobs ALTER COLUMN customer_id DROP NOT NULL;
ALTER TABLE workshop_intakes ADD COLUMN company_serial_id bigint REFERENCES inventory_serials;
ALTER TABLE workshop_intakes ADD COLUMN equipment_identity varchar(300);
ALTER TABLE workshop_intakes ADD COLUMN isolation_id bigint UNIQUE REFERENCES inventory_isolations;
ALTER TABLE workshop_intakes ADD CONSTRAINT workshop_equipment_owner CHECK
 ((customer_owned AND customer_id IS NOT NULL AND company_serial_id IS NULL) OR
  (NOT customer_owned AND customer_id IS NULL AND company_serial_id IS NOT NULL AND product_id IS NOT NULL));
CREATE UNIQUE INDEX workshop_company_equipment_in_custody ON workshop_intakes(company_serial_id)
 WHERE company_serial_id IS NOT NULL AND status NOT IN('draft','delivered','returned_unrepaired');
CREATE UNIQUE INDEX workshop_equipment_identity_in_custody ON workshop_intakes(equipment_identity)
 WHERE equipment_identity IS NOT NULL AND status NOT IN('draft','delivered','returned_unrepaired');
CREATE TABLE workshop_quote_approvals (
 estimate_id bigint PRIMARY KEY REFERENCES workshop_estimates, approved_by bigint NOT NULL REFERENCES users,
 reference text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE workshop_estimates ADD COLUMN commercial_snapshot jsonb NOT NULL DEFAULT '{}';
ALTER TABLE workshop_estimates ADD COLUMN valid_until date;
ALTER TABLE workshop_estimates ADD COLUMN warranty_days integer NOT NULL DEFAULT 0 CHECK(warranty_days>=0 AND warranty_days<=3650);
ALTER TABLE workshop_estimates ADD COLUMN warranty_conditions text NOT NULL DEFAULT '';
CREATE TABLE workshop_quotes (
 id bigserial PRIMARY KEY, estimate_id bigint NOT NULL UNIQUE REFERENCES workshop_estimates,
 job_id bigint NOT NULL REFERENCES workshop_jobs, document_id uuid NOT NULL UNIQUE REFERENCES system_official_documents,
 issued_by bigint NOT NULL REFERENCES users, issued_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_nonbillable_authorizations (
 job_id bigint PRIMARY KEY REFERENCES workshop_jobs, kind varchar(20) NOT NULL CHECK(kind IN('warranty','internal','goodwill')),
 source_job_id bigint REFERENCES workshop_jobs, reference text NOT NULL, approved_by bigint NOT NULL REFERENCES users,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE workshop_transfers (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, job_id bigint NOT NULL REFERENCES workshop_jobs,
 source_workshop_id bigint NOT NULL REFERENCES workshops, destination_workshop_id bigint NOT NULL REFERENCES workshops,
 reason text NOT NULL, condition text NOT NULL, dispatched_by bigint NOT NULL REFERENCES users,
 dispatched_at timestamptz NOT NULL DEFAULT now(), CHECK(source_workshop_id<>destination_workshop_id)
);
CREATE TABLE workshop_transfer_receipts (
 transfer_id bigint PRIMARY KEY REFERENCES workshop_transfers, receipt_key uuid NOT NULL UNIQUE,
 received_by bigint NOT NULL REFERENCES users, condition text NOT NULL, received_at timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE workshop_jobs ADD COLUMN pending_transfer_id bigint REFERENCES workshop_transfers;
CREATE TABLE workshop_delivery_documents (
 delivery_id bigint PRIMARY KEY REFERENCES workshop_deliveries, document_id uuid NOT NULL UNIQUE REFERENCES system_official_documents
);
CREATE OR REPLACE FUNCTION workshop_guard_intake() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Workshop history cannot be deleted'; END IF;
 IF OLD.status IN('delivered','returned_unrepaired') OR
 ROW(NEW.creation_key,NEW.number,NEW.branch_id,NEW.customer_id,NEW.customer_owned,NEW.company_serial_id,NEW.maintenance_work_order_id,NEW.maintenance_visit_id,NEW.project_id,NEW.created_by)
 IS DISTINCT FROM ROW(OLD.creation_key,OLD.number,OLD.branch_id,OLD.customer_id,OLD.customer_owned,OLD.company_serial_id,OLD.maintenance_work_order_id,OLD.maintenance_visit_id,OLD.project_id,OLD.created_by)
 THEN RAISE EXCEPTION 'Workshop custody identity is immutable'; END IF;
 IF OLD.status<>'draft' AND ROW(NEW.product_id,NEW.serial_number,NEW.model,NEW.equipment_identity,NEW.isolation_id)
 IS DISTINCT FROM ROW(OLD.product_id,OLD.serial_number,OLD.model,OLD.equipment_identity,OLD.isolation_id)
 THEN RAISE EXCEPTION 'Received equipment identity is immutable'; END IF;
 RETURN NEW; END $$;
SQL);
        foreach (['quote_approvals', 'quotes', 'nonbillable_authorizations', 'transfers', 'transfer_receipts', 'delivery_documents'] as $table) {
            DB::unprepared("CREATE TRIGGER workshop_{$table}_immutable BEFORE UPDATE OR DELETE ON workshop_{$table} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M09.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => 'M09 · الورش', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Workshop evidence requires forward migrations.');
    }
};

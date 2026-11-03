<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE maintenance_work_orders ADD COLUMN contract_id bigint REFERENCES service_contracts(id), ADD COLUMN contract_equipment_ref varchar(160), ADD COLUMN contract_service_date date, ADD COLUMN contract_service_kind varchar(12);
ALTER TABLE maintenance_work_orders ADD CONSTRAINT maintenance_contract_identity CHECK((contract_id IS NULL AND contract_equipment_ref IS NULL AND contract_service_date IS NULL AND contract_service_kind IS NULL) OR (contract_id IS NOT NULL AND contract_equipment_ref IS NOT NULL AND contract_service_date IS NOT NULL AND contract_service_kind IN ('preventive','corrective') AND NOT billable));
ALTER TABLE workshop_jobs ADD COLUMN contract_id bigint REFERENCES service_contracts(id), ADD COLUMN contract_service_date date;
ALTER TABLE workshop_jobs ADD CONSTRAINT workshop_contract_identity CHECK((contract_id IS NULL AND billing_mode<>'contract') OR (contract_id IS NOT NULL AND contract_service_date IS NOT NULL AND billing_mode='contract')) NOT VALID;
CREATE TABLE service_contract_work (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL,
 owner varchar(20) NOT NULL CHECK(owner IN ('FieldMaintenance','WorkshopRepairs')), source_id bigint NOT NULL,
 equipment_ref varchar(160) NOT NULL, site_id bigint, service_date date NOT NULL, kind varchar(12) NOT NULL CHECK(kind IN ('preventive','corrective')),
 status varchar(12) NOT NULL DEFAULT 'reserved' CHECK(status IN ('reserved','consumed','released')),
 visits integer NOT NULL DEFAULT 1 CHECK(visits>0), labor_minutes integer NOT NULL DEFAULT 0 CHECK(labor_minutes>=0),
 created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(), consumed_at timestamptz, released_at timestamptz,
 UNIQUE(owner,source_id), FOREIGN KEY(contract_id,revision_id) REFERENCES service_contract_revisions(contract_id,id)
);
CREATE TABLE service_contract_work_parts (
 id bigserial PRIMARY KEY, work_id bigint NOT NULL REFERENCES service_contract_work(id), product_id bigint NOT NULL REFERENCES products(id),
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), UNIQUE(work_id,product_id)
);
CREATE TRIGGER service_contract_work_parts_immutable BEFORE UPDATE OR DELETE ON service_contract_work_parts FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION service_contract_guard_work() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'reserved' THEN RAISE EXCEPTION 'Contract work evidence is immutable'; END IF;
 IF ROW(NEW.contract_id,NEW.revision_id,NEW.owner,NEW.source_id,NEW.equipment_ref,NEW.site_id,NEW.service_date,NEW.kind,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.contract_id,OLD.revision_id,OLD.owner,OLD.source_id,OLD.equipment_ref,OLD.site_id,OLD.service_date,OLD.kind,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Contract work identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER service_contract_work_history BEFORE UPDATE OR DELETE ON service_contract_work FOR EACH ROW EXECUTE FUNCTION service_contract_guard_work();
CREATE OR REPLACE FUNCTION service_contract_guard_source_link() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF OLD.contract_id IS NOT NULL AND NEW.contract_id IS DISTINCT FROM OLD.contract_id THEN RAISE EXCEPTION 'Contract source link is immutable'; END IF;
 IF TG_TABLE_NAME='maintenance_work_orders' AND OLD.contract_id IS NOT NULL AND ROW(NEW.contract_equipment_ref,NEW.contract_service_date,NEW.contract_service_kind) IS DISTINCT FROM ROW(OLD.contract_equipment_ref,OLD.contract_service_date,OLD.contract_service_kind) THEN RAISE EXCEPTION 'Contract service identity is immutable'; END IF;
 IF TG_TABLE_NAME='workshop_jobs' AND OLD.contract_id IS NOT NULL AND NEW.contract_service_date IS DISTINCT FROM OLD.contract_service_date THEN RAISE EXCEPTION 'Contract service date is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER maintenance_contract_link BEFORE UPDATE ON maintenance_work_orders FOR EACH ROW EXECUTE FUNCTION service_contract_guard_source_link();
CREATE TRIGGER workshop_contract_link BEFORE UPDATE ON workshop_jobs FOR EACH ROW EXECUTE FUNCTION service_contract_guard_source_link();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain contract entitlement and service history; use forward migrations.');
    }
};

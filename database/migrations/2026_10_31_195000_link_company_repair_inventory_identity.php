<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE workshop_intakes ADD COLUMN company_movement_id bigint REFERENCES inventory_movements;
ALTER TABLE workshop_intakes DROP CONSTRAINT workshop_equipment_owner;
ALTER TABLE workshop_intakes ADD CONSTRAINT workshop_equipment_owner CHECK
 ((customer_owned AND customer_id IS NOT NULL AND company_serial_id IS NULL AND company_movement_id IS NULL) OR
  (NOT customer_owned AND customer_id IS NULL AND product_id IS NOT NULL AND
   ((company_serial_id IS NOT NULL AND company_movement_id IS NULL) OR
    (company_serial_id IS NULL AND company_movement_id IS NOT NULL AND nullif(trim(serial_number),'') IS NOT NULL))));
CREATE OR REPLACE FUNCTION workshop_guard_intake() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Workshop history cannot be deleted'; END IF;
 IF OLD.status IN('delivered','returned_unrepaired') OR
 ROW(NEW.creation_key,NEW.creation_payload_hash,NEW.number,NEW.branch_id,NEW.customer_id,NEW.customer_owned,NEW.company_serial_id,NEW.company_movement_id,NEW.maintenance_work_order_id,NEW.maintenance_visit_id,NEW.project_id,NEW.created_by)
 IS DISTINCT FROM ROW(OLD.creation_key,OLD.creation_payload_hash,OLD.number,OLD.branch_id,OLD.customer_id,OLD.customer_owned,OLD.company_serial_id,OLD.company_movement_id,OLD.maintenance_work_order_id,OLD.maintenance_visit_id,OLD.project_id,OLD.created_by)
 THEN RAISE EXCEPTION 'Workshop custody identity is immutable'; END IF;
 IF OLD.status<>'draft' AND ROW(NEW.product_id,NEW.serial_number,NEW.model,NEW.equipment_identity,NEW.isolation_id)
 IS DISTINCT FROM ROW(OLD.product_id,OLD.serial_number,OLD.model,OLD.equipment_identity,OLD.isolation_id)
 THEN RAISE EXCEPTION 'Received equipment identity is immutable'; END IF;
 RETURN NEW; END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Company equipment source references require forward migration.');
    }
};

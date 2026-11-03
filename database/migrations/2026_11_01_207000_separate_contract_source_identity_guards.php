<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION service_contract_guard_source_link() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF OLD.contract_id IS NOT NULL AND NEW.contract_id IS DISTINCT FROM OLD.contract_id THEN RAISE EXCEPTION 'Contract source link is immutable'; END IF;
 IF TG_TABLE_NAME='maintenance_work_orders' THEN
  IF OLD.contract_id IS NOT NULL AND ROW(NEW.contract_equipment_ref,NEW.contract_service_date,NEW.contract_service_kind) IS DISTINCT FROM ROW(OLD.contract_equipment_ref,OLD.contract_service_date,OLD.contract_service_kind) THEN RAISE EXCEPTION 'Contract service identity is immutable'; END IF;
 ELSIF TG_TABLE_NAME='workshop_jobs' THEN
  IF OLD.contract_id IS NOT NULL AND NEW.contract_service_date IS DISTINCT FROM OLD.contract_service_date THEN RAISE EXCEPTION 'Contract service date is immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Use a forward migration to preserve service source guards.');
    }
};

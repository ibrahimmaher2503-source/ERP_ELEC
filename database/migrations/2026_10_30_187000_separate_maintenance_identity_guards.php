<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION maintenance_guard_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Maintenance history cannot be deleted'; END IF;
 IF ROW(NEW.creation_key,NEW.number,NEW.branch_id,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.creation_key,OLD.number,OLD.branch_id,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Maintenance identity is immutable'; END IF;
 IF TG_TABLE_NAME='maintenance_work_orders' THEN
  IF ROW(NEW.request_id,NEW.customer_id,NEW.site_id,NEW.contact_id,NEW.project_id,NEW.product_id,NEW.project_task_id,NEW.project_milestone_id) IS DISTINCT FROM ROW(OLD.request_id,OLD.customer_id,OLD.site_id,OLD.contact_id,OLD.project_id,OLD.product_id,OLD.project_task_id,OLD.project_milestone_id) THEN RAISE EXCEPTION 'Work order source identity is immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Maintenance identity guards require forward migrations.');
    }
};

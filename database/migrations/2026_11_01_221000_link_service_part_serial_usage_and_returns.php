<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['maintenance' => ['FieldMaintenance', 'work_order_id'], 'workshop' => ['WorkshopRepairs', 'job_id']] as $prefix => [$owner, $parent]) {
            DB::unprepared(<<<SQL
ALTER TABLE {$prefix}_part_requirements ADD COLUMN tracking varchar(10) NOT NULL DEFAULT 'quantity' CHECK(tracking IN ('quantity','serial'));
ALTER TABLE {$prefix}_part_usage ADD COLUMN serials jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(serials)='array');
ALTER TABLE {$prefix}_part_returns ADD COLUMN serials jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(serials)='array');
ALTER TABLE {$prefix}_part_issues ADD COLUMN serials jsonb NOT NULL DEFAULT '[]' CHECK(jsonb_typeof(serials)='array');
CREATE TABLE {$prefix}_part_serial_usage (
 id bigserial PRIMARY KEY, usage_id bigint NOT NULL REFERENCES {$prefix}_part_usage(id), serial_id bigint NOT NULL UNIQUE REFERENCES inventory_serials(id),
 movement_id bigint NOT NULL REFERENCES inventory_movements(id), UNIQUE(usage_id,serial_id)
);
CREATE TRIGGER {$prefix}_part_serial_history BEFORE UPDATE OR DELETE ON {$prefix}_part_serial_usage FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION {$prefix}_part_tracking_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
BEGIN
 IF NEW.tracking IS DISTINCT FROM (SELECT tracking FROM products WHERE id=NEW.product_id) OR (NEW.tracking='serial' AND NEW.quantity<>trunc(NEW.quantity)) THEN
  RAISE EXCEPTION 'Service part tracking must preserve the actual product and whole serial units';
 END IF;
 RETURN NEW;
END \$\$;
CREATE TRIGGER {$prefix}_part_tracking BEFORE INSERT ON {$prefix}_part_requirements FOR EACH ROW EXECUTE FUNCTION {$prefix}_part_tracking_guard();
CREATE OR REPLACE FUNCTION {$prefix}_part_serial_usage_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$
DECLARE uid bigint; u {$prefix}_part_usage%ROWTYPE; r {$prefix}_part_requirements%ROWTYPE; names jsonb; count_refs bigint;
BEGIN
 IF TG_TABLE_NAME='{$prefix}_part_usage' THEN uid:=NEW.id; ELSE uid:=NEW.usage_id; END IF;
 SELECT * INTO u FROM {$prefix}_part_usage WHERE id=uid;
 SELECT * INTO r FROM {$prefix}_part_requirements WHERE id=u.requirement_id;
 SELECT count(*),coalesce(jsonb_agg(s.normalized ORDER BY s.normalized COLLATE "C"),'[]'::jsonb) INTO count_refs,names FROM {$prefix}_part_serial_usage x JOIN inventory_serials s ON s.id=x.serial_id WHERE x.usage_id=uid;
 IF (r.tracking='serial' AND (count_refs<>u.quantity OR u.serials<>names)) OR (r.tracking='quantity' AND (count_refs<>0 OR u.serials<>'[]'::jsonb)) THEN
  RAISE EXCEPTION 'Service usage must identify every actual serialized unit';
 END IF;
 IF EXISTS (
  SELECT 1 FROM {$prefix}_part_serial_usage x JOIN inventory_movements m ON m.id=x.movement_id JOIN inventory_serials s ON s.id=x.serial_id
  WHERE x.usage_id=uid AND (m.source_module<>'{$owner}' OR m.operation<>'{$prefix}_issue' OR m.source_id<>r.{$parent}::text OR m.source_line<>r.id::text
   OR m.product_id<>r.product_id OR s.product_id<>r.product_id OR s.state<>'issued' OR s.last_movement_id<>m.id
   OR NOT EXISTS (SELECT 1 FROM inventory_serial_events e WHERE e.serial_id=s.id AND e.movement_id=m.id))
 ) THEN RAISE EXCEPTION 'Service usage serial must belong to its genuine issue'; END IF;
 RETURN NEW;
END \$\$;
CREATE CONSTRAINT TRIGGER {$prefix}_usage_serial_source AFTER INSERT ON {$prefix}_part_usage DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION {$prefix}_part_serial_usage_guard();
CREATE CONSTRAINT TRIGGER {$prefix}_usage_serial_identity AFTER INSERT ON {$prefix}_part_serial_usage DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION {$prefix}_part_serial_usage_guard();
SQL);
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_service_return_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m inventory_movements%ROWTYPE; original inventory_movements%ROWTYPE; r record; i record; names jsonb; cost numeric(20,2); used boolean;
BEGIN
 IF TG_TABLE_NAME='inventory_movements' THEN m:=NEW; ELSE SELECT * INTO m FROM inventory_movements WHERE id=NEW.movement_id; END IF;
 IF m.operation NOT IN ('maintenance_return','workshop_return') THEN RETURN NEW; END IF;
 SELECT * INTO original FROM inventory_movements WHERE id=m.reversal_id;
 SELECT coalesce(jsonb_agg(s.normalized ORDER BY s.normalized COLLATE "C"),'[]'::jsonb) INTO names FROM inventory_serial_events e JOIN inventory_serials s ON s.id=e.serial_id WHERE e.movement_id=m.id;
 SELECT sum(amount) INTO cost FROM inventory_fifo_corrections WHERE movement_id=m.id;
 IF m.operation='maintenance_return' THEN
  SELECT * INTO r FROM maintenance_part_returns WHERE movement_id=m.id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Service stock return requires its actual owning return receipt'; END IF;
  SELECT * INTO i FROM maintenance_part_issues WHERE id=r.issue_id;
  SELECT EXISTS(SELECT 1 FROM maintenance_part_serial_usage u JOIN inventory_serial_events e ON e.serial_id=u.serial_id WHERE e.movement_id=m.id) INTO used;
 ELSE
  SELECT * INTO r FROM workshop_part_returns WHERE movement_id=m.id;
  IF NOT FOUND THEN RAISE EXCEPTION 'Service stock return requires its actual owning return receipt'; END IF;
  SELECT * INTO i FROM workshop_part_issues WHERE id=r.issue_id;
  SELECT EXISTS(SELECT 1 FROM workshop_part_serial_usage u JOIN inventory_serial_events e ON e.serial_id=u.serial_id WHERE e.movement_id=m.id) INTO used;
 END IF;
 IF i.movement_id IS DISTINCT FROM original.id OR original.operation<>replace(m.operation,'_return','_issue')
  OR m.warehouse_id<>original.warehouse_id OR m.product_id<>original.product_id OR m.quantity<>r.quantity
  OR r.amount IS DISTINCT FROM cost OR r.serials<>names OR used OR m.actor_id<>r.created_by
  OR m.idempotency_key IS DISTINCT FROM replace(m.operation,'_','-')||':'||r.return_key::text
 THEN RAISE EXCEPTION 'Service return must preserve unused serial identity and original source cost'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_service_return_source AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_service_return_guard();
CREATE CONSTRAINT TRIGGER maintenance_return_stock_source AFTER INSERT ON maintenance_part_returns DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_service_return_guard();
CREATE CONSTRAINT TRIGGER workshop_return_stock_source AFTER INSERT ON workshop_part_returns DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_service_return_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain service serial usage and exact-source return evidence.');
    }
};

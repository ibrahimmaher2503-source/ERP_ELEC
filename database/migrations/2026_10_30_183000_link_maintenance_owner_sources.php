<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE rp_customer_invoices ADD COLUMN maintenance_work_order_id bigint UNIQUE REFERENCES maintenance_work_orders;
ALTER TABLE rp_customer_invoice_lines ALTER COLUMN sales_order_line_id DROP NOT NULL;
ALTER TABLE rp_customer_invoice_lines ALTER COLUMN delivery_line_id DROP NOT NULL;
ALTER TABLE rp_customer_invoice_lines ADD COLUMN maintenance_approval_id bigint UNIQUE REFERENCES maintenance_commercial_approvals;
ALTER TABLE rp_customer_invoice_lines ADD CONSTRAINT rp_customer_source_kind CHECK
 ((maintenance_approval_id IS NOT NULL AND sales_order_line_id IS NULL AND delivery_line_id IS NULL) OR
 (maintenance_approval_id IS NULL AND sales_order_line_id IS NOT NULL AND delivery_line_id IS NOT NULL));
CREATE OR REPLACE FUNCTION maintenance_source_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m inventory_movements%ROWTYPE; q numeric; v numeric;
BEGIN
 IF TG_TABLE_NAME='maintenance_part_issues' THEN
  SELECT * INTO m FROM inventory_movements WHERE idempotency_key='reservation:'||NEW.issue_key;
  IF m.id IS NULL OR NOT EXISTS(SELECT 1 FROM maintenance_part_issues WHERE id=NEW.id AND movement_id=m.id AND amount IS NOT NULL) THEN RAISE EXCEPTION 'Maintenance issue requires posted source'; END IF;
 ELSE
  IF NEW.operation NOT IN ('maintenance_issue','maintenance_return') THEN RETURN NEW; END IF;
  m:=NEW;
 END IF;
 IF m.operation='maintenance_issue' THEN
  SELECT coalesce(sum(quantity),0),coalesce(sum(amount),0) INTO q,v FROM inventory_fifo_allocations WHERE movement_id=m.id;
  IF q<>-m.quantity OR NOT EXISTS(SELECT 1 FROM maintenance_part_issues i JOIN maintenance_part_requirements r ON r.id=i.requirement_id JOIN maintenance_work_orders w ON w.id=r.work_order_id JOIN inventory_reservations s ON s.id=i.reservation_id JOIN inventory_reservation_events e ON e.reservation_id=s.id AND e.idempotency_key=i.issue_key AND e.action='fulfilled'
   WHERE i.movement_id=m.id AND i.issue_key::text=substring(m.idempotency_key FROM 13) AND i.quantity=-m.quantity AND i.amount=v AND m.source_module='FieldMaintenance' AND m.source_id=w.id::text AND m.source_line=r.id::text AND m.product_id=r.product_id AND m.warehouse_id=r.warehouse_id AND s.source_module='maintenance' AND e.quantity=i.quantity) THEN RAISE EXCEPTION 'Maintenance source/FIFO mismatch'; END IF;
 ELSE
  SELECT original_quantity,original_value INTO q,v FROM inventory_fifo_layers WHERE movement_id=m.id;
  IF q IS NULL OR q<>m.quantity OR NOT EXISTS(SELECT 1 FROM inventory_fifo_corrections c JOIN inventory_fifo_allocations a ON a.id=c.allocation_id JOIN inventory_fifo_layers original ON original.id=c.source_layer_id JOIN inventory_fifo_layers restored ON restored.movement_id=m.id JOIN inventory_movements o ON o.id=c.original_movement_id JOIN maintenance_part_issues i ON i.movement_id=o.id JOIN maintenance_part_returns r ON r.issue_id=i.id AND r.movement_id=m.id
   WHERE c.movement_id=m.id AND m.reversal_id=o.id AND o.operation='maintenance_issue' AND m.quantity=c.quantity AND r.quantity=c.quantity AND r.amount=c.amount AND a.movement_id=o.id AND a.layer_id=c.source_layer_id AND restored.original_value=c.amount AND restored.received_on=original.received_on
   AND (SELECT sum(quantity) FROM inventory_fifo_corrections WHERE allocation_id=a.id)<=a.quantity AND (SELECT sum(amount) FROM inventory_fifo_corrections WHERE allocation_id=a.id)<=a.amount
   AND EXISTS(SELECT 1 FROM inventory_corrections WHERE correcting_movement_id=m.id AND movement_id=o.id AND status='posted')) THEN RAISE EXCEPTION 'Maintenance return needs original exact FIFO source'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id WHERE x.movement_id=m.id AND x.amount=v AND ((v=0 AND x.journal_id IS NULL) OR (v>0 AND j.status='posted' AND j.source_key='inventory-movement:'||m.id AND v=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND v=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Maintenance stock requires balanced GL'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER maintenance_issue_commit AFTER INSERT ON maintenance_part_issues DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION maintenance_source_commit();
CREATE CONSTRAINT TRIGGER inventory_maintenance_commit AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION maintenance_source_commit();
CREATE OR REPLACE FUNCTION maintenance_reservation_commit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NEW.source_module='maintenance' AND NOT EXISTS(SELECT 1 FROM maintenance_part_reservations l JOIN maintenance_part_requirements r ON r.id=l.requirement_id WHERE l.reservation_id=NEW.id AND r.work_order_id::text=NEW.source_id AND r.id::text=NEW.source_line AND r.product_id=NEW.product_id AND r.warehouse_id=NEW.warehouse_id) THEN RAISE EXCEPTION 'Maintenance reservation requires owner link'; END IF;
 RETURN NEW; END $$;
CREATE CONSTRAINT TRIGGER inventory_maintenance_reservation_commit AFTER INSERT OR UPDATE ON inventory_reservations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION maintenance_reservation_commit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Maintenance financial sources require forward migrations.');
    }
};

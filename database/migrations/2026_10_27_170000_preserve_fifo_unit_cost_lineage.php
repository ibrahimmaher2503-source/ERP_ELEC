<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_require_fifo_cost_lineage() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE actual numeric; expected numeric;
BEGIN
 IF NEW.operation='transfer_receive' THEN
  SELECT f.unit_cost,s.unit_cost INTO actual,expected FROM inventory_fifo_layers f
  JOIN inventory_transfer_allocations a ON a.movement_id=f.movement_id JOIN inventory_transfer_lots t ON t.id=a.lot_id
  JOIN inventory_fifo_allocations x ON x.id=t.allocation_id JOIN inventory_fifo_layers s ON s.id=x.layer_id WHERE f.movement_id=NEW.id;
 ELSIF NEW.operation='correction' AND NEW.quantity>0 THEN
  SELECT f.unit_cost,s.unit_cost INTO actual,expected FROM inventory_fifo_layers f JOIN inventory_fifo_corrections c ON c.movement_id=f.movement_id
  JOIN inventory_fifo_layers s ON s.id=c.source_layer_id WHERE f.movement_id=NEW.id;
 ELSIF NEW.operation='adjustment_post' AND NEW.quantity>0 THEN
  SELECT f.unit_cost,l.unit_cost INTO actual,expected FROM inventory_fifo_layers f JOIN inventory_document_lines l ON l.id=NEW.source_line::bigint AND l.document_id=NEW.document_id WHERE f.movement_id=NEW.id;
 ELSE RETURN NEW;
 END IF;
 IF actual IS NULL OR expected IS NULL OR actual<>expected THEN RAISE EXCEPTION 'FIFO unit cost must retain its authorized source'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_fifo_cost_lineage AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_fifo_cost_lineage();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('FIFO source costing requires forward migrations.');
    }
};

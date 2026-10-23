<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movement_nonzero CHECK (quantity <> 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Inventory history cannot be deleted'; END IF;
 IF OLD.status IN ('posted','received','cancelled') THEN RAISE EXCEPTION 'Final inventory document is immutable'; END IF;
 IF (NEW.kind,NEW.creation_key,NEW.warehouse_id,NEW.created_by) IS DISTINCT FROM (OLD.kind,OLD.creation_key,OLD.warehouse_id,OLD.created_by) THEN RAISE EXCEPTION 'Inventory document identity immutable'; END IF;
 IF OLD.kind='count' AND NEW.cutoff IS DISTINCT FROM OLD.cutoff THEN RAISE EXCEPTION 'Count cutoff is immutable'; END IF;
 IF OLD.status<>'draft' AND (NEW.destination_id,NEW.reason,NEW.cutoff) IS DISTINCT FROM (OLD.destination_id,OLD.reason,OLD.cutoff) THEN RAISE EXCEPTION 'Submitted inventory snapshot is immutable'; END IF;
 IF NEW.status<>OLD.status THEN
  IF NEW.version<>OLD.version+1 OR NOT (
    (OLD.status='draft' AND NEW.status IN ('submitted','cancelled')) OR
    (OLD.status='submitted' AND NEW.status IN ('approved','cancelled')) OR
    (OLD.status='submitted' AND OLD.kind='count' AND NEW.status='posted') OR
    (OLD.status='approved' AND NEW.status='cancelled') OR
    (OLD.status='approved' AND OLD.kind NOT IN ('count','transfer','receipt') AND NEW.status='posted') OR
    (OLD.status='approved' AND OLD.kind='transfer' AND NEW.status='dispatched') OR
    (OLD.status='dispatched' AND OLD.kind='transfer' AND NEW.status='received')
  ) THEN RAISE EXCEPTION 'Invalid inventory lifecycle transition'; END IF;
  IF NEW.status IN ('approved','posted','dispatched','received') AND (NEW.approved_by IS NULL OR NEW.approved_by=NEW.created_by OR NEW.approved_at IS NULL) THEN RAISE EXCEPTION 'Separate inventory approval required'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventory_movements DROP CONSTRAINT inventory_movement_nonzero');
        // History protection remains in force when rolling back this constraint.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE s text; did bigint; BEGIN
IF TG_OP='UPDATE' AND NEW.document_id<>OLD.document_id THEN RAISE EXCEPTION 'Inventory line parent is immutable'; END IF;
IF TG_OP='DELETE' THEN did=OLD.document_id; ELSE did=NEW.document_id; END IF;
SELECT status INTO s FROM inventory_documents WHERE id=did;
IF s IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Submitted inventory lines are immutable'; END IF;
IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW; END $$;
CREATE OR REPLACE FUNCTION inventory_reservation_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Reservation history cannot be deleted'; END IF;
IF (NEW.idempotency_key,NEW.source_module,NEW.source_id,NEW.source_line,NEW.warehouse_id,NEW.product_id,NEW.requested,NEW.reserved,NEW.actor_id,NEW.created_at) IS DISTINCT FROM (OLD.idempotency_key,OLD.source_module,OLD.source_id,OLD.source_line,OLD.warehouse_id,OLD.product_id,OLD.requested,OLD.reserved,OLD.actor_id,OLD.created_at)
OR NEW.fulfilled<OLD.fulfilled OR NEW.released<OLD.released THEN RAISE EXCEPTION 'Reservation request and settled history are immutable'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER inventory_reservation_guard_trigger BEFORE UPDATE OR DELETE ON inventory_reservations FOR EACH ROW EXECUTE FUNCTION inventory_reservation_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS inventory_reservation_guard_trigger ON inventory_reservations');
    }
};

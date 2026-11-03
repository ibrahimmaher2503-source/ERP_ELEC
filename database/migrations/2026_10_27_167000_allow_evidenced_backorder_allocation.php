<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_reservation_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Reservation history cannot be deleted'; END IF;
 IF ROW(NEW.idempotency_key,NEW.source_module,NEW.source_id,NEW.source_line,NEW.warehouse_id,NEW.product_id,NEW.requested,NEW.actor_id,NEW.created_at)
 IS DISTINCT FROM ROW(OLD.idempotency_key,OLD.source_module,OLD.source_id,OLD.source_line,OLD.warehouse_id,OLD.product_id,OLD.requested,OLD.actor_id,OLD.created_at)
 OR NEW.reserved<OLD.reserved OR NEW.fulfilled<OLD.fulfilled OR NEW.released<OLD.released THEN RAISE EXCEPTION 'Reservation request and settled history are immutable'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION inventory_require_reservation_allocation_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rid bigint; allocated numeric; r inventory_reservations%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='inventory_reservations' THEN rid:=NEW.id; ELSE rid:=NEW.reservation_id; END IF;
 SELECT * INTO r FROM inventory_reservations WHERE id=rid;
 SELECT coalesce(sum(quantity),0) INTO allocated FROM inventory_reservation_events WHERE reservation_id=rid AND action IN ('requested','replenished');
 IF r.reserved<>allocated THEN RAISE EXCEPTION 'Reservation allocation requires exact immutable evidence'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_reservation_allocation_evidence AFTER UPDATE ON inventory_reservations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_reservation_allocation_evidence();
CREATE CONSTRAINT TRIGGER inventory_reservation_event_allocation_evidence AFTER INSERT ON inventory_reservation_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_require_reservation_allocation_evidence();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Reservation allocation history requires forward migrations.');
    }
};

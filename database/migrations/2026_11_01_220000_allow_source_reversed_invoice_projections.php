<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION rp_guard_posted_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Financial invoice history cannot be deleted'; END IF;
 IF ROW(NEW.creation_key,NEW.branch_id,NEW.created_by) IS DISTINCT FROM ROW(OLD.creation_key,OLD.branch_id,OLD.created_by) THEN RAISE EXCEPTION 'Invoice identity is immutable'; END IF;
 IF OLD.status IN ('posted','partially_paid','paid','partially_collected','collected','credited') THEN
  IF (to_jsonb(NEW)-ARRAY['status','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','updated_at']) THEN
   RAISE EXCEPTION 'Posted invoice evidence is immutable';
  END IF;
  IF NOT (
   (OLD.status='posted' AND NEW.status IN ('partially_paid','paid','partially_collected','collected','credited')) OR
   (OLD.status='partially_paid' AND NEW.status IN ('paid','credited')) OR
   (OLD.status='partially_collected' AND NEW.status IN ('collected','credited')) OR
   (OLD.status IN ('paid','collected') AND NEW.status='credited') OR OLD.status=NEW.status OR
   (OLD.status<>'credited' AND EXISTS (
    SELECT 1 FROM rp_open_items i JOIN rp_settlement_allocations a ON a.open_item_id=i.id
    JOIN rp_cash_reversals r ON r.original_movement_id=a.movement_id
    JOIN treasury_movements m ON m.id=r.movement_id AND m.original_movement_id=r.original_movement_id
    WHERE i.source_id=NEW.id AND i.branch_id=NEW.branch_id AND
     i.source_type=(CASE WHEN TG_TABLE_NAME='rp_customer_invoices' THEN 'customer_invoice' ELSE 'supplier_invoice' END) AND
     NEW.status=(CASE WHEN i.remaining_amount=0 THEN CASE WHEN i.party_type='customer' THEN 'collected' ELSE 'paid' END
      WHEN i.settled_amount>0 THEN CASE WHEN i.party_type='customer' THEN 'partially_collected' ELSE 'partially_paid' END ELSE 'posted' END)
   ))
  ) THEN RAISE EXCEPTION 'Invalid settlement projection transition'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain source-proved invoice settlement projection history.');
    }
};

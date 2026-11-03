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
   (OLD.status IN ('paid','collected') AND NEW.status='credited') OR OLD.status=NEW.status
  ) THEN RAISE EXCEPTION 'Invalid settlement projection transition'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Settlement projection protection requires forward migrations.');
    }
};

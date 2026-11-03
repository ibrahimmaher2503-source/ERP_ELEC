<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL table refresh retains standalone functions. Retire the original
        // name so migration 196 can be replayed after a wipe without changing it.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION project_tender_guard_v2() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR ROW(NEW.creation_key,NEW.creation_hash,NEW.identity_hash,NEW.number,NEW.branch_id,NEW.customer_id,NEW.external_reference,NEW.issuer,NEW.created_by)
 IS DISTINCT FROM ROW(OLD.creation_key,OLD.creation_hash,OLD.identity_hash,OLD.number,OLD.branch_id,OLD.customer_id,OLD.external_reference,OLD.issuer,OLD.created_by)
 THEN RAISE EXCEPTION 'Tender source identity and history are immutable'; END IF;
 IF NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Tender version must advance exactly once'; END IF;
 RETURN NEW; END $$;
DROP TRIGGER project_tender_identity ON project_tenders;
CREATE TRIGGER project_tender_identity BEFORE UPDATE OR DELETE ON project_tenders FOR EACH ROW EXECUTE FUNCTION project_tender_guard_v2();
DROP FUNCTION project_tender_identity_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Tender source guards require forward migrations.');
    }
};

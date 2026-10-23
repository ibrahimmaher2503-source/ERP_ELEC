<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchase_requisition() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected_action text;
BEGIN
 IF TG_OP = 'DELETE' OR OLD.status = 'approved' THEN RAISE EXCEPTION 'Approved requisitions and history cannot be changed'; END IF;
 IF NEW.branch_id <> OLD.branch_id OR NEW.created_by <> OLD.created_by OR NEW.creation_key <> OLD.creation_key THEN RAISE EXCEPTION 'Requisition identity is immutable'; END IF;
 IF OLD.status <> 'draft' AND (NEW.request_date,NEW.required_date,NEW.department,NEW.purpose,NEW.notes) IS DISTINCT FROM (OLD.request_date,OLD.required_date,OLD.department,OLD.purpose,OLD.notes) THEN RAISE EXCEPTION 'Review snapshot is immutable'; END IF;
 IF NEW.status <> OLD.status THEN
   expected_action := CASE WHEN OLD.status='draft' AND NEW.status='submitted' THEN 'submit' WHEN OLD.status='submitted' AND NEW.status='approved' THEN 'approve' WHEN OLD.status='submitted' AND NEW.status='rejected' THEN 'reject' WHEN OLD.status='rejected' AND NEW.status='draft' THEN 'reopen' ELSE NULL END;
   IF expected_action IS NULL OR NEW.version <> OLD.version+1 OR NOT EXISTS(SELECT 1 FROM purchase_requisition_reviews WHERE requisition_id=OLD.id AND document_version=OLD.version AND action=expected_action) THEN RAISE EXCEPTION 'Transition requires an exact version review record'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
        DB::statement('CREATE UNIQUE INDEX requisition_review_version_unique ON purchase_requisition_reviews(requisition_id, document_version)');
    }

    public function down(): void {}
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE treasury_statement_matches ADD CONSTRAINT treasury_statement_match_reasons CHECK(length(trim(reason))>=5 AND
 ((active AND cleared_by IS NULL AND cleared_at IS NULL AND cleared_reason IS NULL) OR
  (NOT active AND cleared_by IS NOT NULL AND cleared_at IS NOT NULL AND length(trim(cleared_reason))>=5)));
ALTER TABLE treasury_statement_reviews ADD CONSTRAINT treasury_statement_review_reason CHECK(length(trim(reason))>=5);
CREATE OR REPLACE FUNCTION treasury_statement_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s treasury_statements%ROWTYPE; l treasury_statement_lines%ROWTYPE; m treasury_movements%ROWTYPE; signed numeric(20,2);
BEGIN
 SELECT * INTO s FROM treasury_statements WHERE id=NEW.statement_id FOR UPDATE;
 IF s.status<>'imported' THEN RAISE EXCEPTION 'Reopen statement before changing its evidence'; END IF;
 IF TG_TABLE_NAME='treasury_statement_lines' THEN
  IF s.version<>1 OR s.last_review_id IS NOT NULL OR NEW.transaction_date NOT BETWEEN s.starts_on AND s.ends_on THEN
   RAISE EXCEPTION 'Statement line is outside the original import';
  END IF;
 ELSIF TG_TABLE_NAME='treasury_statement_matches' THEN
  IF TG_OP='INSERT' AND NOT NEW.active THEN RAISE EXCEPTION 'New match must be active'; END IF;
  SELECT * INTO l FROM treasury_statement_lines WHERE id=NEW.line_id;
  SELECT * INTO m FROM treasury_movements WHERE id=NEW.movement_id;
  signed:=CASE WHEN m.kind IN ('customer_collection','transfer_in','supplier_credit_refund') THEN m.amount ELSE -m.amount END;
  IF l.statement_id<>s.id OR m.branch_id<>s.branch_id OR m.account_id<>s.account_id OR m.movement_date NOT BETWEEN s.starts_on AND s.ends_on OR l.amount<>signed THEN
   RAISE EXCEPTION 'Statement match must preserve actual line, account, branch, period and signed amount';
  END IF;
 ELSE
  IF NEW.version<>s.version OR NEW.reviewer_id=s.created_by OR (NEW.snapshot->>'statement_id')::bigint IS DISTINCT FROM s.id
   OR (NEW.snapshot->>'version')::int IS DISTINCT FROM s.version OR NEW.snapshot->>'file_sha256' IS DISTINCT FROM s.file_sha256 THEN
   RAISE EXCEPTION 'Independent statement review must bind the current source version';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER treasury_statement_line_source BEFORE INSERT ON treasury_statement_lines FOR EACH ROW EXECUTE FUNCTION treasury_statement_insert_guard();
CREATE TRIGGER treasury_statement_match_source BEFORE INSERT OR UPDATE ON treasury_statement_matches FOR EACH ROW EXECUTE FUNCTION treasury_statement_insert_guard();
CREATE TRIGGER treasury_statement_review_source BEFORE INSERT ON treasury_statement_reviews FOR EACH ROW EXECUTE FUNCTION treasury_statement_insert_guard();
CREATE OR REPLACE FUNCTION treasury_statement_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r treasury_statement_reviews%ROWTYPE;
BEGIN
 IF NEW.status='void' THEN RAISE EXCEPTION 'Statement evidence cannot be voided'; END IF;
 IF NEW.status IN ('reconciled','reviewed_with_differences') THEN
  SELECT * INTO r FROM treasury_statement_reviews WHERE id=NEW.last_review_id;
  IF OLD.status<>'imported' OR r.statement_id IS DISTINCT FROM NEW.id OR r.version IS DISTINCT FROM OLD.version THEN
   RAISE EXCEPTION 'Statement status requires its own current independent review';
  END IF;
  IF (NEW.status='reconciled') IS DISTINCT FROM
   ((r.snapshot->'reconciliation'->>'unmatched_lines')::int=0 AND (r.snapshot->'reconciliation'->>'unmatched_movements')::int=0 AND (r.snapshot->'reconciliation'->>'difference')::numeric=0)
  THEN RAISE EXCEPTION 'Statement status must retain the actual review result'; END IF;
 ELSIF NEW.status='imported' AND NEW.last_review_id IS DISTINCT FROM OLD.last_review_id THEN
  RAISE EXCEPTION 'Reopening preserves the previous immutable review';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER treasury_statement_state_source BEFORE UPDATE ON treasury_statements FOR EACH ROW EXECUTE FUNCTION treasury_statement_transition_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain bank statement source and review protections.');
    }
};

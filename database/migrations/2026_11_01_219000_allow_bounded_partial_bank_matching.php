<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE treasury_statement_matches ADD COLUMN amount numeric(20,2) CHECK(amount>0);
DROP INDEX treasury_statement_active_line;
DROP INDEX treasury_statement_active_movement;
CREATE UNIQUE INDEX treasury_statement_active_pair ON treasury_statement_matches(line_id,movement_id) WHERE active;
CREATE OR REPLACE FUNCTION treasury_statement_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s treasury_statements%ROWTYPE; l treasury_statement_lines%ROWTYPE; m treasury_movements%ROWTYPE;
 signed numeric(20,2); line_used numeric(20,2); movement_used numeric(20,2);
BEGIN
 SELECT * INTO s FROM treasury_statements WHERE id=NEW.statement_id FOR UPDATE;
 IF s.status<>'imported' THEN RAISE EXCEPTION 'Reopen statement before changing its evidence'; END IF;
 IF TG_TABLE_NAME='treasury_statement_lines' THEN
  IF s.version<>1 OR s.last_review_id IS NOT NULL OR NEW.transaction_date NOT BETWEEN s.starts_on AND s.ends_on THEN
   RAISE EXCEPTION 'Statement line is outside the original import';
  END IF;
 ELSIF TG_TABLE_NAME='treasury_statement_matches' THEN
  IF TG_OP='INSERT' AND NOT NEW.active THEN RAISE EXCEPTION 'New match must be active'; END IF;
  SELECT * INTO l FROM treasury_statement_lines WHERE id=NEW.line_id FOR UPDATE;
  SELECT * INTO m FROM treasury_movements WHERE id=NEW.movement_id FOR UPDATE;
  signed:=CASE WHEN m.kind IN ('customer_collection','transfer_in','supplier_credit_refund','cash_correction_in') THEN m.amount ELSE -m.amount END;
  IF l.statement_id<>s.id OR m.branch_id<>s.branch_id OR m.account_id<>s.account_id OR m.movement_date NOT BETWEEN s.starts_on AND s.ends_on OR (l.amount>0)<>(signed>0) THEN
   RAISE EXCEPTION 'Statement match must preserve actual line, account, branch, period and signed amount';
  END IF;
  IF TG_OP='INSERT' THEN
   -- Earlier immutable matches have a NULL amount and represent the original full-line match.
   SELECT coalesce(sum(coalesce(x.amount,abs(b.amount))),0) INTO line_used FROM treasury_statement_matches x JOIN treasury_statement_lines b ON b.id=x.line_id WHERE x.active AND x.line_id=l.id;
   SELECT coalesce(sum(coalesce(x.amount,abs(b.amount))),0) INTO movement_used FROM treasury_statement_matches x JOIN treasury_statement_lines b ON b.id=x.line_id WHERE x.active AND x.movement_id=m.id;
   NEW.amount:=coalesce(NEW.amount,least(abs(l.amount)-line_used,m.amount-movement_used));
   IF NEW.amount<=0 OR NEW.amount+line_used>abs(l.amount) OR NEW.amount+movement_used>m.amount THEN
    RAISE EXCEPTION 'Statement allocation exceeds remaining line or movement cents';
   END IF;
  END IF;
 ELSE
  IF NEW.version<>s.version OR NEW.reviewer_id=s.created_by OR (NEW.snapshot->>'statement_id')::bigint IS DISTINCT FROM s.id
   OR (NEW.snapshot->>'version')::int IS DISTINCT FROM s.version OR NEW.snapshot->>'file_sha256' IS DISTINCT FROM s.file_sha256 THEN
   RAISE EXCEPTION 'Independent statement review must bind the current source version';
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain immutable partial bank matching allocations.');
    }
};

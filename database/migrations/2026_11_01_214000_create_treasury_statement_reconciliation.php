<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE treasury_statements (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id), account_id bigint NOT NULL REFERENCES treasury_accounts(id),
 external_reference varchar(160) NOT NULL, starts_on date NOT NULL, ends_on date NOT NULL CHECK(ends_on>=starts_on),
 opening_balance numeric(20,2) NOT NULL, closing_balance numeric(20,2) NOT NULL, file_path text NOT NULL UNIQUE,
 file_sha256 char(64) NOT NULL, original_name varchar(200) NOT NULL, payload_hash char(64) NOT NULL,
 status varchar(32) NOT NULL DEFAULT 'imported' CHECK(status IN ('imported','reconciled','reviewed_with_differences','void')),
 version integer NOT NULL DEFAULT 1, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(branch_id,account_id,external_reference), UNIQUE(branch_id,account_id,file_sha256)
);
CREATE TABLE treasury_statement_lines (
 id bigserial PRIMARY KEY, statement_id bigint NOT NULL REFERENCES treasury_statements(id), external_id varchar(160) NOT NULL,
 transaction_date date NOT NULL, amount numeric(20,2) NOT NULL CHECK(amount<>0), reference varchar(500) NOT NULL,
 UNIQUE(statement_id,external_id)
);
CREATE TABLE treasury_statement_matches (
 id bigserial PRIMARY KEY, statement_id bigint NOT NULL REFERENCES treasury_statements(id), line_id bigint NOT NULL REFERENCES treasury_statement_lines(id),
 movement_id bigint NOT NULL REFERENCES treasury_movements(id), reason text NOT NULL, created_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT now(), active boolean NOT NULL DEFAULT true, cleared_by bigint REFERENCES users(id), cleared_at timestamptz, cleared_reason text,
 CHECK(active=(cleared_by IS NULL AND cleared_at IS NULL AND cleared_reason IS NULL))
);
CREATE UNIQUE INDEX treasury_statement_active_line ON treasury_statement_matches(line_id) WHERE active;
CREATE UNIQUE INDEX treasury_statement_active_movement ON treasury_statement_matches(movement_id) WHERE active;
CREATE TABLE treasury_statement_reviews (
 id bigserial PRIMARY KEY, statement_id bigint NOT NULL REFERENCES treasury_statements(id), version integer NOT NULL,
 reviewer_id bigint NOT NULL REFERENCES users(id), reviewed_at timestamptz NOT NULL DEFAULT now(), reason text NOT NULL,
 snapshot json NOT NULL CHECK(json_typeof(snapshot)='object'), snapshot_sha256 char(64) NOT NULL,
 UNIQUE(statement_id,version), CHECK(snapshot_sha256=encode(sha256(convert_to(snapshot::text,'UTF8')),'hex'))
);
ALTER TABLE treasury_statements ADD COLUMN last_review_id bigint REFERENCES treasury_statement_reviews(id);
ALTER TABLE treasury_statements ADD CONSTRAINT treasury_statement_review_required CHECK(status NOT IN ('reconciled','reviewed_with_differences') OR last_review_id IS NOT NULL);
CREATE TRIGGER treasury_statement_line_history BEFORE UPDATE OR DELETE ON treasury_statement_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER treasury_statement_review_history BEFORE UPDATE OR DELETE ON treasury_statement_reviews FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION treasury_guard_statement_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Statement history must be retained'; END IF;
 IF TG_TABLE_NAME='treasury_statements' THEN
  IF OLD.status='void' OR NEW.version<>OLD.version+1 OR (to_jsonb(NEW)-ARRAY['version','status','last_review_id']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['version','status','last_review_id'])
  THEN RAISE EXCEPTION 'Statement source identity is immutable'; END IF;
 ELSE
  IF NOT OLD.active OR NEW.active OR NEW.cleared_by IS NULL OR NEW.cleared_at IS NULL OR length(NEW.cleared_reason)<5
   OR (to_jsonb(NEW)-ARRAY['active','cleared_by','cleared_at','cleared_reason']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['active','cleared_by','cleared_at','cleared_reason'])
  THEN RAISE EXCEPTION 'Match evidence can only be explicitly cleared'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER treasury_statement_history BEFORE UPDATE OR DELETE ON treasury_statements FOR EACH ROW EXECUTE FUNCTION treasury_guard_statement_history();
CREATE TRIGGER treasury_statement_match_history BEFORE UPDATE OR DELETE ON treasury_statement_matches FOR EACH ROW EXECUTE FUNCTION treasury_guard_statement_history();
SQL);
        foreach (['statement_view' => 'عرض كشوف البنك ومطابقة الحركات', 'statement_manage' => 'استيراد ومطابقة كشوف البنك', 'statement_review' => 'مراجعة مطابقة البنك مستقلاً'] as $key => $label) {
            DB::table('permission_catalog')->insertOrIgnore(['code' => 'M13.'.$key, 'label' => $label, 'scope' => 'branch', 'module' => 'M13 · الخزينة', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Retain bank statement input, matching and independent review history.');
    }
};

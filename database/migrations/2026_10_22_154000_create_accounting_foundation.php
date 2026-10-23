<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE accounting_accounts (
 id bigserial PRIMARY KEY, code varchar(40) NOT NULL UNIQUE, name varchar(200) NOT NULL,
 kind varchar(20) NOT NULL CHECK(kind IN ('asset','liability','equity','income','expense')),
 parent_id bigint REFERENCES accounting_accounts(id), postable boolean NOT NULL DEFAULT true,
 active boolean NOT NULL DEFAULT true, version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), CHECK(parent_id<>id)
);
CREATE TABLE accounting_periods (
 id bigserial PRIMARY KEY, name varchar(100) NOT NULL, starts_on date NOT NULL, ends_on date NOT NULL,
 status varchar(10) NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(), CHECK(ends_on>=starts_on)
);
CREATE TABLE accounting_journals (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id),
 journal_date date NOT NULL, description text NOT NULL, currency char(3) NOT NULL DEFAULT 'EGP' CHECK(currency='EGP'),
 status varchar(15) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','approved','posted')),
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 posted_by bigint REFERENCES users(id), posted_at timestamptz, period_id bigint REFERENCES accounting_periods(id),
 official_number varchar(80) UNIQUE, official_document_id uuid,
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(approved_by IS NULL OR approved_by<>created_by),
 CHECK((status IN ('draft','submitted') AND approved_by IS NULL AND approved_at IS NULL) OR (status IN ('approved','posted') AND approved_by IS NOT NULL AND approved_at IS NOT NULL)),
 CHECK((status='posted' AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND period_id IS NOT NULL AND official_number IS NOT NULL AND official_document_id IS NOT NULL) OR (status<>'posted' AND posted_at IS NULL AND posted_by IS NULL AND official_number IS NULL AND official_document_id IS NULL))
);
CREATE INDEX accounting_journals_branch_date ON accounting_journals(branch_id,journal_date);
CREATE TABLE accounting_journal_lines (
 id bigserial PRIMARY KEY, journal_id bigint NOT NULL REFERENCES accounting_journals(id),
 account_id bigint NOT NULL REFERENCES accounting_accounts(id), account_code varchar(40) NOT NULL, account_name varchar(200) NOT NULL,
 debit numeric(20,2) NOT NULL DEFAULT 0, credit numeric(20,2) NOT NULL DEFAULT 0, description varchar(500) NOT NULL DEFAULT '',
 CHECK((debit>0 AND credit=0) OR (credit>0 AND debit=0))
);
CREATE INDEX accounting_lines_journal ON accounting_journal_lines(journal_id);
CREATE OR REPLACE FUNCTION accounting_guard_journal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE total_dr numeric; total_cr numeric; n integer;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Accounting journals cannot be deleted'; END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.status='posted' THEN RAISE EXCEPTION 'Posted journal is immutable'; END IF;
  IF ROW(OLD.creation_key,OLD.branch_id,OLD.created_by) IS DISTINCT FROM ROW(NEW.creation_key,NEW.branch_id,NEW.created_by) THEN RAISE EXCEPTION 'Journal identity is immutable'; END IF;
  IF OLD.status<>'draft' AND ROW(OLD.journal_date,OLD.description,OLD.currency) IS DISTINCT FROM ROW(NEW.journal_date,NEW.description,NEW.currency) THEN RAISE EXCEPTION 'Submitted content is frozen'; END IF;
  IF OLD.status<>NEW.status AND NOT ((OLD.status='draft' AND NEW.status='submitted') OR (OLD.status='submitted' AND NEW.status='approved') OR (OLD.status='approved' AND NEW.status='posted')) THEN RAISE EXCEPTION 'Invalid journal lifecycle'; END IF;
 END IF;
 IF NEW.status<>'draft' THEN
  SELECT count(*),coalesce(sum(debit),0),coalesce(sum(credit),0) INTO n,total_dr,total_cr FROM accounting_journal_lines WHERE journal_id=NEW.id;
  IF n<2 OR total_dr<=0 OR total_dr<>total_cr THEN RAISE EXCEPTION 'Unbalanced or empty journal'; END IF;
 END IF;
 IF NEW.status='posted' THEN
  IF NOT EXISTS(SELECT 1 FROM accounting_periods WHERE id=NEW.period_id AND status='open' AND NEW.journal_date BETWEEN starts_on AND ends_on) THEN RAISE EXCEPTION 'Period not open'; END IF;
  IF EXISTS(SELECT 1 FROM accounting_journal_lines l JOIN accounting_accounts a ON a.id=l.account_id WHERE l.journal_id=NEW.id AND (NOT a.active OR NOT a.postable)) THEN RAISE EXCEPTION 'Invalid posting account'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_journal_guard BEFORE INSERT OR UPDATE OR DELETE ON accounting_journals FOR EACH ROW EXECUTE FUNCTION accounting_guard_journal();
CREATE OR REPLACE FUNCTION accounting_guard_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE state varchar;
BEGIN
 SELECT status INTO state FROM accounting_journals WHERE id=CASE WHEN TG_OP='DELETE' THEN OLD.journal_id ELSE NEW.journal_id END FOR UPDATE;
 IF state IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Only draft lines may change'; END IF;
 IF TG_OP='UPDATE' AND NEW.journal_id<>OLD.journal_id THEN RAISE EXCEPTION 'Line owner is immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_line_guard BEFORE INSERT OR UPDATE OR DELETE ON accounting_journal_lines FOR EACH ROW EXECUTE FUNCTION accounting_guard_line();
SQL);
        foreach (\App\Modules\Organization\Application\PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M11.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => 'M11 · المحاسبة', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(\App\Modules\Organization\Application\SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void {}
};

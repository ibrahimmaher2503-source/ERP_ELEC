<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE accounting_activation (
 id smallint PRIMARY KEY CHECK(id=1), go_live_date date, version integer NOT NULL DEFAULT 1,
 tax_validated boolean NOT NULL DEFAULT false, tax_validation_reference text,
 configured_by bigint REFERENCES users(id), activated_by bigint REFERENCES users(id), activated_at timestamptz,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(NOT tax_validated OR length(trim(tax_validation_reference))>=5),
 CHECK((activated_at IS NULL AND activated_by IS NULL) OR (activated_at IS NOT NULL AND activated_by IS NOT NULL AND go_live_date IS NOT NULL AND tax_validated))
);
INSERT INTO accounting_activation(id) VALUES(1);
CREATE TABLE accounting_account_mappings (
 purpose varchar(40) PRIMARY KEY, account_id bigint NOT NULL REFERENCES accounting_accounts(id), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE accounting_tax_rules (
 id bigserial PRIMARY KEY, code varchar(40) NOT NULL, name varchar(200) NOT NULL,
 transaction_type varchar(30) NOT NULL CHECK(transaction_type IN ('sale','purchase')),
 product_id bigint, rate numeric(8,5) NOT NULL CHECK(rate>=0 AND rate<=999),
 starts_on date NOT NULL, ends_on date, tax_scale smallint NOT NULL CHECK(tax_scale IN (2,5)),
 treatment varchar(20) NOT NULL CHECK(treatment IN ('percentage','exempt')),
 legal_reference text NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(ends_on IS NULL OR ends_on>=starts_on), CHECK(treatment<>'exempt' OR rate=0)
);
CREATE INDEX accounting_tax_selector ON accounting_tax_rules(transaction_type,product_id,starts_on,ends_on);
CREATE TABLE accounting_tax_rule_endings (
 rule_id bigint PRIMARY KEY REFERENCES accounting_tax_rules(id), ends_on date,
 withdrawn boolean NOT NULL DEFAULT false, reason text NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(withdrawn OR ends_on IS NOT NULL)
);
CREATE TABLE accounting_opening_batches (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, configuration_version integer NOT NULL,
 status varchar(15) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','approved','posted')),
 no_balances boolean NOT NULL DEFAULT false, evidence_reference text NOT NULL,
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 submitted_at timestamptz, posted_at timestamptz, version integer NOT NULL DEFAULT 1,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 CHECK(approved_by IS NULL OR approved_by<>created_by),
 CHECK((status IN ('approved','posted'))=(approved_by IS NOT NULL AND approved_at IS NOT NULL)),
 CHECK((status='posted')=(posted_at IS NOT NULL))
);
CREATE UNIQUE INDEX accounting_single_posted_opening ON accounting_opening_batches((true)) WHERE status='posted';
CREATE TABLE accounting_opening_lines (
 id bigserial PRIMARY KEY, batch_id bigint NOT NULL REFERENCES accounting_opening_batches(id),
 kind varchar(15) NOT NULL CHECK(kind IN ('gl','ap','cash','bank','inventory')),
 reference varchar(160) NOT NULL, branch_id bigint NOT NULL REFERENCES branches(id),
 account_id bigint REFERENCES accounting_accounts(id), supplier_id bigint, warehouse_id bigint, product_id bigint,
 debit numeric(20,2) NOT NULL DEFAULT 0 CHECK(debit>=0), credit numeric(20,2) NOT NULL DEFAULT 0 CHECK(credit>=0),
 quantity numeric(24,8), unit_cost numeric(36,16), amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(amount>=0),
 UNIQUE(batch_id,kind,reference), CHECK(kind<>'gl' OR ((debit>0 AND credit=0) OR (credit>0 AND debit=0))),
 CHECK(kind<>'inventory' OR (quantity>0 AND unit_cost>=0 AND warehouse_id IS NOT NULL AND product_id IS NOT NULL))
);
ALTER TABLE accounting_journals ADD COLUMN opening_batch_id bigint REFERENCES accounting_opening_batches(id);
ALTER TABLE accounting_journals ADD COLUMN source_key varchar(160) UNIQUE;
CREATE OR REPLACE FUNCTION accounting_guard_activation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Financial activation cannot be deleted'; END IF;
 IF OLD.activated_at IS NOT NULL THEN RAISE EXCEPTION 'Activated financial configuration is frozen'; END IF;
 IF NEW.activated_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM accounting_opening_batches WHERE status='posted' AND configuration_version=NEW.version) THEN RAISE EXCEPTION 'Approved posted opening required'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_activation_guard BEFORE UPDATE OR DELETE ON accounting_activation FOR EACH ROW EXECUTE FUNCTION accounting_guard_activation();
CREATE OR REPLACE FUNCTION accounting_guard_opening() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Opening history cannot be deleted'; END IF;
 IF TG_OP='INSERT' AND NEW.status<>'draft' THEN RAISE EXCEPTION 'Opening must start as draft'; END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.status='posted' THEN RAISE EXCEPTION 'Posted opening is immutable'; END IF;
  IF ROW(OLD.creation_key,OLD.created_by,OLD.configuration_version,OLD.no_balances,OLD.evidence_reference) IS DISTINCT FROM ROW(NEW.creation_key,NEW.created_by,NEW.configuration_version,NEW.no_balances,NEW.evidence_reference) THEN RAISE EXCEPTION 'Opening content immutable; prepare a new batch'; END IF;
  IF OLD.status<>NEW.status AND NOT ((OLD.status='draft' AND NEW.status='submitted') OR (OLD.status='submitted' AND NEW.status='approved') OR (OLD.status='approved' AND NEW.status='posted')) THEN RAISE EXCEPTION 'Invalid opening lifecycle'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_opening_guard BEFORE INSERT OR UPDATE OR DELETE ON accounting_opening_batches FOR EACH ROW EXECUTE FUNCTION accounting_guard_opening();
CREATE OR REPLACE FUNCTION accounting_guard_opening_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE state varchar;
BEGIN
 SELECT status INTO state FROM accounting_opening_batches WHERE id=CASE WHEN TG_OP='DELETE' THEN OLD.batch_id ELSE NEW.batch_id END FOR UPDATE;
 IF state<>'draft' THEN RAISE EXCEPTION 'Opening lines frozen'; END IF;
 IF TG_OP='UPDATE' AND OLD.batch_id<>NEW.batch_id THEN RAISE EXCEPTION 'Opening line owner immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_opening_line_guard BEFORE INSERT OR UPDATE OR DELETE ON accounting_opening_lines FOR EACH ROW EXECUTE FUNCTION accounting_guard_opening_line();
CREATE OR REPLACE FUNCTION accounting_financial_posting_gate() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE setup accounting_activation%ROWTYPE;
BEGIN
 IF NEW.status='posted' THEN
  SELECT * INTO setup FROM accounting_activation WHERE id=1 FOR SHARE;
  IF setup.go_live_date IS NULL OR NEW.journal_date<setup.go_live_date THEN RAISE EXCEPTION 'Before company Go-Live'; END IF;
  IF NEW.opening_batch_id IS NULL AND setup.activated_at IS NULL THEN RAISE EXCEPTION 'Financial posting is disabled'; END IF;
  IF NEW.opening_batch_id IS NOT NULL AND (setup.activated_at IS NOT NULL OR NEW.journal_date<>setup.go_live_date OR NOT EXISTS(SELECT 1 FROM accounting_opening_batches b WHERE b.id=NEW.opening_batch_id AND b.status='approved' AND b.configuration_version=setup.version)) THEN RAISE EXCEPTION 'Invalid opening posting'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_posting_gate BEFORE INSERT OR UPDATE ON accounting_journals FOR EACH ROW EXECUTE FUNCTION accounting_financial_posting_gate();
CREATE OR REPLACE FUNCTION accounting_immutable_record() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Immutable financial evidence'; END $$;
CREATE TRIGGER accounting_tax_immutable BEFORE UPDATE OR DELETE ON accounting_tax_rules FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER accounting_tax_ending_immutable BEFORE UPDATE OR DELETE ON accounting_tax_rule_endings FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M11.') || $code === 'M05.receipt_post') {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => str_starts_with($code, 'M11.') ? 'M11 · المحاسبة' : 'M05 · المشتريات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

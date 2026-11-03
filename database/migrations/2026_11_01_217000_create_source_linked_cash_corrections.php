<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE treasury_movements DROP CONSTRAINT treasury_movements_kind_check;
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_movements_kind_check CHECK(kind IN ('supplier_payment','customer_collection','transfer_out','transfer_in','customer_advance_refund','customer_credit_refund','supplier_credit_refund','cash_correction_in','cash_correction_out'));
ALTER TABLE treasury_movements ADD COLUMN original_movement_id bigint UNIQUE REFERENCES treasury_movements(id);
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_correction_identity CHECK((kind IN ('cash_correction_in','cash_correction_out'))=(original_movement_id IS NOT NULL));
CREATE TABLE rp_cash_reversals (
 id bigserial PRIMARY KEY, original_movement_id bigint NOT NULL UNIQUE REFERENCES treasury_movements(id), movement_id bigint NOT NULL UNIQUE REFERENCES treasury_movements(id),
 effective_date date NOT NULL, created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER rp_cash_reversal_history BEFORE UPDATE OR DELETE ON rp_cash_reversals FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION treasury_cash_correction_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m treasury_movements%ROWTYPE; o treasury_movements%ROWTYPE; paired treasury_movements%ROWTYPE; canonical bigint; j accounting_journals%ROWTYPE;
BEGIN
 IF TG_TABLE_NAME='rp_cash_reversals' THEN SELECT * INTO m FROM treasury_movements WHERE id=NEW.movement_id;
 ELSE m:=NEW; END IF;
 IF m.original_movement_id IS NULL THEN RETURN NEW; END IF;
 SELECT * INTO o FROM treasury_movements WHERE id=m.original_movement_id;
 canonical:=CASE WHEN o.kind='transfer_in' THEN o.paired_movement_id ELSE o.id END;
 SELECT * INTO j FROM accounting_journals WHERE id=m.journal_id;
 IF o.original_movement_id IS NOT NULL OR m.branch_id<>o.branch_id OR m.account_id<>o.account_id OR m.amount<>o.amount OR m.party_id IS DISTINCT FROM o.party_id
  OR m.movement_date<o.movement_date OR m.executed_by=o.executed_by OR j.status<>'posted' OR j.branch_id<>o.branch_id OR j.journal_date<>m.movement_date
  OR j.source_key IS DISTINCT FROM 'treasury-correction:'||canonical::text OR length(trim(m.reference))<5
  OR m.kind<>(CASE WHEN o.kind IN ('customer_collection','transfer_in','supplier_credit_refund') THEN 'cash_correction_out' ELSE 'cash_correction_in' END)
 THEN RAISE EXCEPTION 'Cash correction must reverse its exact independent source'; END IF;
 IF o.kind IN ('transfer_in','transfer_out') THEN
  SELECT * INTO paired FROM treasury_movements WHERE original_movement_id=o.paired_movement_id;
  IF paired.id IS NULL OR paired.paired_movement_id IS DISTINCT FROM m.id OR m.paired_movement_id IS DISTINCT FROM paired.id
   OR paired.journal_id<>m.journal_id OR paired.movement_date<>m.movement_date OR paired.executed_by<>m.executed_by
  THEN RAISE EXCEPTION 'Transfer corrections must preserve the atomic source pair'; END IF;
 ELSIF m.paired_movement_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=o.id AND r.movement_id=m.id AND r.effective_date=m.movement_date AND r.created_by=m.executed_by) THEN
  RAISE EXCEPTION 'Cash correction requires its owning subledger reversal';
 END IF;
 IF EXISTS ((SELECT account_id,sum(debit) dr,sum(credit) cr FROM accounting_journal_lines WHERE journal_id=o.journal_id GROUP BY account_id)
  EXCEPT (SELECT account_id,sum(credit),sum(debit) FROM accounting_journal_lines WHERE journal_id=m.journal_id GROUP BY account_id))
  OR EXISTS ((SELECT account_id,sum(credit) dr,sum(debit) cr FROM accounting_journal_lines WHERE journal_id=m.journal_id GROUP BY account_id)
  EXCEPT (SELECT account_id,sum(debit),sum(credit) FROM accounting_journal_lines WHERE journal_id=o.journal_id GROUP BY account_id))
 THEN RAISE EXCEPTION 'Cash correction must reverse original account cents without remapping'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER treasury_cash_correction_source AFTER INSERT ON treasury_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION treasury_cash_correction_guard();
CREATE CONSTRAINT TRIGGER rp_cash_reversal_source AFTER INSERT ON rp_cash_reversals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION treasury_cash_correction_guard();
CREATE OR REPLACE FUNCTION accounting_treasury_correction_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='posted' AND NEW.source_key LIKE 'treasury-correction:%' AND NOT EXISTS (SELECT 1 FROM treasury_movements WHERE journal_id=NEW.id AND original_movement_id IS NOT NULL) THEN
  RAISE EXCEPTION 'Treasury correcting journal requires actual source movements';
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER accounting_treasury_correction_source AFTER INSERT OR UPDATE ON accounting_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION accounting_treasury_correction_guard();
SQL);
        $function = DB::selectOne("SELECT pg_get_functiondef('rp_party_credit_refund_guard()'::regprocedure) AS definition")->definition;
        $function = str_replace('FROM rp_party_credit_refunds WHERE credit_id=cid', 'FROM rp_party_credit_refunds WHERE credit_id=cid AND NOT EXISTS (SELECT 1 FROM rp_cash_reversals WHERE original_movement_id=rp_party_credit_refunds.movement_id)', $function);
        DB::unprepared($function);
        DB::table('permission_catalog')->insertOrIgnore(['code' => 'M13.cash_correct', 'label' => 'تصحيح مستقل لحركة خزينة من مصدرها', 'scope' => 'branch', 'module' => 'M13', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain immutable cash correction and original source history.');
    }
};

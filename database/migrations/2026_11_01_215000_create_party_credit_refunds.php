<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE rp_party_credit_refunds (
 id bigserial PRIMARY KEY, credit_id bigint NOT NULL REFERENCES rp_party_credits(id), movement_id bigint NOT NULL UNIQUE REFERENCES treasury_movements(id),
 amount numeric(20,2) NOT NULL CHECK(amount>0), refund_date date NOT NULL, creation_key uuid NOT NULL UNIQUE,
 created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER rp_party_credit_refund_history BEFORE UPDATE OR DELETE ON rp_party_credit_refunds FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
ALTER TABLE treasury_movements DROP CONSTRAINT treasury_movements_kind_check;
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_movements_kind_check CHECK(kind IN ('supplier_payment','customer_collection','transfer_out','transfer_in','customer_advance_refund','customer_credit_refund','supplier_credit_refund'));
ALTER TABLE treasury_movements ADD COLUMN party_credit_id bigint REFERENCES rp_party_credits(id);
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_credit_refund_identity CHECK((kind IN ('customer_credit_refund','supplier_credit_refund'))=(party_credit_id IS NOT NULL));
CREATE UNIQUE INDEX treasury_credit_refund_journal ON treasury_movements(journal_id) WHERE party_credit_id IS NOT NULL;
CREATE OR REPLACE FUNCTION rp_party_credit_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.id,NEW.credit_note_id,NEW.branch_id,NEW.party_type,NEW.party_id,NEW.original_amount,NEW.created_at)
    IS DISTINCT FROM ROW(OLD.id,OLD.credit_note_id,OLD.branch_id,OLD.party_type,OLD.party_id,OLD.original_amount,OLD.created_at) THEN
  RAISE EXCEPTION 'Party credit source is immutable';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER rp_party_credit_source BEFORE UPDATE ON rp_party_credits FOR EACH ROW EXECUTE FUNCTION rp_party_credit_source_guard();
CREATE OR REPLACE FUNCTION rp_party_credit_refund_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cid bigint; c rp_party_credits%ROWTYPE; total numeric(20,2);
BEGIN
 IF TG_TABLE_NAME='rp_party_credits' THEN cid:=NEW.id; ELSE cid:=NEW.credit_id; END IF;
 SELECT * INTO c FROM rp_party_credits WHERE id=cid FOR UPDATE;
 SELECT coalesce(sum(amount),0) INTO total FROM rp_party_credit_refunds WHERE credit_id=cid;
 IF total>c.original_amount OR c.allocated_amount<>total THEN RAISE EXCEPTION 'Party credit refund residual mismatch'; END IF;
 IF EXISTS (
  SELECT 1 FROM rp_party_credit_refunds r JOIN treasury_movements m ON m.id=r.movement_id
  JOIN rp_credit_notes n ON n.id=c.credit_note_id JOIN accounting_journals j ON j.id=m.journal_id
  WHERE r.credit_id=cid AND (m.party_credit_id IS DISTINCT FROM cid OR m.kind<>c.party_type||'_credit_refund'
   OR m.branch_id<>c.branch_id OR m.party_id IS DISTINCT FROM c.party_id OR m.amount<>r.amount OR m.movement_date<>r.refund_date
   OR m.idempotency_key<>r.creation_key OR m.executed_by<>r.created_by OR n.status<>'posted' OR r.refund_date<n.note_date
   OR j.status<>'posted' OR j.branch_id<>c.branch_id OR j.journal_date<>r.refund_date
   OR j.source_key IS DISTINCT FROM 'party-credit-refund:'||r.creation_key::text)
 ) THEN RAISE EXCEPTION 'Party credit refund source mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER rp_party_credit_refund_source AFTER INSERT ON rp_party_credit_refunds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION rp_party_credit_refund_guard();
CREATE CONSTRAINT TRIGGER rp_party_credit_refund_balance AFTER INSERT OR UPDATE ON rp_party_credits DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION rp_party_credit_refund_guard();
CREATE OR REPLACE FUNCTION treasury_credit_refund_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.party_credit_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM rp_party_credit_refunds WHERE movement_id=NEW.id AND credit_id=NEW.party_credit_id) THEN
  RAISE EXCEPTION 'Treasury credit refund requires its owning source receipt';
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER treasury_credit_refund_source AFTER INSERT ON treasury_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION treasury_credit_refund_source_guard();
SQL);
        DB::table('permission_catalog')->insertOrIgnore(['code' => 'M13.credit_refund', 'label' => 'رد الرصيد الدائن المرتبط بإشعار مرحّل', 'scope' => 'branch', 'module' => 'M13', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain source-linked credit refund history.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION org_allows_self_approval(p_actor_id bigint, p_scope text) RETURNS boolean LANGUAGE sql STABLE AS $$
SELECT EXISTS (
    SELECT 1
    FROM users u
    WHERE u.id = p_actor_id
      AND u.active = true
      AND u.email_verified_at IS NOT NULL
      AND (
          EXISTS (
              SELECT 1
              FROM organization_self_approval_policies p
              WHERE p.scope = p_scope
                AND p.enabled = true
                AND (
                    (p.target_type = 'user' AND p.target_id = u.id)
                    OR (p.target_type = 'role' AND p.target_id IN (SELECT ur.role_id FROM user_roles ur WHERE ur.user_id = u.id))
                )
          )
          OR (
              p_scope = 'quotations'
              AND EXISTS (
                  SELECT 1
                  FROM quotation_self_approval_policies qp
                  WHERE qp.enabled = true
                    AND (
                        (qp.target_type = 'user' AND qp.target_id = u.id)
                        OR (qp.target_type = 'role' AND qp.target_id IN (SELECT ur.role_id FROM user_roles ur WHERE ur.user_id = u.id))
                    )
              )
          )
      )
);
$$;

CREATE OR REPLACE FUNCTION org_guard_self_approval() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    actor_col text := TG_ARGV[0];
    creator_col text := TG_ARGV[1];
    scope_name text := TG_ARGV[2];
    status_filter text := CASE WHEN TG_NARGS >= 4 THEN TG_ARGV[3] ELSE NULL END;
    j jsonb := to_jsonb(NEW);
    v_actor bigint := NULLIF(j->>actor_col, '')::bigint;
    v_creator bigint := NULLIF(j->>creator_col, '')::bigint;
    v_status text := j->>'status';
BEGIN
    IF status_filter IS NOT NULL AND v_status IS DISTINCT FROM status_filter THEN
        RETURN NEW;
    END IF;
    IF v_actor IS NOT NULL AND v_creator IS NOT NULL AND v_actor = v_creator AND NOT org_allows_self_approval(v_actor, scope_name) THEN
        RAISE EXCEPTION 'Independent approval required for scope %', scope_name;
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION org_guard_manual_journal_self_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.source_key IS NULL AND NEW.approved_by IS NOT NULL AND NEW.approved_by = NEW.created_by AND NOT org_allows_self_approval(NEW.approved_by, 'accounting') THEN
        RAISE EXCEPTION 'Independent manual journal approval required';
    END IF;
    RETURN NEW;
END $$;

ALTER TABLE accounting_journals DROP CONSTRAINT accounting_manual_independent_approval;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON accounting_journals FOR EACH ROW EXECUTE FUNCTION org_guard_manual_journal_self_approval();

ALTER TABLE accounting_opening_batches DROP CONSTRAINT accounting_opening_batches_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON accounting_opening_batches FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'accounting');

ALTER TABLE workshop_rates DROP CONSTRAINT workshop_rates_check1;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON workshop_rates FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'proposed_by', 'workshops');

ALTER TABLE treasury_payment_requests DROP CONSTRAINT treasury_payment_requests_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON treasury_payment_requests FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'treasury_ar_ap');

ALTER TABLE rp_credit_notes DROP CONSTRAINT rp_credit_notes_check1;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON rp_credit_notes FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'treasury_ar_ap');

ALTER TABLE product_sale_prices DROP CONSTRAINT product_sale_prices_check1;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON product_sale_prices FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'purchasing');

ALTER TABLE purchasing_orders DROP CONSTRAINT po_no_self_approval;
ALTER TABLE purchasing_orders ADD CONSTRAINT po_approval_actor CHECK ((status <> 'approved') OR (approved_by IS NOT NULL));
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON purchasing_orders FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'purchasing', 'approved');

ALTER TABLE purchasing_awards DROP CONSTRAINT award_authority;
ALTER TABLE purchasing_awards ADD CONSTRAINT award_authority CHECK ((status IN ('proposed', 'approved', 'rejected')) AND ((status <> 'approved') OR (approved_by IS NOT NULL)));
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON purchasing_awards FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'purchasing', 'approved');

ALTER TABLE purchasing_receipts DROP CONSTRAINT receipt_financial_state;
ALTER TABLE purchasing_receipts ADD CONSTRAINT receipt_financial_state CHECK (
    ((status IN ('draft', 'integration_pending')) AND (inventory_document_id IS NULL) AND (journal_id IS NULL) AND (capitalized_amount IS NULL) AND (posted_at IS NULL) AND (posted_by IS NULL))
    OR ((status = 'posted') AND (inventory_document_id IS NULL) AND (capitalized_amount IS NOT NULL) AND (posted_at IS NOT NULL) AND (posted_by IS NOT NULL) AND (((capitalized_amount = 0) AND (journal_id IS NULL)) OR ((capitalized_amount > 0) AND (journal_id IS NOT NULL))))
);
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON purchasing_receipts FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('posted_by', 'created_by', 'purchasing', 'posted');

ALTER TABLE inventory_documents DROP CONSTRAINT inventory_document_state;
ALTER TABLE inventory_documents ADD CONSTRAINT inventory_document_state CHECK (
    (kind IN ('opening', 'receipt', 'issue', 'transfer', 'adjustment', 'count'))
    AND (status IN ('draft', 'submitted', 'approved', 'posted', 'dispatched', 'received', 'cancelled'))
    AND ((destination_id IS NULL) OR (destination_id <> warehouse_id))
);

ALTER TABLE attendance_adjustments DROP CONSTRAINT attendance_adjustments_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON attendance_adjustments FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('reviewed_by', 'created_by', 'operations_other');

ALTER TABLE maintenance_commercial_approvals DROP CONSTRAINT maintenance_commercial_approvals_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON maintenance_commercial_approvals FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'proposed_by', 'operations_other');

ALTER TABLE maintenance_labor_rates DROP CONSTRAINT maintenance_labor_rates_check1;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON maintenance_labor_rates FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

ALTER TABLE service_contract_billing_sources DROP CONSTRAINT service_contract_billing_sources_check1;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON service_contract_billing_sources FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

ALTER TABLE service_contract_claims DROP CONSTRAINT service_contract_claims_check2;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON service_contract_claims FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

ALTER TABLE service_contract_measurements DROP CONSTRAINT service_contract_measurements_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON service_contract_measurements FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

ALTER TABLE service_contract_recognition_reversals DROP CONSTRAINT service_contract_recognition_reversals_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON service_contract_recognition_reversals FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

ALTER TABLE service_contract_recognitions DROP CONSTRAINT service_contract_recognitions_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON service_contract_recognitions FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

ALTER TABLE service_contract_revisions DROP CONSTRAINT service_contract_revisions_check;
CREATE TRIGGER org_self_approval_guard BEFORE INSERT OR UPDATE ON service_contract_revisions FOR EACH ROW EXECUTE FUNCTION org_guard_self_approval('approved_by', 'created_by', 'operations_other');

CREATE OR REPLACE FUNCTION inventory_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Inventory history cannot be deleted'; END IF;
 IF OLD.status IN ('posted','received','cancelled') THEN RAISE EXCEPTION 'Final inventory document is immutable'; END IF;
 IF (NEW.kind,NEW.creation_key,NEW.warehouse_id,NEW.created_by) IS DISTINCT FROM (OLD.kind,OLD.creation_key,OLD.warehouse_id,OLD.created_by) THEN RAISE EXCEPTION 'Inventory document identity immutable'; END IF;
 IF OLD.kind='count' AND NEW.cutoff IS DISTINCT FROM OLD.cutoff THEN RAISE EXCEPTION 'Count cutoff is immutable'; END IF;
 IF OLD.status<>'draft' AND (NEW.destination_id,NEW.reason,NEW.cutoff) IS DISTINCT FROM (OLD.destination_id,OLD.reason,OLD.cutoff) THEN RAISE EXCEPTION 'Submitted inventory snapshot is immutable'; END IF;
 IF NEW.status<>OLD.status THEN
  IF NEW.version<>OLD.version+1 OR NOT (
    (OLD.status='draft' AND NEW.status IN ('submitted','cancelled')) OR
    (OLD.status='submitted' AND NEW.status IN ('approved','cancelled')) OR
    (OLD.status='submitted' AND OLD.kind='count' AND NEW.status='posted') OR
    (OLD.status='approved' AND NEW.status='cancelled') OR
    (OLD.status='approved' AND OLD.kind NOT IN ('count','transfer','receipt') AND NEW.status='posted') OR
    (OLD.status='approved' AND OLD.kind='transfer' AND NEW.status='dispatched') OR
    (OLD.status='dispatched' AND OLD.kind='transfer' AND NEW.status='received')
  ) THEN RAISE EXCEPTION 'Invalid inventory lifecycle transition'; END IF;
  IF NEW.status IN ('approved','posted','dispatched','received') AND (NEW.approved_by IS NULL OR (NEW.approved_by=NEW.created_by AND NOT org_allows_self_approval(NEW.approved_by,'inventory')) OR NEW.approved_at IS NULL) THEN RAISE EXCEPTION 'Separate inventory approval required'; END IF;
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION inventory_require_correction_commit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c inventory_fifo_corrections%ROWTYPE; o inventory_movements%ROWTYPE; a inventory_fifo_allocations%ROWTYPE; d inventory_documents%ROWTYPE; v numeric; q numeric;
BEGIN
 IF NEW.operation<>'correction' THEN RETURN NEW; END IF;
 SELECT * INTO c FROM inventory_fifo_corrections WHERE movement_id=NEW.id;
 SELECT * INTO o FROM inventory_movements WHERE id=NEW.reversal_id;
 SELECT * INTO d FROM inventory_documents WHERE id=o.document_id;
 IF c.movement_id IS NULL OR o.id IS NULL OR c.original_movement_id<>o.id OR o.reversal_id IS NOT NULL OR d.status<>'posted' OR d.kind NOT IN ('issue','adjustment')
 OR d.approved_by IS NULL OR (d.approved_by=d.created_by AND NOT org_allows_self_approval(d.approved_by,'inventory')) OR o.source_module<>'Inventory' OR o.operation NOT IN ('issue_post','adjustment_post') OR NEW.source_module<>'Inventory'
 OR NEW.source_id<>o.id::text OR NEW.source_line<>o.id::text OR NEW.warehouse_id<>o.warehouse_id OR NEW.product_id<>o.product_id OR NEW.quantity*o.quantity>=0
 OR abs(NEW.quantity)<>c.quantity THEN RAISE EXCEPTION 'Correction requires original approved source and exact FIFO link'; END IF;
 IF (SELECT coalesce(sum(quantity),0) FROM inventory_fifo_corrections WHERE original_movement_id=o.id)>abs(o.quantity) THEN RAISE EXCEPTION 'Correction exceeds original quantity'; END IF;
 IF NEW.quantity>0 THEN
  SELECT * INTO a FROM inventory_fifo_allocations WHERE id=c.allocation_id;
  IF a.movement_id<>o.id OR a.layer_id<>c.source_layer_id OR (SELECT coalesce(sum(quantity),0) FROM inventory_fifo_corrections WHERE allocation_id=a.id)>a.quantity
  OR (SELECT coalesce(sum(amount),0) FROM inventory_fifo_corrections WHERE allocation_id=a.id)>a.amount
  OR NOT EXISTS(SELECT 1 FROM inventory_fifo_layers f JOIN inventory_fifo_layers s ON s.id=c.source_layer_id WHERE f.movement_id=NEW.id AND f.original_quantity=c.quantity AND f.original_value=c.amount AND f.received_on=s.received_on) THEN RAISE EXCEPTION 'Correction receipt must restore original FIFO cost/date'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM inventory_fifo_layers WHERE id=c.source_layer_id AND movement_id=o.id)
  OR EXISTS(SELECT 1 FROM inventory_fifo_allocations WHERE movement_id=NEW.id AND layer_id<>c.source_layer_id) THEN RAISE EXCEPTION 'Correction must consume its original receipt layer'; END IF;
  SELECT coalesce(sum(amount),0),coalesce(sum(quantity),0) INTO v,q FROM inventory_fifo_allocations WHERE movement_id=NEW.id;
  IF v<>c.amount OR q<>c.quantity THEN RAISE EXCEPTION 'Correction consumption mismatch'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM inventory_corrections WHERE correcting_movement_id=NEW.id AND movement_id=o.id AND status='posted' AND quantity=c.quantity)
 OR NOT EXISTS(SELECT 1 FROM inventory_movement_journals x LEFT JOIN accounting_journals j ON j.id=x.journal_id WHERE x.movement_id=NEW.id AND x.amount=c.amount
 AND ((c.amount=0 AND x.journal_id IS NULL) OR (c.amount>0 AND j.status='posted' AND j.source_key='inventory-movement:'||NEW.id AND c.amount=(SELECT sum(debit) FROM accounting_journal_lines WHERE journal_id=j.id) AND c.amount=(SELECT sum(credit) FROM accounting_journal_lines WHERE journal_id=j.id)))) THEN RAISE EXCEPTION 'Correction must commit with source evidence and balanced GL'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_sales_order_review_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Sales Order review history is immutable'; END IF;
 IF OLD.status<>'pending' OR NEW.status NOT IN ('withdrawn','rejected','approved') THEN RAISE EXCEPTION 'Sales Order review decision is immutable'; END IF;
 IF ROW(NEW.sales_order_id,NEW.target_version,NEW.content_hash,NEW.snapshot,NEW.requested_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.sales_order_id,OLD.target_version,OLD.content_hash,OLD.snapshot,OLD.requested_by,OLD.created_at) THEN RAISE EXCEPTION 'Sales Order review snapshot is immutable'; END IF;
 IF NEW.decided_by IS NULL OR NEW.decided_at IS NULL OR (NEW.status='approved' AND NEW.decided_by=NEW.requested_by AND NOT org_allows_self_approval(NEW.decided_by,'sales_orders')) THEN RAISE EXCEPTION 'Independent review decision requires attribution'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION sales_require_order_commitments() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='approved' AND (NOT EXISTS(SELECT 1 FROM sales_order_issues i JOIN sales_order_reviews r ON r.id=i.review_id WHERE i.order_id=NEW.id AND r.sales_order_id=NEW.id AND r.status='approved' AND r.decided_by=i.approved_by AND (r.requested_by<>i.approved_by OR org_allows_self_approval(i.approved_by,'sales_orders')))
 OR EXISTS(SELECT 1 FROM sales_order_lines l WHERE l.sales_order_id=NEW.id AND NOT EXISTS(SELECT 1 FROM sales_order_allocations a JOIN inventory_reservations r ON r.id=a.reservation_id WHERE a.line_id=l.id AND a.order_id=NEW.id AND r.source_module='sales' AND r.source_id=NEW.id::text AND r.source_line=l.id::text AND r.product_id=l.product_id AND r.requested=a.base_quantity AND r.warehouse_id=a.warehouse_id))) THEN RAISE EXCEPTION 'Approved order requires immutable review and real Inventory commitments'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_purchase_requisition_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Review history is append only'; END IF;
 IF NEW.action IN ('approve','reject') AND NEW.actor_id = (SELECT created_by FROM purchase_requisitions WHERE id = NEW.requisition_id) AND NOT org_allows_self_approval(NEW.actor_id,'purchasing') THEN RAISE EXCEPTION 'Self approval is forbidden'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_purchasing_order_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PO decisions append only'; END IF;
 IF NEW.action IN ('approve','reject') AND NEW.actor_id=(SELECT created_by FROM purchasing_orders WHERE id=NEW.order_id) AND NOT org_allows_self_approval(NEW.actor_id,'purchasing') THEN RAISE EXCEPTION 'No self approval'; END IF;
 RETURN NEW;
END $$;

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
   SELECT coalesce(sum(coalesce(x.amount,abs(b.amount))),0) INTO line_used FROM treasury_statement_matches x JOIN treasury_statement_lines b ON b.id=x.line_id WHERE x.active AND x.line_id=l.id;
   SELECT coalesce(sum(coalesce(x.amount,abs(b.amount))),0) INTO movement_used FROM treasury_statement_matches x JOIN treasury_statement_lines b ON b.id=x.line_id WHERE x.active AND x.movement_id=m.id;
   NEW.amount:=coalesce(NEW.amount,least(abs(l.amount)-line_used,m.amount-movement_used));
   IF NEW.amount<=0 OR NEW.amount+line_used>abs(l.amount) OR NEW.amount+movement_used>m.amount THEN
    RAISE EXCEPTION 'Statement allocation exceeds remaining line or movement cents';
   END IF;
  END IF;
 ELSE
  IF NEW.version<>s.version OR (NEW.reviewer_id=s.created_by AND NOT org_allows_self_approval(NEW.reviewer_id,'treasury_ar_ap')) OR (NEW.snapshot->>'statement_id')::bigint IS DISTINCT FROM s.id
   OR (NEW.snapshot->>'version')::int IS DISTINCT FROM s.version OR (NEW.snapshot->>'file_sha256') IS DISTINCT FROM s.file_sha256 THEN
   RAISE EXCEPTION 'Independent statement review must bind the current source version';
  END IF;
 END IF;
 RETURN NEW;
END $$;

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
  OR m.movement_date<o.movement_date OR (m.executed_by=o.executed_by AND NOT org_allows_self_approval(m.executed_by,'treasury_ar_ap')) OR j.status<>'posted' OR j.branch_id<>o.branch_id OR j.journal_date<>m.movement_date
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
 IF o.kind IN ('customer_collection','supplier_payment') AND EXISTS (
  SELECT 1 FROM rp_settlement_allocations source JOIN rp_open_items i ON i.id=source.open_item_id WHERE source.movement_id=o.id AND
   i.settled_amount<>(coalesce((SELECT sum(a.amount) FROM rp_settlement_allocations a WHERE a.open_item_id=i.id AND NOT EXISTS
    (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=a.movement_id)),0)
    +coalesce((SELECT sum(l.amount) FROM rp_customer_advance_application_lines l WHERE l.open_item_id=i.id),0))
 ) THEN RAISE EXCEPTION 'Cash correction must restore exact settlement balances'; END IF;
 IF o.kind='customer_collection' AND EXISTS (
  SELECT 1 FROM rp_customer_advances a WHERE a.movement_id=o.id AND
   (EXISTS (SELECT 1 FROM rp_customer_advance_applications p WHERE p.advance_id=a.id)
   OR EXISTS (SELECT 1 FROM rp_customer_advance_refunds f WHERE f.advance_id=a.id AND NOT EXISTS (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=f.movement_id)))
 ) THEN RAISE EXCEPTION 'Consumed advance prevents original cash correction'; END IF;
 IF o.party_credit_id IS NOT NULL AND EXISTS (
  SELECT 1 FROM rp_party_credits c WHERE c.id=o.party_credit_id AND c.allocated_amount<>
   coalesce((SELECT sum(f.amount) FROM rp_party_credit_refunds f WHERE f.credit_id=c.id AND NOT EXISTS (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=f.movement_id)),0)
 ) THEN RAISE EXCEPTION 'Cash correction must restore exact party credit'; END IF;
 IF EXISTS ((SELECT account_id,sum(debit) dr,sum(credit) cr FROM accounting_journal_lines WHERE journal_id=o.journal_id GROUP BY account_id)
  EXCEPT (SELECT account_id,sum(credit),sum(debit) FROM accounting_journal_lines WHERE journal_id=m.journal_id GROUP BY account_id))
  OR EXISTS ((SELECT account_id,sum(credit) dr,sum(debit) cr FROM accounting_journal_lines WHERE journal_id=m.journal_id GROUP BY account_id)
  EXCEPT (SELECT account_id,sum(debit),sum(credit) FROM accounting_journal_lines WHERE journal_id=o.journal_id GROUP BY account_id))
 THEN RAISE EXCEPTION 'Cash correction must reverse original account cents without remapping'; END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_project_independent_decision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status IN ('approved','rejected') AND (NEW.decided_by IS NULL OR (NEW.decided_by=NEW.created_by AND NOT org_allows_self_approval(NEW.decided_by,'operations_other'))) THEN RAISE EXCEPTION 'Independent project budget decision required'; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain policy-controlled self-approval database guards.');
    }
};

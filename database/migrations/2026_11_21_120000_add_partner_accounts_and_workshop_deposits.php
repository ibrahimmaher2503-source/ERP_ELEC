<?php

use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE accounting_partners (
    id bigserial PRIMARY KEY,
    code varchar(40) NOT NULL UNIQUE,
    name varchar(200) NOT NULL,
    ownership_percent numeric(5,2) CHECK (ownership_percent IS NULL OR (ownership_percent >= 0 AND ownership_percent <= 100)),
    current_account_id bigint NOT NULL UNIQUE REFERENCES accounting_accounts(id),
    active boolean NOT NULL DEFAULT true,
    notes text,
    version integer NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE accounting_partner_movements (
    id bigserial PRIMARY KEY,
    creation_key uuid NOT NULL UNIQUE,
    partner_id bigint NOT NULL REFERENCES accounting_partners(id),
    branch_id bigint NOT NULL REFERENCES branches(id),
    kind varchar(32) NOT NULL CHECK (kind IN ('contribution', 'drawing', 'expense_paid_by_partner', 'reversal')),
    movement_date date NOT NULL,
    amount numeric(20,2) NOT NULL CHECK (amount > 0),
    reference varchar(300) NOT NULL,
    notes text,
    expense_account_id bigint REFERENCES accounting_accounts(id),
    treasury_account_id bigint REFERENCES treasury_accounts(id),
    treasury_movement_id bigint UNIQUE REFERENCES treasury_movements(id),
    journal_id bigint NOT NULL UNIQUE REFERENCES accounting_journals(id),
    official_document_id uuid REFERENCES system_official_documents(id),
    reversed_movement_id bigint UNIQUE REFERENCES accounting_partner_movements(id),
    created_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind <> 'expense_paid_by_partner') OR (expense_account_id IS NOT NULL AND treasury_movement_id IS NULL)),
    CHECK ((kind NOT IN ('contribution', 'drawing')) OR (treasury_movement_id IS NOT NULL AND treasury_account_id IS NOT NULL AND expense_account_id IS NULL)),
    CHECK ((kind <> 'reversal') OR (reversed_movement_id IS NOT NULL))
);

CREATE TRIGGER accounting_partner_movements_immutable BEFORE UPDATE OR DELETE ON accounting_partner_movements FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();

ALTER TABLE treasury_movements DROP CONSTRAINT treasury_movements_kind_check;
ALTER TABLE treasury_movements ADD CONSTRAINT treasury_movements_kind_check CHECK (
    (kind)::text = ANY ((ARRAY[
        'supplier_payment'::character varying,
        'customer_collection'::character varying,
        'transfer_out'::character varying,
        'transfer_in'::character varying,
        'customer_advance_refund'::character varying,
        'customer_credit_refund'::character varying,
        'supplier_credit_refund'::character varying,
        'cash_correction_in'::character varying,
        'cash_correction_out'::character varying,
        'partner_contribution'::character varying,
        'partner_drawing'::character varying
    ])::text[])
);

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
  signed:=CASE WHEN m.kind IN ('customer_collection','transfer_in','supplier_credit_refund','cash_correction_in','partner_contribution') THEN m.amount ELSE -m.amount END;
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
  OR m.kind<>(CASE WHEN o.kind IN ('customer_collection','transfer_in','supplier_credit_refund','partner_contribution') THEN 'cash_correction_out' ELSE 'cash_correction_in' END)
 THEN RAISE EXCEPTION 'Cash correction must reverse its exact independent source'; END IF;
 IF o.kind IN ('transfer_in','transfer_out') THEN
  SELECT * INTO paired FROM treasury_movements WHERE original_movement_id=o.paired_movement_id;
  IF paired.id IS NULL OR paired.paired_movement_id IS DISTINCT FROM m.id OR m.paired_movement_id IS DISTINCT FROM paired.id
   OR paired.journal_id<>m.journal_id OR paired.movement_date<>m.movement_date OR paired.executed_by<>m.executed_by
  THEN RAISE EXCEPTION 'Transfer corrections must preserve the atomic source pair'; END IF;
 ELSIF m.paired_movement_id IS NOT NULL OR (o.kind NOT IN ('transfer_in','transfer_out','partner_contribution','partner_drawing') AND NOT EXISTS (SELECT 1 FROM rp_cash_reversals r WHERE r.original_movement_id=o.id AND r.movement_id=m.id AND r.effective_date=m.movement_date AND r.created_by=m.executed_by)) THEN
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

ALTER TABLE rp_customer_advances ADD COLUMN workshop_job_id bigint REFERENCES workshop_jobs(id);
CREATE INDEX rp_customer_advances_workshop_job_id_idx ON rp_customer_advances (workshop_job_id) WHERE workshop_job_id IS NOT NULL;

CREATE TABLE workshop_cancellations (
    id bigserial PRIMARY KEY,
    job_id bigint NOT NULL UNIQUE REFERENCES workshop_jobs(id),
    product_id bigint REFERENCES products(id),
    fee_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK (fee_amount >= 0),
    tax_treatment varchar(20) NOT NULL DEFAULT 'exempt_0' CHECK (tax_treatment IN ('rule', 'vat_14', 'exempt_0')),
    tax_exempt_reason varchar(2000),
    tax_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK (tax_amount >= 0),
    total_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK (total_amount = fee_amount + tax_amount),
    tax_snapshot jsonb,
    reason text NOT NULL,
    customer_agreement text NOT NULL,
    cancelled_by bigint NOT NULL REFERENCES users(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    CHECK ((fee_amount = 0) OR (product_id IS NOT NULL AND tax_snapshot IS NOT NULL))
);

CREATE TRIGGER workshop_cancellations_immutable BEFORE UPDATE OR DELETE ON workshop_cancellations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();

ALTER TABLE rp_customer_invoice_lines ADD COLUMN workshop_cancellation_id bigint UNIQUE REFERENCES workshop_cancellations(id);
ALTER TABLE rp_customer_invoice_lines DROP CONSTRAINT rp_customer_source_kind;
ALTER TABLE rp_customer_invoice_lines ADD CONSTRAINT rp_customer_source_kind CHECK (
    ((sales_order_line_id IS NULL) AND (delivery_line_id IS NULL) AND (num_nonnulls(maintenance_approval_id, workshop_approval_id, workshop_cancellation_id, service_contract_source_id) = 1))
    OR ((sales_order_line_id IS NOT NULL) AND (delivery_line_id IS NOT NULL) AND (num_nonnulls(maintenance_approval_id, workshop_approval_id, workshop_cancellation_id, service_contract_source_id) = 0))
);
SQL);

        foreach ([
            'M11.partner_view' => ['عرض حسابات جاري الشركاء وكشوفها', 'company', 'M11'],
            'M11.partner_manage' => ['إدارة الشركاء وتسجيل وعكس حركات جاري الشركاء', 'branch', 'M11'],
            'M13.partner_cash' => ['تنفيذ إيداعات ومسحوبات الشركاء النقدية والبنكية', 'branch', 'M13'],
        ] as $code => [$label, $scope, $module]) {
            DB::table('permission_catalog')->insertOrIgnore([
                'code' => $code,
                'label' => $label,
                'scope' => $scope,
                'module' => $module,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Retain partner accounts and workshop cancellation/deposit history.');
    }
};

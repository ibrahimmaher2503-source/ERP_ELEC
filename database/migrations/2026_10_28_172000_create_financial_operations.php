<?php

use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ DECLARE c record;
BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='accounting_journals'::regclass AND contype='c' AND pg_get_constraintdef(oid) ILIKE '%approved_by%created_by%' LOOP
  EXECUTE format('ALTER TABLE accounting_journals DROP CONSTRAINT %I',c.conname);
 END LOOP;
END $$;
ALTER TABLE accounting_journals ADD CONSTRAINT accounting_manual_independent_approval
 CHECK(source_key IS NOT NULL OR approved_by IS NULL OR approved_by<>created_by);

CREATE TABLE rp_supplier_invoices (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id),
 supplier_id bigint NOT NULL REFERENCES suppliers(id), supplier_name varchar(255) NOT NULL,
 supplier_invoice_number varchar(100) NOT NULL, invoice_date date NOT NULL, due_date date NOT NULL,
 currency char(3) NOT NULL DEFAULT 'EGP' CHECK(currency='EGP'), tax_mode varchar(10) NOT NULL CHECK(tax_mode IN ('inclusive','exclusive')),
 status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','validated','matched','approved','posted','partially_paid','paid','credited')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), net_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(net_amount>=0),
 tax_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(tax_amount>=0), total_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(total_amount>=0),
 grni_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(grni_amount>=0), ppv_amount numeric(20,2) NOT NULL DEFAULT 0,
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz,
 posted_by bigint REFERENCES users(id), posted_at timestamptz, journal_id bigint UNIQUE REFERENCES accounting_journals(id),
 official_document_id uuid UNIQUE REFERENCES system_official_documents(id), official_number varchar(80) UNIQUE,
 source_snapshot jsonb, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(supplier_id,supplier_invoice_number), CHECK(due_date>=invoice_date), CHECK(total_amount=net_amount+tax_amount),
 CHECK((status IN ('posted','partially_paid','paid','credited'))=(posted_at IS NOT NULL AND journal_id IS NOT NULL AND official_document_id IS NOT NULL AND official_number IS NOT NULL))
);
CREATE INDEX rp_supplier_invoice_register ON rp_supplier_invoices(branch_id,invoice_date,status);
CREATE TABLE rp_supplier_invoice_lines (
 id bigserial PRIMARY KEY, invoice_id bigint NOT NULL REFERENCES rp_supplier_invoices(id), order_line_id bigint NOT NULL REFERENCES purchasing_order_lines(id),
 product_id bigint NOT NULL REFERENCES products(id), description varchar(500) NOT NULL, quantity numeric(24,8) NOT NULL CHECK(quantity>0),
 unit_price numeric(24,8) NOT NULL CHECK(unit_price>=0), discount_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(discount_amount>=0),
 net_amount numeric(20,2) NOT NULL CHECK(net_amount>=0), tax_amount numeric(20,2) NOT NULL CHECK(tax_amount>=0), total_amount numeric(20,2) NOT NULL CHECK(total_amount=net_amount+tax_amount),
 tax_rule_id bigint NOT NULL REFERENCES accounting_tax_rules(id), tax_snapshot jsonb NOT NULL, purchase_recoverable boolean NOT NULL,
 UNIQUE(invoice_id,order_line_id)
);
CREATE TABLE rp_supplier_invoice_matches (
 id bigserial PRIMARY KEY, invoice_line_id bigint NOT NULL REFERENCES rp_supplier_invoice_lines(id), receipt_line_id bigint NOT NULL REFERENCES purchasing_receipt_lines(id),
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), grni_amount numeric(20,2) NOT NULL CHECK(grni_amount>=0), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(invoice_line_id,receipt_line_id)
);
CREATE INDEX rp_supplier_match_receipt ON rp_supplier_invoice_matches(receipt_line_id);

CREATE TABLE rp_customer_invoices (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id), customer_id bigint NOT NULL REFERENCES customers(id),
 customer_name varchar(255) NOT NULL, invoice_date date NOT NULL, due_date date NOT NULL, currency char(3) NOT NULL DEFAULT 'EGP' CHECK(currency='EGP'),
 status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','validated','issued','posted','partially_collected','collected','credited')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), net_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(net_amount>=0), tax_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(tax_amount>=0),
 total_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(total_amount=net_amount+tax_amount), created_by bigint NOT NULL REFERENCES users(id), posted_by bigint REFERENCES users(id),
 posted_at timestamptz, journal_id bigint UNIQUE REFERENCES accounting_journals(id), official_document_id uuid UNIQUE REFERENCES system_official_documents(id), official_number varchar(80) UNIQUE,
 source_snapshot jsonb, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), CHECK(due_date>=invoice_date),
 CHECK((status IN ('posted','partially_collected','collected','credited'))=(posted_at IS NOT NULL AND journal_id IS NOT NULL AND official_document_id IS NOT NULL AND official_number IS NOT NULL))
);
CREATE INDEX rp_customer_invoice_register ON rp_customer_invoices(branch_id,invoice_date,status);
CREATE TABLE rp_customer_invoice_lines (
 id bigserial PRIMARY KEY, invoice_id bigint NOT NULL REFERENCES rp_customer_invoices(id), sales_order_line_id bigint NOT NULL REFERENCES sales_order_lines(id),
 delivery_line_id bigint NOT NULL REFERENCES sales_delivery_instruction_lines(id), product_id bigint NOT NULL REFERENCES products(id), description varchar(500) NOT NULL,
 quantity numeric(24,8) NOT NULL CHECK(quantity>0), net_amount numeric(20,2) NOT NULL CHECK(net_amount>=0), tax_amount numeric(20,2) NOT NULL CHECK(tax_amount>=0),
 total_amount numeric(20,2) NOT NULL CHECK(total_amount=net_amount+tax_amount), tax_rule_id bigint NOT NULL REFERENCES accounting_tax_rules(id), tax_snapshot jsonb NOT NULL,
 UNIQUE(invoice_id,delivery_line_id)
);
CREATE INDEX rp_customer_billing_source ON rp_customer_invoice_lines(delivery_line_id);

CREATE TABLE rp_open_items (
 id bigserial PRIMARY KEY, branch_id bigint NOT NULL REFERENCES branches(id), party_type varchar(10) NOT NULL CHECK(party_type IN ('supplier','customer')),
 party_id bigint NOT NULL, source_type varchar(30) NOT NULL CHECK(source_type IN ('supplier_invoice','customer_invoice','opening_payable')),
 source_id bigint NOT NULL, source_number varchar(100) NOT NULL, document_date date NOT NULL, due_date date NOT NULL,
 original_amount numeric(20,2) NOT NULL CHECK(original_amount>0), settled_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(settled_amount>=0),
 credited_amount numeric(20,2) NOT NULL DEFAULT 0 CHECK(credited_amount>=0), remaining_amount numeric(20,2) GENERATED ALWAYS AS (original_amount-settled_amount-credited_amount) STORED,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(source_type,source_id), CHECK(settled_amount+credited_amount<=original_amount)
);
CREATE INDEX rp_open_item_aging ON rp_open_items(party_type,party_id,due_date) WHERE remaining_amount>0;

CREATE TABLE rp_credit_notes (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id), kind varchar(10) NOT NULL CHECK(kind IN ('supplier','customer')),
 original_invoice_id bigint NOT NULL, party_id bigint NOT NULL, note_date date NOT NULL, reason text NOT NULL, amount numeric(20,2) NOT NULL CHECK(amount>0),
 net_amount numeric(20,2) NOT NULL CHECK(net_amount>=0), tax_amount numeric(20,2) NOT NULL CHECK(tax_amount>=0), status varchar(15) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','posted')),
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz, posted_by bigint REFERENCES users(id), posted_at timestamptz,
 journal_id bigint UNIQUE REFERENCES accounting_journals(id), official_document_id uuid UNIQUE REFERENCES system_official_documents(id), official_number varchar(80) UNIQUE,
 source_snapshot jsonb, created_at timestamptz NOT NULL DEFAULT now(), CHECK(amount=net_amount+tax_amount), CHECK(approved_by IS NULL OR approved_by<>created_by),
 CHECK((status='posted')=(posted_at IS NOT NULL AND journal_id IS NOT NULL AND official_document_id IS NOT NULL AND official_number IS NOT NULL))
);
CREATE INDEX rp_credit_original ON rp_credit_notes(kind,original_invoice_id);

CREATE TABLE treasury_accounts (
 id bigserial PRIMARY KEY, code varchar(40) NOT NULL UNIQUE, name varchar(200) NOT NULL, kind varchar(10) NOT NULL CHECK(kind IN ('bank','cash')),
 gl_account_id bigint NOT NULL REFERENCES accounting_accounts(id), active boolean NOT NULL DEFAULT true, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX treasury_one_operational_kind ON treasury_accounts(kind) WHERE active;
CREATE TABLE treasury_payment_requests (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id), supplier_id bigint NOT NULL REFERENCES suppliers(id),
 account_id bigint NOT NULL REFERENCES treasury_accounts(id), request_date date NOT NULL, amount numeric(20,2) NOT NULL CHECK(amount>0), reference varchar(200) NOT NULL,
 status varchar(15) NOT NULL DEFAULT 'prepared' CHECK(status IN ('prepared','approved','executed','cancelled')), created_by bigint NOT NULL REFERENCES users(id),
 approved_by bigint REFERENCES users(id), approved_at timestamptz, executed_by bigint REFERENCES users(id), executed_at timestamptz, version integer NOT NULL DEFAULT 1 CHECK(version>0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), CHECK(approved_by IS NULL OR approved_by<>created_by),
 CHECK((status IN ('approved','executed'))=(approved_by IS NOT NULL AND approved_at IS NOT NULL)), CHECK((status='executed')=(executed_at IS NOT NULL AND executed_by IS NOT NULL))
);
CREATE TABLE treasury_movements (
 id bigserial PRIMARY KEY, idempotency_key uuid NOT NULL UNIQUE, branch_id bigint NOT NULL REFERENCES branches(id), account_id bigint NOT NULL REFERENCES treasury_accounts(id),
 kind varchar(20) NOT NULL CHECK(kind IN ('supplier_payment','customer_collection','transfer_out','transfer_in')), movement_date date NOT NULL,
 party_id bigint, amount numeric(20,2) NOT NULL CHECK(amount>0), reference varchar(200) NOT NULL, paired_movement_id bigint UNIQUE REFERENCES treasury_movements(id),
 payment_request_id bigint UNIQUE REFERENCES treasury_payment_requests(id), journal_id bigint UNIQUE NOT NULL REFERENCES accounting_journals(id), official_document_id uuid UNIQUE REFERENCES system_official_documents(id),
 executed_by bigint NOT NULL REFERENCES users(id), executed_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX treasury_movement_register ON treasury_movements(account_id,movement_date);
CREATE TABLE rp_settlement_allocations (
 id bigserial PRIMARY KEY, movement_id bigint NOT NULL REFERENCES treasury_movements(id), open_item_id bigint NOT NULL REFERENCES rp_open_items(id),
 amount numeric(20,2) NOT NULL CHECK(amount>0), created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(movement_id,open_item_id)
);

CREATE TRIGGER rp_supplier_line_immutable BEFORE UPDATE OR DELETE ON rp_supplier_invoice_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER rp_supplier_match_immutable BEFORE UPDATE OR DELETE ON rp_supplier_invoice_matches FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER rp_customer_line_immutable BEFORE UPDATE OR DELETE ON rp_customer_invoice_lines FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER rp_open_item_no_delete BEFORE DELETE ON rp_open_items FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER rp_credit_no_delete BEFORE DELETE ON rp_credit_notes FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER treasury_movement_immutable BEFORE UPDATE OR DELETE ON treasury_movements FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER rp_allocation_immutable BEFORE UPDATE OR DELETE ON rp_settlement_allocations FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();

CREATE OR REPLACE FUNCTION rp_guard_posted_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Financial invoice history cannot be deleted'; END IF;
 IF OLD.status IN ('posted','partially_paid','paid','partially_collected','collected','credited') THEN RAISE EXCEPTION 'Posted invoice is immutable'; END IF;
 IF ROW(NEW.creation_key,NEW.branch_id,NEW.created_by) IS DISTINCT FROM ROW(OLD.creation_key,OLD.branch_id,OLD.created_by) THEN RAISE EXCEPTION 'Invoice identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER rp_supplier_invoice_guard BEFORE UPDATE OR DELETE ON rp_supplier_invoices FOR EACH ROW EXECUTE FUNCTION rp_guard_posted_invoice();
CREATE TRIGGER rp_customer_invoice_guard BEFORE UPDATE OR DELETE ON rp_customer_invoices FOR EACH ROW EXECUTE FUNCTION rp_guard_posted_invoice();
SQL);

        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M11.') || str_starts_with($code, 'M12.') || str_starts_with($code, 'M13.')) {
                $module = str_starts_with($code, 'M11.') ? 'M11 · المحاسبة' : (str_starts_with($code, 'M12.') ? 'M12 · الذمم المدينة والدائنة' : 'M13 · الخزينة');
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => $module, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Financial operations history requires forward migrations.');
    }
};

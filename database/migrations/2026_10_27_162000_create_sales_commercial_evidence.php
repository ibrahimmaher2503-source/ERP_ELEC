<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE sales_quotations DROP CONSTRAINT sales_quote_state;
ALTER TABLE sales_quotations ADD CONSTRAINT sales_quote_state CHECK(status IN ('draft','pending_approval','cancelled','rejected','expired','issued','sent','accepted','converted'));
CREATE TABLE sales_quotation_issues (
 id bigserial PRIMARY KEY, quotation_id bigint NOT NULL UNIQUE REFERENCES sales_quotations(id), source_version integer NOT NULL,
 document_id uuid NOT NULL UNIQUE REFERENCES system_official_documents(id), tax_snapshot jsonb NOT NULL, commercial_snapshot jsonb NOT NULL,
 issued_by bigint NOT NULL REFERENCES users(id), issued_at timestamptz NOT NULL
);
CREATE TABLE sales_quotation_acceptances (
 id bigserial PRIMARY KEY, quotation_id bigint NOT NULL UNIQUE REFERENCES sales_quotations(id), issue_id bigint NOT NULL UNIQUE REFERENCES sales_quotation_issues(id),
 accepted_on date NOT NULL, evidence_type varchar(20) NOT NULL CHECK(evidence_type IN ('purchase_order','email','signed_acceptance')),
 reference varchar(500) NOT NULL, evidence_name varchar(200) NOT NULL, evidence_mime varchar(100) NOT NULL, evidence_base64 text NOT NULL,
 sha256 char(64) NOT NULL, recorded_by bigint NOT NULL REFERENCES users(id), recorded_at timestamptz NOT NULL
);
CREATE TABLE sales_quotation_conversions (
 quotation_id bigint PRIMARY KEY REFERENCES sales_quotations(id), acceptance_id bigint NOT NULL UNIQUE REFERENCES sales_quotation_acceptances(id),
 order_id bigint NOT NULL UNIQUE REFERENCES sales_orders(id), creation_key uuid NOT NULL UNIQUE, converted_by bigint NOT NULL REFERENCES users(id), converted_at timestamptz NOT NULL
);
CREATE TRIGGER sales_quotation_issue_immutable BEFORE UPDATE OR DELETE ON sales_quotation_issues FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER sales_quotation_acceptance_immutable BEFORE UPDATE OR DELETE ON sales_quotation_acceptances FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TRIGGER sales_quotation_conversion_immutable BEFORE UPDATE OR DELETE ON sales_quotation_conversions FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE OR REPLACE FUNCTION sales_guard_issued_quote() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' AND EXISTS(SELECT 1 FROM sales_quotation_issues WHERE quotation_id=OLD.id) THEN RAISE EXCEPTION 'Issued quotation immutable'; END IF;
 IF TG_OP='UPDATE' AND EXISTS(SELECT 1 FROM sales_quotation_issues WHERE quotation_id=OLD.id)
 AND (to_jsonb(NEW)-ARRAY['status','version','updated_by','updated_at','number']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','version','updated_by','updated_at','number']) THEN RAISE EXCEPTION 'Issued quotation commercial content immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER sales_issued_quote_guard BEFORE UPDATE OR DELETE ON sales_quotations FOR EACH ROW EXECUTE FUNCTION sales_guard_issued_quote();
CREATE OR REPLACE FUNCTION sales_guard_issued_quote_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM sales_quotation_issues WHERE quotation_id=CASE WHEN TG_OP='DELETE' THEN OLD.quotation_id ELSE NEW.quotation_id END)
 OR (TG_OP='UPDATE' AND EXISTS(SELECT 1 FROM sales_quotation_issues WHERE quotation_id=OLD.quotation_id)) THEN RAISE EXCEPTION 'Issued quotation lines immutable'; END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER sales_issued_quote_line_guard BEFORE INSERT OR UPDATE OR DELETE ON sales_quotation_lines FOR EACH ROW EXECUTE FUNCTION sales_guard_issued_quote_line();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Commercial evidence requires forward migrations.');
    }
};

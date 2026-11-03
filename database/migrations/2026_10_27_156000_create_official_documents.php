<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE system_official_sequences (
 company_id bigint NOT NULL REFERENCES companies(id), document_type varchar(30) NOT NULL, year smallint NOT NULL,
 last_number bigint NOT NULL CHECK(last_number>0), PRIMARY KEY(company_id,document_type,year)
);
CREATE TABLE system_official_documents (
 id uuid PRIMARY KEY, company_id bigint NOT NULL REFERENCES companies(id), document_type varchar(30) NOT NULL,
 source_id varchar(100) NOT NULL, revision integer NOT NULL CHECK(revision>0), document_date date NOT NULL,
 official_number varchar(100) NOT NULL UNIQUE, snapshot jsonb NOT NULL,
 pdf_base64 text NOT NULL, sha256 char(64) NOT NULL, issued_by bigint NOT NULL REFERENCES users(id),
 issued_at timestamptz NOT NULL DEFAULT now(), UNIQUE(company_id,document_type,source_id,revision),
 CHECK(length(pdf_base64)>100 AND length(pdf_base64)<20000000)
);
CREATE TRIGGER system_official_document_immutable BEFORE UPDATE OR DELETE ON system_official_documents FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
ALTER TABLE accounting_journals ADD CONSTRAINT accounting_official_document_fk FOREIGN KEY(official_document_id) REFERENCES system_official_documents(id);
SQL);
    }

    public function down(): void {}
};

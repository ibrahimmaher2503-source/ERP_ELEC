<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE project_documents (id bigserial PRIMARY KEY, project_id bigint NOT NULL REFERENCES projects(id), number varchar(80) NOT NULL, title varchar(200) NOT NULL, classification varchar(100) NOT NULL, required boolean NOT NULL DEFAULT false, UNIQUE(project_id,number));
CREATE TABLE project_document_revisions (id bigserial PRIMARY KEY, document_id bigint NOT NULL REFERENCES project_documents(id), revision integer NOT NULL CHECK(revision>0), creation_key uuid NOT NULL UNIQUE, status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','review','approved','rejected')), path text NOT NULL, original_name varchar(200) NOT NULL, mime varchar(100) NOT NULL, sha256 char(64) NOT NULL, size bigint NOT NULL CHECK(size>0), uploaded_by bigint NOT NULL REFERENCES users(id), uploaded_at timestamptz NOT NULL, decided_by bigint REFERENCES users(id), decided_at timestamptz, decision_reason text, notes text NOT NULL DEFAULT '', version integer NOT NULL DEFAULT 1, UNIQUE(document_id,revision));
CREATE OR REPLACE FUNCTION protect_project_revision() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Document history cannot be deleted'; END IF; IF OLD.status IN ('approved','rejected') OR NEW.document_id<>OLD.document_id OR NEW.revision<>OLD.revision OR NEW.path<>OLD.path OR NEW.sha256<>OLD.sha256 OR NEW.uploaded_by<>OLD.uploaded_by OR NEW.creation_key<>OLD.creation_key THEN RAISE EXCEPTION 'Revision contents are immutable; upload a new revision'; END IF; RETURN NEW; END $$;
CREATE TRIGGER project_revision_immutable BEFORE UPDATE OR DELETE ON project_document_revisions FOR EACH ROW EXECUTE FUNCTION protect_project_revision();
SQL);
    }

    public function down(): void {}
};

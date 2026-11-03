<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE project_documents ADD COLUMN task_id bigint REFERENCES project_tasks(id);
CREATE INDEX project_documents_task ON project_documents(task_id) WHERE task_id IS NOT NULL;
CREATE OR REPLACE FUNCTION guard_project_document_identity() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Project document identity is immutable; use revisions'; END IF;
 IF EXISTS(SELECT 1 FROM projects WHERE id=NEW.project_id AND status IN ('closed','cancelled')) THEN RAISE EXCEPTION 'Closed project documents are read only'; END IF;
 IF NEW.task_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM project_tasks WHERE id=NEW.task_id AND project_id=NEW.project_id) THEN RAISE EXCEPTION 'Document task must belong to the same project'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER project_document_identity BEFORE INSERT OR UPDATE OR DELETE ON project_documents FOR EACH ROW EXECUTE FUNCTION guard_project_document_identity();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Project document evidence requires forward migrations.');
    }
};

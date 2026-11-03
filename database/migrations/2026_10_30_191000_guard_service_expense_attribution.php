<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE accounting_service_expense_attributions (
 journal_id bigint PRIMARY KEY REFERENCES accounting_journals, owner varchar(30) NOT NULL CHECK(owner IN('FieldMaintenance','WorkshopRepairs')),
 source_id bigint NOT NULL, created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now()
);
INSERT INTO accounting_service_expense_attributions(journal_id,owner,source_id,created_by)
 SELECT journal_id,'FieldMaintenance',work_order_id,created_by FROM maintenance_expense_sources;
INSERT INTO accounting_service_expense_attributions(journal_id,owner,source_id,created_by)
 SELECT journal_id,'WorkshopRepairs',job_id,created_by FROM workshop_expense_sources;
CREATE TRIGGER accounting_service_expense_immutable BEFORE UPDATE OR DELETE ON accounting_service_expense_attributions FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
ALTER TABLE workshop_customer_parts ADD COLUMN source_id bigint REFERENCES workshop_customer_parts;
ALTER TABLE workshop_customer_parts ADD CONSTRAINT workshop_customer_part_source CHECK((disposition='received')=(source_id IS NULL));
CREATE OR REPLACE FUNCTION workshop_expense_attribution_commit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_TABLE_NAME='workshop_expense_sources' THEN
  IF NOT EXISTS(SELECT 1 FROM accounting_service_expense_attributions WHERE journal_id=NEW.journal_id AND owner='WorkshopRepairs' AND source_id=NEW.job_id) THEN RAISE EXCEPTION 'Workshop expense requires exclusive GL attribution'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM accounting_service_expense_attributions WHERE journal_id=NEW.journal_id AND owner='FieldMaintenance' AND source_id=NEW.work_order_id) THEN RAISE EXCEPTION 'Maintenance expense requires exclusive GL attribution'; END IF;
 END IF;
 RETURN NEW; END $$;
CREATE CONSTRAINT TRIGGER workshop_expense_attribution AFTER INSERT ON workshop_expense_sources DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_expense_attribution_commit();
CREATE CONSTRAINT TRIGGER maintenance_expense_attribution AFTER INSERT ON maintenance_expense_sources DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION workshop_expense_attribution_commit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Expense source attribution requires forward migrations.');
    }
};

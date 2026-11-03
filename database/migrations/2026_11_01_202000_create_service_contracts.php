<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE service_contracts (
 id bigserial PRIMARY KEY, creation_key uuid NOT NULL UNIQUE,
 branch_id bigint NOT NULL REFERENCES branches(id), customer_id bigint NOT NULL REFERENCES customers(id),
 responsible_employee_id bigint NOT NULL REFERENCES organization_employees(id),
 renewed_from_id bigint REFERENCES service_contracts(id), current_revision_id bigint, active_revision_id bigint,
 status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','approved','signed','active','suspended','terminated','completed')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), created_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE service_contract_revisions (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision integer NOT NULL CHECK(revision>0),
 creation_key uuid NOT NULL UNIQUE, data jsonb NOT NULL CHECK(jsonb_typeof(data)='object'),
 status varchar(20) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','submitted','approved','signed','active','superseded')),
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id),
 signed_by bigint REFERENCES users(id), signed_on date, signature_document_id bigint,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(contract_id,revision), UNIQUE(contract_id,id),
 CHECK(approved_by IS NULL OR approved_by<>created_by),
 CHECK((signed_by IS NULL AND signed_on IS NULL AND signature_document_id IS NULL) OR (signed_by IS NOT NULL AND signed_on IS NOT NULL AND signature_document_id IS NOT NULL))
);
ALTER TABLE service_contracts ADD CONSTRAINT service_contract_current_revision_fk FOREIGN KEY(id,current_revision_id) REFERENCES service_contract_revisions(contract_id,id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE service_contracts ADD CONSTRAINT service_contract_active_revision_fk FOREIGN KEY(id,active_revision_id) REFERENCES service_contract_revisions(contract_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE service_contract_documents (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL,
 classification varchar(20) NOT NULL CHECK(classification IN ('signature','agreement','supporting')),
 reference varchar(160) NOT NULL, path varchar(500) NOT NULL UNIQUE, original_name varchar(200) NOT NULL,
 mime varchar(100) NOT NULL, sha256 char(64) NOT NULL, terms_sha256 char(64) NOT NULL, uploaded_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(contract_id,revision_id,id),
 FOREIGN KEY(contract_id,revision_id) REFERENCES service_contract_revisions(contract_id,id)
);
ALTER TABLE service_contract_revisions ADD CONSTRAINT service_contract_signature_fk FOREIGN KEY(contract_id,id,signature_document_id) REFERENCES service_contract_documents(contract_id,revision_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE service_contract_activities (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL,
 actor_id bigint NOT NULL REFERENCES users(id), action varchar(80) NOT NULL, details jsonb NOT NULL,
 occurred_at timestamptz NOT NULL DEFAULT now(), FOREIGN KEY(contract_id,revision_id) REFERENCES service_contract_revisions(contract_id,id)
);
CREATE INDEX service_contract_register ON service_contracts(branch_id,status,id);
CREATE OR REPLACE FUNCTION service_contract_guard_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR TG_TABLE_NAME IN ('service_contract_activities','service_contract_documents') THEN RAISE EXCEPTION 'Service contract history is append-only'; END IF;
 IF TG_TABLE_NAME='service_contracts' THEN
  IF ROW(NEW.creation_key,NEW.branch_id,NEW.customer_id,NEW.renewed_from_id,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.creation_key,OLD.branch_id,OLD.customer_id,OLD.renewed_from_id,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Contract identity is immutable'; END IF;
 ELSE
  IF ROW(NEW.contract_id,NEW.revision,NEW.creation_key,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.contract_id,OLD.revision,OLD.creation_key,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Revision identity is immutable'; END IF;
  IF OLD.status<>'draft' AND NEW.data IS DISTINCT FROM OLD.data THEN RAISE EXCEPTION 'Submitted contract terms are immutable'; END IF;
  IF OLD.approved_by IS NOT NULL AND NEW.approved_by IS DISTINCT FROM OLD.approved_by THEN RAISE EXCEPTION 'Approval history is immutable'; END IF;
  IF OLD.signed_by IS NOT NULL AND ROW(NEW.signed_by,NEW.signed_on,NEW.signature_document_id) IS DISTINCT FROM ROW(OLD.signed_by,OLD.signed_on,OLD.signature_document_id) THEN RAISE EXCEPTION 'Signature history is immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER service_contract_history BEFORE UPDATE OR DELETE ON service_contracts FOR EACH ROW EXECUTE FUNCTION service_contract_guard_history();
CREATE TRIGGER service_contract_revision_history BEFORE UPDATE OR DELETE ON service_contract_revisions FOR EACH ROW EXECUTE FUNCTION service_contract_guard_history();
CREATE TRIGGER service_contract_document_history BEFORE UPDATE OR DELETE ON service_contract_documents FOR EACH ROW EXECUTE FUNCTION service_contract_guard_history();
CREATE TRIGGER service_contract_activity_history BEFORE UPDATE OR DELETE ON service_contract_activities FOR EACH ROW EXECUTE FUNCTION service_contract_guard_history();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $key => [$label, $scope]) {
            if (str_starts_with($key, 'M10.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $key], ['label' => $label, 'scope' => $scope, 'module' => 'M10 · عقود الخدمة', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Service contract evidence must be retained; use forward migrations.');
    }
};

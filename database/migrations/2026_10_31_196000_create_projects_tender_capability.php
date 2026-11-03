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
CREATE TABLE project_tenders (
 id bigserial PRIMARY KEY, number varchar(60) NOT NULL UNIQUE, creation_key uuid NOT NULL UNIQUE,
 creation_hash char(64) NOT NULL, identity_hash char(64) NOT NULL UNIQUE,
 external_reference varchar(150) NOT NULL, title varchar(250) NOT NULL, description text NOT NULL DEFAULT '',
 issuer varchar(250) NOT NULL, customer_id bigint REFERENCES customers, customer_name varchar(250),
 category varchar(20) NOT NULL CHECK(category IN('supply','project','service','mixed')),
 branch_id bigint NOT NULL REFERENCES branches, responsible_id bigint NOT NULL REFERENCES users,
 published_on date NOT NULL, deadlines jsonb NOT NULL, requirements text NOT NULL DEFAULT '',
 status varchar(40) NOT NULL DEFAULT 'identified', version integer NOT NULL DEFAULT 1,
 technical_id bigint, financial_id bigint, submitted_id bigint,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_tenders_branch_status ON project_tenders(branch_id,status);
CREATE INDEX project_tenders_customer ON project_tenders(customer_id);
CREATE TABLE project_tender_documents (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, creation_key uuid NOT NULL UNIQUE,
 title varchar(200) NOT NULL, classification varchar(30) NOT NULL, path text NOT NULL UNIQUE,
 original_name varchar(200) NOT NULL, mime varchar(100) NOT NULL, sha256 char(64) NOT NULL, size bigint NOT NULL,
 uploaded_by bigint NOT NULL REFERENCES users, uploaded_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_tender_documents_tender ON project_tender_documents(tender_id);
CREATE TABLE project_tender_technical (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, revision integer NOT NULL,
 snapshot jsonb NOT NULL, sha256 char(64) NOT NULL, prepared_by bigint NOT NULL REFERENCES users,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tender_id,revision)
);
CREATE TABLE project_tender_financial (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders,
 technical_id bigint NOT NULL REFERENCES project_tender_technical, revision integer NOT NULL,
 snapshot jsonb NOT NULL, sha256 char(64) NOT NULL, cost numeric(24,8) NOT NULL CHECK(cost>=0),
 net numeric(20,2) NOT NULL CHECK(net>=0), tax numeric(20,2) NOT NULL CHECK(tax>=0), total numeric(20,2) NOT NULL CHECK(total>=0),
 prepared_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tender_id,revision)
);
CREATE TABLE project_tender_approvals (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, kind varchar(20) NOT NULL CHECK(kind IN('technical','financial')),
 technical_id bigint REFERENCES project_tender_technical, financial_id bigint REFERENCES project_tender_financial,
 approved_by bigint NOT NULL REFERENCES users, reference text NOT NULL, approved_at timestamptz NOT NULL DEFAULT now(),
 CHECK((kind='technical' AND technical_id IS NOT NULL AND financial_id IS NULL) OR (kind='financial' AND financial_id IS NOT NULL AND technical_id IS NULL))
);
CREATE UNIQUE INDEX project_tender_technical_approved ON project_tender_approvals(technical_id) WHERE kind='technical';
CREATE UNIQUE INDEX project_tender_financial_approved ON project_tender_approvals(financial_id) WHERE kind='financial';
CREATE TABLE project_tender_bid_documents (
 financial_id bigint PRIMARY KEY REFERENCES project_tender_financial, document_id uuid NOT NULL UNIQUE REFERENCES system_official_documents,
 issued_by bigint NOT NULL REFERENCES users, issued_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE project_tender_submissions (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, creation_key uuid NOT NULL UNIQUE,
 financial_id bigint NOT NULL REFERENCES project_tender_financial, technical_id bigint NOT NULL REFERENCES project_tender_technical,
 document_id uuid NOT NULL REFERENCES system_official_documents, evidence_id bigint NOT NULL REFERENCES project_tender_documents,
 method varchar(20) NOT NULL CHECK(method IN('physical','email','portal')), reference text NOT NULL,
 submitted_at timestamptz NOT NULL, submitted_by bigint NOT NULL REFERENCES users, snapshot jsonb NOT NULL, payload_hash char(64) NOT NULL,
 UNIQUE(tender_id,financial_id)
);
CREATE TABLE project_tender_results (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, submission_id bigint NOT NULL REFERENCES project_tender_submissions,
 outcome varchar(20) NOT NULL CHECK(outcome IN('awarded','lost','cancelled','negotiating')),
 decided_on date NOT NULL, award_amount numeric(20,2), reference text NOT NULL,
 evidence_id bigint NOT NULL REFERENCES project_tender_documents, recorded_by bigint NOT NULL REFERENCES users, recorded_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_tender_results_tender ON project_tender_results(tender_id,id);
CREATE TABLE project_tender_events (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, action varchar(100) NOT NULL,
 actor_id bigint NOT NULL REFERENCES users, snapshot jsonb NOT NULL, occurred_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX project_tender_events_tender ON project_tender_events(tender_id,id);
CREATE TABLE project_tender_guarantees (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, reference varchar(200) NOT NULL,
 kind varchar(20) NOT NULL CHECK(kind IN('fee','bid_bond','performance')), guarantee_type varchar(100) NOT NULL,
 bank varchar(200) NOT NULL DEFAULT '', amount numeric(20,2) NOT NULL CHECK(amount>0), issued_on date NOT NULL, expires_on date,
 evidence_id bigint NOT NULL REFERENCES project_tender_documents, recorded_by bigint NOT NULL REFERENCES users,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tender_id,kind,reference), CHECK(expires_on IS NULL OR expires_on>=issued_on)
);
CREATE TABLE project_tender_guarantee_events (
 id bigserial PRIMARY KEY, guarantee_id bigint NOT NULL REFERENCES project_tender_guarantees,
 status varchar(20) NOT NULL CHECK(status IN('released','returned','forfeited')), reference text NOT NULL,
 evidence_id bigint NOT NULL REFERENCES project_tender_documents, recorded_by bigint NOT NULL REFERENCES users, recorded_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE project_tender_handoff_plans (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, result_id bigint NOT NULL REFERENCES project_tender_results,
 snapshot jsonb NOT NULL, proposed_by bigint NOT NULL REFERENCES users, proposed_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE project_tender_handoff_approvals (
 plan_id bigint PRIMARY KEY REFERENCES project_tender_handoff_plans, approved_by bigint NOT NULL REFERENCES users,
 reference text NOT NULL, approved_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE project_tender_handoffs (
 id bigserial PRIMARY KEY, tender_id bigint NOT NULL REFERENCES project_tenders, plan_id bigint NOT NULL REFERENCES project_tender_handoff_plans,
 destination varchar(20) NOT NULL CHECK(destination IN('project','quotation')), target_id bigint NOT NULL, source_snapshot jsonb NOT NULL,
 created_by bigint NOT NULL REFERENCES users, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tender_id,destination), UNIQUE(destination,target_id)
);
ALTER TABLE project_tenders ADD FOREIGN KEY(technical_id) REFERENCES project_tender_technical;
ALTER TABLE project_tenders ADD FOREIGN KEY(financial_id) REFERENCES project_tender_financial;
ALTER TABLE project_tenders ADD FOREIGN KEY(submitted_id) REFERENCES project_tender_submissions;
CREATE FUNCTION project_tender_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR ROW(NEW.creation_key,NEW.creation_hash,NEW.identity_hash,NEW.number,NEW.branch_id,NEW.customer_id,NEW.external_reference,NEW.issuer,NEW.created_by)
 IS DISTINCT FROM ROW(OLD.creation_key,OLD.creation_hash,OLD.identity_hash,OLD.number,OLD.branch_id,OLD.customer_id,OLD.external_reference,OLD.issuer,OLD.created_by)
 THEN RAISE EXCEPTION 'Tender source identity and history are immutable'; END IF;
 IF NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Tender version must advance exactly once'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER project_tender_identity BEFORE UPDATE OR DELETE ON project_tenders FOR EACH ROW EXECUTE FUNCTION project_tender_identity_guard();
SQL);
        foreach (['documents', 'technical', 'financial', 'approvals', 'bid_documents', 'submissions', 'results', 'events', 'guarantees', 'guarantee_events', 'handoff_plans', 'handoff_approvals', 'handoffs'] as $table) {
            DB::unprepared("CREATE TRIGGER project_tender_{$table}_immutable BEFORE UPDATE OR DELETE ON project_tender_{$table} FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record()");
        }
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M07.tender_')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => 'M07.Tenders · المناقصات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Tender revisions and submission history require forward migrations.');
    }
};

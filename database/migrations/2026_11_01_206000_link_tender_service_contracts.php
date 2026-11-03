<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE project_tender_handoffs DROP CONSTRAINT project_tender_handoffs_destination_check;
ALTER TABLE project_tender_handoffs ADD CONSTRAINT project_tender_handoffs_destination_check CHECK(destination IN ('project','quotation','contract'));
CREATE TABLE service_contract_tender_sources (
 contract_id bigint PRIMARY KEY REFERENCES service_contracts(id), tender_id bigint NOT NULL REFERENCES project_tenders(id),
 result_id bigint NOT NULL REFERENCES project_tender_results(id), plan_id bigint NOT NULL REFERENCES project_tender_handoff_plans(id),
 snapshot jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tender_id,result_id)
);
CREATE TRIGGER service_contract_tender_source_history BEFORE UPDATE OR DELETE ON service_contract_tender_sources FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
CREATE TABLE service_contract_official_documents (
 revision_id bigint PRIMARY KEY REFERENCES service_contract_revisions(id), document_id uuid NOT NULL REFERENCES system_official_documents(id),
 issued_by bigint NOT NULL REFERENCES users(id), issued_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER service_contract_official_history BEFORE UPDATE OR DELETE ON service_contract_official_documents FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
        $key = 'M10.document_issue';
        [$label, $scope] = PermissionCatalog::DEFINITIONS[$key];
        DB::table('permission_catalog')->updateOrInsert(['code' => $key], ['label' => $label, 'scope' => $scope, 'module' => 'M10 · عقود الخدمة', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain tender award and official contract evidence; use forward migrations.');
    }
};

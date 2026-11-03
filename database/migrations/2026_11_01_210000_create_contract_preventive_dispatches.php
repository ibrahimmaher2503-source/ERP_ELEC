<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE service_contract_preventive_dispatches (
 id bigserial PRIMARY KEY, contract_id bigint NOT NULL REFERENCES service_contracts(id), revision_id bigint NOT NULL REFERENCES service_contract_revisions(id),
 schedule_code varchar(50) NOT NULL, work_order_id bigint NOT NULL UNIQUE REFERENCES maintenance_work_orders(id),
 responsible_id bigint NOT NULL REFERENCES users(id), creation_key uuid NOT NULL UNIQUE, created_by bigint NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(contract_id,revision_id,schedule_code)
);
CREATE TRIGGER service_contract_preventive_history BEFORE UPDATE OR DELETE ON service_contract_preventive_dispatches FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Retain preventive dispatch source history.');
    }
};

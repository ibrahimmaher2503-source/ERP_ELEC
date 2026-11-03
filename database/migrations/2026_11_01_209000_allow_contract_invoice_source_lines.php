<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The header owns source uniqueness; a measured claim can contain several taxed products.
        DB::statement('ALTER TABLE rp_customer_invoice_lines DROP CONSTRAINT rp_customer_invoice_lines_service_contract_source_id_key');
        DB::statement('CREATE INDEX rp_customer_invoice_lines_contract_source_idx ON rp_customer_invoice_lines(service_contract_source_id)');
    }

    public function down(): void
    {
        throw new RuntimeException('Retain source-linked measured invoice lines.');
    }
};

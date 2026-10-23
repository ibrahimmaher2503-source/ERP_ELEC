<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE project_source_links DROP CONSTRAINT project_source_links_source_type_check');
        DB::statement("ALTER TABLE project_source_links ADD CONSTRAINT project_source_links_source_type_check CHECK(source_type IN ('quotation','sales_order','requisition','rfq','supplier_offer','purchase_order','purchase_receipt'))");
    }

    public function down(): void {}
};

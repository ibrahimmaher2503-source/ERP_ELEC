<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchasing_rfqs', function (Blueprint $t) {
            $t->string('source_type', 20)->default('REQUISITION');
            $t->text('justification')->nullable();
        });
        DB::statement('ALTER TABLE purchasing_rfqs ALTER COLUMN requisition_id DROP NOT NULL');
        DB::statement('ALTER TABLE purchasing_rfq_lines ALTER COLUMN requisition_line_id DROP NOT NULL');
        Schema::table('purchasing_rfq_lines', function (Blueprint $t) {
            $t->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->unsignedBigInteger('base_unit_id')->nullable();
            $t->string('sku', 100)->nullable();
            $t->string('product_name', 200)->nullable();
            $t->string('unit_name', 100)->nullable();
            $t->decimal('source_quantity', 24, 8)->nullable();
        });
        // Read legacy issued snapshots without changing immutable historical lines.
        DB::statement('CREATE OR REPLACE VIEW purchasing_rfq_product_lines AS SELECT r.id,r.rfq_id,r.requisition_line_id,r.quantity,COALESCE(r.product_id,q.product_id) AS product_id,COALESCE(r.base_unit_id,q.unit_id) AS base_unit_id,COALESCE(r.sku,q.sku) AS sku,COALESCE(r.product_name,q.product_name) AS product_name,COALESCE(r.unit_name,q.unit_id::text) AS unit_name,COALESCE(r.source_quantity,r.quantity) AS source_quantity FROM purchasing_rfq_lines r LEFT JOIN purchase_requisition_lines q ON q.id=r.requisition_line_id');
        DB::statement("ALTER TABLE purchasing_rfqs ADD CONSTRAINT rfq_source_valid CHECK ((source_type='REQUISITION' AND requisition_id IS NOT NULL) OR (source_type='STANDALONE' AND requisition_id IS NULL AND justification IS NOT NULL AND length(btrim(justification))>0))");
        DB::statement('ALTER TABLE purchasing_rfq_lines ADD CONSTRAINT rfq_source_ceiling CHECK (source_quantity IS NULL OR (source_quantity>0 AND quantity<=source_quantity))');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchasing_rfq() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='issued' THEN RAISE EXCEPTION 'Issued RFQ is immutable'; END IF;
 IF (NEW.branch_id,NEW.requisition_id,NEW.source_type,NEW.created_by,NEW.creation_key) IS DISTINCT FROM (OLD.branch_id,OLD.requisition_id,OLD.source_type,OLD.created_by,OLD.creation_key) THEN RAISE EXCEPTION 'RFQ source identity is immutable'; END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['M05.rfq_standalone'] as $code) {
            [$label, $scope] = PermissionCatalog::DEFINITIONS[$code];
            DB::table('permission_catalog')->insertOrIgnore(['code'=>$code,'label'=>$label,'scope'=>$scope,'module'=>'M05 · المشتريات','created_at'=>now(),'updated_at'=>now()]);
        }
    }

    public function down(): void
    {
        // Removing source identity would destroy standalone procurement history.
    }
};

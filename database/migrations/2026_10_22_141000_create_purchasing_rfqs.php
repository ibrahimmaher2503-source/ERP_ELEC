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
        Schema::create('purchasing_rfqs', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('requisition_id')->constrained('purchase_requisitions')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->string('subject', 200);
            $t->date('request_date');
            $t->date('deadline')->nullable();
            $t->text('requirements')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('issued_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('purchasing_rfq_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained('purchasing_rfqs')->restrictOnDelete();
            $t->foreignId('requisition_line_id')->constrained('purchase_requisition_lines')->restrictOnDelete();
            $t->decimal('quantity', 24, 8);
            $t->unique(['rfq_id', 'requisition_line_id']);
        });
        Schema::create('purchasing_rfq_suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained('purchasing_rfqs')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('supplier_name', 255);
            $t->timestampTz('dispatched_at')->nullable();
            $t->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('dispatch_reference', 300)->nullable();
            $t->unique(['rfq_id', 'supplier_id']);
        });
        DB::statement('ALTER TABLE purchasing_rfq_lines ADD CONSTRAINT rfq_positive_quantity CHECK(quantity > 0)');
        DB::statement("ALTER TABLE purchasing_rfqs ADD CONSTRAINT rfq_status CHECK(status IN ('draft','issued'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchasing_rfq() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR OLD.status = 'issued' THEN RAISE EXCEPTION 'Issued RFQ is immutable'; END IF;
 IF NEW.branch_id <> OLD.branch_id OR NEW.requisition_id <> OLD.requisition_id OR NEW.created_by <> OLD.created_by OR NEW.creation_key <> OLD.creation_key THEN RAISE EXCEPTION 'RFQ source identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_rfq_guard BEFORE UPDATE OR DELETE ON purchasing_rfqs FOR EACH ROW EXECUTE FUNCTION guard_purchasing_rfq();
CREATE OR REPLACE FUNCTION guard_purchasing_rfq_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
 IF TG_OP = 'UPDATE' AND NEW.rfq_id <> OLD.rfq_id THEN RAISE EXCEPTION 'Cannot reparent RFQ line'; END IF;
 IF TG_OP = 'DELETE' THEN parent_id := OLD.rfq_id; ELSE parent_id := NEW.rfq_id; END IF;
 IF (SELECT status FROM purchasing_rfqs WHERE id=parent_id) <> 'draft' THEN RAISE EXCEPTION 'Only draft RFQ lines are editable'; END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER purchasing_rfq_line_guard BEFORE INSERT OR UPDATE OR DELETE ON purchasing_rfq_lines FOR EACH ROW EXECUTE FUNCTION guard_purchasing_rfq_line();
CREATE OR REPLACE FUNCTION guard_purchasing_rfq_supplier() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
 IF TG_OP = 'UPDATE' AND NEW.rfq_id <> OLD.rfq_id THEN RAISE EXCEPTION 'Cannot reparent supplier invitation'; END IF;
 IF TG_OP = 'DELETE' THEN parent_id := OLD.rfq_id; ELSE parent_id := NEW.rfq_id; END IF;
 IF (SELECT status FROM purchasing_rfqs WHERE id=parent_id) = 'issued' THEN
   IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'Issued recipients are immutable'; END IF;
   IF NEW.rfq_id <> OLD.rfq_id OR NEW.supplier_id <> OLD.supplier_id OR NEW.supplier_name <> OLD.supplier_name OR OLD.dispatched_at IS NOT NULL OR NEW.dispatched_at IS NULL OR NEW.dispatched_by IS NULL THEN RAISE EXCEPTION 'Only initial actual dispatch may be recorded'; END IF;
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER purchasing_rfq_supplier_guard BEFORE INSERT OR UPDATE OR DELETE ON purchasing_rfq_suppliers FOR EACH ROW EXECUTE FUNCTION guard_purchasing_rfq_supplier();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M05.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M05 · المشتريات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

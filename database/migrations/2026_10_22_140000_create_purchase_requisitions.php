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
        Schema::create('purchase_requisitions', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('draft')->index();
            $t->date('request_date');
            $t->date('required_date')->nullable();
            $t->string('department', 160)->nullable();
            $t->text('purpose');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('purchase_requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained('purchase_requisitions')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $t->string('sku', 100);
            $t->string('product_name', 255);
            $t->decimal('quantity', 24, 8);
            $t->date('required_date')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->unique(['requisition_id', 'product_id']);
        });
        Schema::create('purchase_requisition_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained('purchase_requisitions')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 30);
            $t->unsignedInteger('document_version');
            $t->text('reason')->nullable();
            $t->timestampTz('created_at');
        });
        DB::statement('ALTER TABLE purchase_requisition_lines ADD CONSTRAINT purchase_quantity_positive CHECK(quantity > 0)');
        DB::statement("ALTER TABLE purchase_requisitions ADD CONSTRAINT purchase_requisition_status CHECK(status IN ('draft','submitted','approved','rejected'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchase_requisition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR OLD.status = 'approved' THEN RAISE EXCEPTION 'Approved requisitions and history cannot be changed'; END IF;
 IF NEW.branch_id <> OLD.branch_id OR NEW.created_by <> OLD.created_by OR NEW.creation_key <> OLD.creation_key THEN RAISE EXCEPTION 'Requisition identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchase_requisition_guard BEFORE UPDATE OR DELETE ON purchase_requisitions FOR EACH ROW EXECUTE FUNCTION guard_purchase_requisition();
CREATE OR REPLACE FUNCTION guard_purchase_requisition_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
 IF TG_OP = 'UPDATE' AND NEW.requisition_id <> OLD.requisition_id THEN RAISE EXCEPTION 'Cannot reparent requisition lines'; END IF;
 IF TG_OP = 'DELETE' THEN parent_id := OLD.requisition_id; ELSE parent_id := NEW.requisition_id; END IF;
 IF (SELECT status FROM purchase_requisitions WHERE id = parent_id) <> 'draft' THEN RAISE EXCEPTION 'Only draft lines are editable'; END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchase_requisition_line_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_requisition_lines FOR EACH ROW EXECUTE FUNCTION guard_purchase_requisition_line();
CREATE OR REPLACE FUNCTION guard_purchase_requisition_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Review history is append only'; END IF;
 IF NEW.action IN ('approve','reject') AND NEW.actor_id = (SELECT created_by FROM purchase_requisitions WHERE id = NEW.requisition_id) THEN RAISE EXCEPTION 'Self approval is forbidden'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchase_requisition_review_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_requisition_reviews FOR EACH ROW EXECUTE FUNCTION guard_purchase_requisition_review();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M05.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M05 · المشتريات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

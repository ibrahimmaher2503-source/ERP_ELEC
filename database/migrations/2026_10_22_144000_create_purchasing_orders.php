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
        Schema::create('purchasing_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('supplier_name', 255);
            $t->foreignId('award_id')->nullable()->unique()->constrained('purchasing_awards')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->string('currency', 3);
            $t->text('payment_terms')->nullable();
            $t->text('notes')->nullable();
            $t->decimal('net_amount', 32, 8);
            $t->decimal('reported_tax', 32, 8)->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('issued_at')->nullable();
            $t->string('official_number')->nullable()->unique();
            $t->timestampsTz();
        });
        Schema::create('purchasing_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('purchasing_orders')->restrictOnDelete();
            $t->foreignId('offer_line_id')->nullable()->constrained('purchasing_offer_lines')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $t->string('sku', 100);
            $t->string('product_name', 255);
            $t->decimal('quantity', 24, 8);
            $t->decimal('unit_price', 24, 8);
            $t->decimal('discount_amount', 32, 8);
            $t->decimal('net_amount', 32, 8);
            $t->unique(['order_id', 'product_id']);
        });
        Schema::create('purchasing_order_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('purchasing_orders')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 30);
            $t->unsignedInteger('document_version');
            $t->text('reason')->nullable();
            $t->jsonb('snapshot');
            $t->timestampTz('created_at');
            $t->unique(['order_id', 'document_version']);
        });
        DB::statement("ALTER TABLE purchasing_orders ADD CONSTRAINT po_status CHECK(status IN ('draft','submitted','approved','rejected'))");
        DB::statement("ALTER TABLE purchasing_orders ADD CONSTRAINT po_no_self_approval CHECK(status<>'approved' OR (approved_by IS NOT NULL AND approved_by<>created_by))");
        DB::statement('ALTER TABLE purchasing_order_lines ADD CONSTRAINT po_line_values CHECK(quantity>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND net_amount=quantity*unit_price-discount_amount)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchasing_order() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE expected_action text;
BEGIN
 IF TG_OP='DELETE' OR OLD.status='approved' THEN RAISE EXCEPTION 'Approved PO immutable; amendment required'; END IF;
 IF (NEW.branch_id,NEW.supplier_id,NEW.created_by,NEW.creation_key,NEW.award_id) IS DISTINCT FROM (OLD.branch_id,OLD.supplier_id,OLD.created_by,OLD.creation_key,OLD.award_id) THEN RAISE EXCEPTION 'PO identity immutable'; END IF;
 IF OLD.status<>'draft' AND (NEW.order_date,NEW.expected_date,NEW.currency,NEW.payment_terms,NEW.notes,NEW.net_amount,NEW.reported_tax) IS DISTINCT FROM (OLD.order_date,OLD.expected_date,OLD.currency,OLD.payment_terms,OLD.notes,OLD.net_amount,OLD.reported_tax) THEN RAISE EXCEPTION 'PO review snapshot immutable'; END IF;
 IF NEW.status<>OLD.status THEN
   expected_action:=CASE WHEN OLD.status='draft' AND NEW.status='submitted' THEN 'submit' WHEN OLD.status='submitted' AND NEW.status='approved' THEN 'approve' WHEN OLD.status='submitted' AND NEW.status='rejected' THEN 'reject' WHEN OLD.status='rejected' AND NEW.status='draft' THEN 'reopen' ELSE NULL END;
   IF expected_action IS NULL OR NEW.version<>OLD.version+1 OR NOT EXISTS(SELECT 1 FROM purchasing_order_reviews WHERE order_id=OLD.id AND document_version=OLD.version AND action=expected_action) THEN RAISE EXCEPTION 'PO transition requires exact version decision'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_order_guard BEFORE UPDATE OR DELETE ON purchasing_orders FOR EACH ROW EXECUTE FUNCTION guard_purchasing_order();
CREATE OR REPLACE FUNCTION guard_purchasing_order_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
 IF TG_OP='UPDATE' AND NEW.order_id<>OLD.order_id THEN RAISE EXCEPTION 'Cannot reparent PO lines'; END IF;
 IF TG_OP='DELETE' THEN parent_id:=OLD.order_id; ELSE parent_id:=NEW.order_id; END IF;
 IF (SELECT status FROM purchasing_orders WHERE id=parent_id)<>'draft' THEN RAISE EXCEPTION 'Only draft PO lines editable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER purchasing_order_line_guard BEFORE INSERT OR UPDATE OR DELETE ON purchasing_order_lines FOR EACH ROW EXECUTE FUNCTION guard_purchasing_order_line();
CREATE OR REPLACE FUNCTION guard_purchasing_order_review() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PO decisions append only'; END IF;
 IF NEW.action IN ('approve','reject') AND NEW.actor_id=(SELECT created_by FROM purchasing_orders WHERE id=NEW.order_id) THEN RAISE EXCEPTION 'No self approval'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_order_review_guard BEFORE INSERT OR UPDATE OR DELETE ON purchasing_order_reviews FOR EACH ROW EXECUTE FUNCTION guard_purchasing_order_review();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M05.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M05 · المشتريات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

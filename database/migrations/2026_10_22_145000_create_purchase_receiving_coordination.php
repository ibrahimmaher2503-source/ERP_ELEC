<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->foreignId('order_id')->constrained('purchasing_orders')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 30)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->date('receipt_date');
            $t->string('delivery_reference', 200);
            $t->text('notes')->nullable();
            $t->foreignId('inventory_document_id')->nullable()->unique()->constrained('inventory_documents')->restrictOnDelete();
            $t->timestampsTz();
        });
        Schema::create('purchasing_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained('purchasing_receipts')->restrictOnDelete();
            $t->foreignId('order_line_id')->constrained('purchasing_order_lines')->restrictOnDelete();
            $t->decimal('quantity', 24, 8);
            $t->unique(['receipt_id', 'order_line_id']);
        });
        Schema::create('purchasing_amendment_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->foreignId('order_id')->constrained('purchasing_orders')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('source_version');
            $t->text('reason');
            $t->jsonb('proposed_changes');
            $t->string('status', 30)->default('pending_contract');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE purchasing_receipts ADD CONSTRAINT receipt_unposted_only CHECK(status IN ('draft','integration_pending') AND inventory_document_id IS NULL)");
        DB::statement('ALTER TABLE purchasing_receipt_lines ADD CONSTRAINT receipt_quantity_positive CHECK(quantity>0)');
        DB::statement("CREATE UNIQUE INDEX purchasing_active_amendment ON purchasing_amendment_requests(order_id) WHERE status='pending_contract'");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchasing_receipt() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'draft' THEN RAISE EXCEPTION 'Submitted receiving expectation immutable'; END IF;
 IF (NEW.order_id,NEW.branch_id,NEW.warehouse_id,NEW.created_by,NEW.creation_key) IS DISTINCT FROM (OLD.order_id,OLD.branch_id,OLD.warehouse_id,OLD.created_by,OLD.creation_key) THEN RAISE EXCEPTION 'Receipt source identity immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_receipt_guard BEFORE UPDATE OR DELETE ON purchasing_receipts FOR EACH ROW EXECUTE FUNCTION guard_purchasing_receipt();
CREATE OR REPLACE FUNCTION guard_purchasing_receipt_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
 IF TG_OP='UPDATE' AND NEW.receipt_id<>OLD.receipt_id THEN RAISE EXCEPTION 'Cannot reparent receipt line'; END IF;
 IF TG_OP='DELETE' THEN parent_id:=OLD.receipt_id; ELSE parent_id:=NEW.receipt_id; END IF;
 IF (SELECT status FROM purchasing_receipts WHERE id=parent_id)<>'draft' THEN RAISE EXCEPTION 'Submitted receipt lines immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER purchasing_receipt_line_guard BEFORE INSERT OR UPDATE OR DELETE ON purchasing_receipt_lines FOR EACH ROW EXECUTE FUNCTION guard_purchasing_receipt_line();
CREATE TRIGGER purchasing_amendment_guard BEFORE UPDATE OR DELETE ON purchasing_amendment_requests FOR EACH ROW EXECUTE FUNCTION guard_purchasing_original_file();
SQL);
    }

    public function down(): void {}
};

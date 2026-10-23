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
        Schema::create('inventory_warehouses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->string('name', 160);
            $t->text('address')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['id', 'branch_id']);
        });
        Schema::create('user_warehouse_scopes', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('branch_id');
            $t->timestampsTz();
            $t->primary(['user_id', 'warehouse_id']);
            $t->foreign(['warehouse_id', 'branch_id'])->references(['id', 'branch_id'])->on('inventory_warehouses')->restrictOnDelete();
        });
        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->string('sku', 100);
            $t->string('product_name', 200);
            $t->string('unit_name', 100);
            $t->unsignedBigInteger('category_id')->nullable();
            $t->string('category_name', 160)->nullable();
            $t->decimal('on_hand', 24, 8)->default('0');
            $t->decimal('reserved', 24, 8)->default('0');
            $t->decimal('available', 24, 8)->storedAs('on_hand - reserved');
            $t->decimal('minimum', 24, 8)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['warehouse_id', 'product_id']);
        });
        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balance_nonnegative CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand AND (minimum IS NULL OR minimum >= 0))');
        Schema::create('inventory_documents', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->string('kind', 20);
            $t->string('status', 20)->default('draft');
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('destination_id')->nullable()->constrained('inventory_warehouses')->restrictOnDelete();
            $t->text('reason');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->unsignedBigInteger('cutoff')->nullable();
            $t->unsignedBigInteger('linked_document_id')->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['warehouse_id', 'status']);
        });
        DB::statement("ALTER TABLE inventory_documents ADD CONSTRAINT inventory_document_state CHECK (kind IN ('opening','receipt','issue','transfer','adjustment','count') AND status IN ('draft','submitted','approved','posted','dispatched','received','cancelled') AND (destination_id IS NULL OR destination_id <> warehouse_id) AND (approved_by IS NULL OR approved_by <> created_by))");
        Schema::create('inventory_document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('inventory_documents')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->string('sku', 100);
            $t->string('product_name', 200);
            $t->unsignedBigInteger('base_unit_id');
            $t->string('unit_name', 100);
            $t->decimal('quantity', 24, 8);
            $t->decimal('recorded', 24, 8)->nullable();
            $t->unique(['document_id', 'product_id']);
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->string('operation', 30);
            $t->string('source_module', 40);
            $t->string('source_id', 100);
            $t->string('source_line', 100);
            $t->foreignId('document_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->decimal('quantity', 24, 8);
            $t->unsignedBigInteger('base_unit_id');
            $t->string('sku', 100);
            $t->string('product_name', 200);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('posted_at');
            $t->string('idempotency_key', 160)->unique();
            $t->foreignId('reversal_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $t->index(['warehouse_id', 'product_id', 'id']);
        });
        Schema::create('inventory_reservations', function (Blueprint $t) {
            $t->id();
            $t->uuid('idempotency_key')->unique();
            $t->string('source_module', 40);
            $t->string('source_id', 100);
            $t->string('source_line', 100);
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->decimal('requested', 24, 8);
            $t->decimal('reserved', 24, 8);
            $t->decimal('fulfilled', 24, 8)->default('0');
            $t->decimal('released', 24, 8)->default('0');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservation_valid CHECK (requested > 0 AND reserved >= 0 AND reserved <= requested AND fulfilled >= 0 AND released >= 0 AND fulfilled + released <= reserved)');
        Schema::create('inventory_reservation_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained('inventory_reservations')->restrictOnDelete();
            $t->string('action', 20);
            $t->decimal('quantity', 24, 8);
            $t->uuid('idempotency_key')->unique();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('occurred_at');
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Inventory evidence is immutable'; END $$;
CREATE TRIGGER inventory_ledger_immutable BEFORE UPDATE OR DELETE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION inventory_immutable();
CREATE TRIGGER inventory_reservation_events_immutable BEFORE UPDATE OR DELETE ON inventory_reservation_events FOR EACH ROW EXECUTE FUNCTION inventory_immutable();
CREATE OR REPLACE FUNCTION inventory_warehouse_branch_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.branch_id <> OLD.branch_id THEN RAISE EXCEPTION 'Warehouse branch is immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER inventory_warehouse_branch_guard BEFORE UPDATE ON inventory_warehouses FOR EACH ROW EXECUTE FUNCTION inventory_warehouse_branch_immutable();
CREATE OR REPLACE FUNCTION inventory_document_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Inventory history cannot be deleted'; END IF;
IF OLD.status IN ('posted','received','cancelled') THEN RAISE EXCEPTION 'Final inventory document is immutable'; END IF;
IF OLD.status <> 'draft' AND (NEW.kind,NEW.creation_key,NEW.warehouse_id,NEW.destination_id,NEW.reason,NEW.created_by,NEW.cutoff) IS DISTINCT FROM (OLD.kind,OLD.creation_key,OLD.warehouse_id,OLD.destination_id,OLD.reason,OLD.created_by,OLD.cutoff) THEN RAISE EXCEPTION 'Submitted inventory snapshot is immutable'; END IF;
RETURN NEW; END $$;
CREATE TRIGGER inventory_document_guard_trigger BEFORE UPDATE OR DELETE ON inventory_documents FOR EACH ROW EXECUTE FUNCTION inventory_document_guard();
CREATE OR REPLACE FUNCTION inventory_line_guard() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE s text; BEGIN
SELECT status INTO s FROM inventory_documents WHERE id = COALESCE(NEW.document_id,OLD.document_id);
IF s <> 'draft' THEN RAISE EXCEPTION 'Submitted inventory lines are immutable'; END IF;
IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END $$;
CREATE TRIGGER inventory_line_guard_trigger BEFORE INSERT OR UPDATE OR DELETE ON inventory_document_lines FOR EACH ROW EXECUTE FUNCTION inventory_line_guard();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M06.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M06 · المخزون', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['inventory_reservation_events', 'inventory_reservations', 'inventory_movements', 'inventory_document_lines', 'inventory_documents', 'inventory_balances', 'user_warehouse_scopes', 'inventory_warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

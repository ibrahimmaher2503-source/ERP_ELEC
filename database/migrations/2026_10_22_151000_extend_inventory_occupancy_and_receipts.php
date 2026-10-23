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
        Schema::table('inventory_balances', fn (Blueprint $t) => $t->decimal('unavailable',24,8)->default('0'));
        DB::statement('ALTER TABLE inventory_balances DROP COLUMN available');
        DB::statement('ALTER TABLE inventory_balances ADD COLUMN available NUMERIC(24,8) GENERATED ALWAYS AS (on_hand-reserved-unavailable) STORED');
        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_unavailable_valid CHECK (unavailable>=0 AND reserved+unavailable<=on_hand)');
        Schema::table('inventory_document_lines', fn (Blueprint $t) => $t->jsonb('serials')->default('[]'));
        Schema::create('inventory_isolations', function (Blueprint $t) {
            $t->id(); $t->uuid('idempotency_key')->unique(); $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete(); $t->foreignId('source_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $t->decimal('quantity',24,8); $t->decimal('released',24,8)->default('0'); $t->text('reason'); $t->string('payload_hash',64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->timestampTz('occurred_at');
        });
        DB::statement('ALTER TABLE inventory_isolations ADD CONSTRAINT inventory_isolation_valid CHECK (quantity>0 AND released>=0 AND released<=quantity)');
        Schema::create('inventory_condition_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('isolation_id')->constrained('inventory_isolations')->restrictOnDelete(); $t->uuid('idempotency_key')->unique();
            $t->string('action',20); $t->decimal('quantity',24,8); $t->string('payload_hash',64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->timestampTz('occurred_at');
        });
        Schema::create('inventory_serials', function (Blueprint $t) {
            $t->id(); $t->foreignId('product_id')->constrained('products')->restrictOnDelete(); $t->string('normalized',200); $t->string('entered',200);
            $t->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->restrictOnDelete(); $t->string('state',20);
            $t->foreignId('source_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $t->foreignId('last_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $t->foreignId('transfer_id')->nullable()->constrained('inventory_documents')->restrictOnDelete();
            $t->foreignId('reservation_id')->nullable()->constrained('inventory_reservations')->restrictOnDelete();
            $t->foreignId('isolation_id')->nullable()->constrained('inventory_isolations')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1); $t->timestampsTz(); $t->unique(['product_id','normalized']);
        });
        DB::statement("ALTER TABLE inventory_serials ADD CONSTRAINT inventory_serial_state CHECK ((state IN ('available','reserved','unavailable','issued') AND warehouse_id IS NOT NULL) OR (state='transit' AND warehouse_id IS NULL AND transfer_id IS NOT NULL))");
        Schema::create('inventory_serial_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('serial_id')->constrained('inventory_serials')->restrictOnDelete();
            $t->foreignId('movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $t->string('action',30); $t->jsonb('snapshot'); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->timestampTz('occurred_at');
            $t->unique(['serial_id','movement_id']);
        });
        Schema::create('inventory_transfer_receipts', function (Blueprint $t) {
            $t->id(); $t->uuid('idempotency_key')->unique(); $t->foreignId('document_id')->constrained('inventory_documents')->restrictOnDelete();
            $t->text('reason')->nullable(); $t->text('evidence')->nullable(); $t->string('payload_hash',64);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->timestampTz('occurred_at');
        });
        Schema::create('inventory_transfer_receipt_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('receipt_id')->constrained('inventory_transfer_receipts')->restrictOnDelete();
            $t->foreignId('line_id')->constrained('inventory_document_lines')->restrictOnDelete();
            foreach (['accepted','damaged','missing','recovered_missing'] as $column) { $t->decimal($column,24,8)->default('0'); }
            $t->jsonb('accepted_serials')->default('[]'); $t->jsonb('damaged_serials')->default('[]'); $t->unique(['receipt_id','line_id']);
        });
        DB::statement('ALTER TABLE inventory_transfer_receipt_lines ADD CONSTRAINT inventory_transfer_receipt_valid CHECK (accepted>=0 AND damaged>=0 AND missing>=0 AND recovered_missing>=0 AND recovered_missing<=accepted+damaged AND accepted+damaged+missing>0)');
        Schema::create('inventory_corrections', function (Blueprint $t) {
            $t->id(); $t->uuid('idempotency_key')->unique(); $t->foreignId('movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $t->foreignId('correcting_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $t->decimal('quantity',24,8); $t->text('reason'); $t->string('status',30); $t->string('payload_hash',64);
            $t->jsonb('serials')->default('[]'); $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->timestampTz('occurred_at');
        });
        DB::statement("ALTER TABLE inventory_corrections ADD CONSTRAINT inventory_correction_valid CHECK (quantity>0 AND ((status='posted' AND correcting_movement_id IS NOT NULL) OR (status='pending_financial' AND correcting_movement_id IS NULL)))");
        foreach (['inventory_condition_events','inventory_serial_events','inventory_transfer_receipts','inventory_transfer_receipt_lines','inventory_corrections'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION inventory_immutable()");
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_isolation_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR (NEW.idempotency_key,NEW.warehouse_id,NEW.product_id,NEW.source_movement_id,NEW.quantity,NEW.reason,NEW.payload_hash,NEW.actor_id,NEW.occurred_at) IS DISTINCT FROM (OLD.idempotency_key,OLD.warehouse_id,OLD.product_id,OLD.source_movement_id,OLD.quantity,OLD.reason,OLD.payload_hash,OLD.actor_id,OLD.occurred_at) OR NEW.released<OLD.released THEN RAISE EXCEPTION 'Isolation history is immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER inventory_isolation_guard_trigger BEFORE UPDATE OR DELETE ON inventory_isolations FOR EACH ROW EXECUTE FUNCTION inventory_isolation_guard();
CREATE OR REPLACE FUNCTION inventory_serial_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR (NEW.product_id,NEW.normalized,NEW.entered,NEW.source_movement_id) IS DISTINCT FROM (OLD.product_id,OLD.normalized,OLD.entered,OLD.source_movement_id) OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'Serial lifetime identity is immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER inventory_serial_guard_trigger BEFORE UPDATE OR DELETE ON inventory_serials FOR EACH ROW EXECUTE FUNCTION inventory_serial_guard();
CREATE OR REPLACE FUNCTION inventory_serial_movement_check() RETURNS trigger LANGUAGE plpgsql AS $$ DECLARE tracking_kind text; event_count bigint; BEGIN
 SELECT tracking INTO tracking_kind FROM products WHERE id=NEW.product_id;
 IF tracking_kind='serial' THEN
   SELECT count(*) INTO event_count FROM inventory_serial_events WHERE movement_id=NEW.id;
   IF abs(NEW.quantity)<>event_count THEN RAISE EXCEPTION 'Serial events must reconcile with posted base quantity'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_serial_movement_quantity AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_serial_movement_check();
SQL);
        foreach (['M06.isolation_manage','M06.isolation_release','M06.correction_execute','M06.serial_view'] as $code) {
            [$label,$scope]=PermissionCatalog::DEFINITIONS[$code];
            DB::table('permission_catalog')->insertOrIgnore(['code'=>$code,'label'=>$label,'scope'=>$scope,'module'=>'M06 · المخزون','created_at'=>now(),'updated_at'=>now()]);
        }
    }

    public function down(): void { /* Occupancy and immutable evidence must not be discarded. */ }
};

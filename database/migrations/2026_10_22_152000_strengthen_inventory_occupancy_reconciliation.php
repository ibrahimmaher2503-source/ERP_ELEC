<?php

use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_balances',fn(Blueprint $t)=>$t->string('tracking',20)->default('quantity'));
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_serial_occupancy_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p bigint; w bigint; b record; held numeric; allocated numeric; isolated numeric;
BEGIN
 IF TG_TABLE_NAME='inventory_balances' THEN p:=NEW.product_id; ELSE p:=NEW.product_id; END IF;
 FOR b IN SELECT * FROM inventory_balances WHERE product_id=p AND tracking='serial' LOOP
   SELECT count(*) FILTER (WHERE state IN ('available','reserved','unavailable')),count(*) FILTER (WHERE state='reserved'),count(*) FILTER (WHERE state='unavailable') INTO held,allocated,isolated FROM inventory_serials WHERE product_id=p AND warehouse_id=b.warehouse_id;
   IF (b.on_hand,b.reserved,b.unavailable) IS DISTINCT FROM (held,allocated,isolated) THEN RAISE EXCEPTION 'Serial occupancy and stock projection do not reconcile'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_serial_balance_check AFTER INSERT OR UPDATE ON inventory_balances DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_serial_occupancy_check();
CREATE CONSTRAINT TRIGGER inventory_serial_location_check AFTER INSERT OR UPDATE ON inventory_serials DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_serial_occupancy_check();
SQL);
        // Preserve the standing explicit-role default; never assign membership or scopes.
        app(SyncAdministratorPermissions::class)->execute();
    }
    public function down(): void { /* Retain integrity safeguards and history. */ }
};

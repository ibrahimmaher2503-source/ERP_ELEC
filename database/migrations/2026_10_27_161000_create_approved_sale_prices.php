<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE product_sale_prices (
 id bigserial PRIMARY KEY, product_id bigint NOT NULL REFERENCES products(id), unit_id bigint NOT NULL REFERENCES units_of_measure(id),
 amount numeric(20,8) NOT NULL CHECK(amount>=0), starts_on date NOT NULL, ends_on date CHECK(ends_on>=starts_on), reference varchar(500) NOT NULL,
 status varchar(12) NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','withdrawn')),
 created_by bigint NOT NULL REFERENCES users(id), approved_by bigint REFERENCES users(id), approved_at timestamptz, created_at timestamptz NOT NULL,
 CHECK(approved_by IS NULL OR approved_by<>created_by), CHECK(status<>'approved' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL))
);
CREATE INDEX product_sale_price_lookup ON product_sale_prices(product_id,unit_id,starts_on) WHERE status='approved';
CREATE OR REPLACE FUNCTION product_guard_sale_price() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(40231);
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Price history cannot be deleted'; END IF;
 IF TG_OP='UPDATE' THEN
  IF OLD.status='withdrawn' OR NEW.status NOT IN ('approved','withdrawn') OR ROW(NEW.product_id,NEW.unit_id,NEW.amount,NEW.starts_on,NEW.ends_on,NEW.reference,NEW.created_by,NEW.created_at) IS DISTINCT FROM ROW(OLD.product_id,OLD.unit_id,OLD.amount,OLD.starts_on,OLD.ends_on,OLD.reference,OLD.created_by,OLD.created_at) THEN RAISE EXCEPTION 'Price source immutable'; END IF;
  IF OLD.status='approved' AND ROW(NEW.approved_by,NEW.approved_at) IS DISTINCT FROM ROW(OLD.approved_by,OLD.approved_at) THEN RAISE EXCEPTION 'Price approval immutable'; END IF;
 END IF;
 IF NEW.status='approved' AND EXISTS(SELECT 1 FROM product_sale_prices p WHERE p.id<>NEW.id AND p.product_id=NEW.product_id AND p.unit_id=NEW.unit_id AND p.status='approved' AND daterange(p.starts_on,p.ends_on,'[]') && daterange(NEW.starts_on,NEW.ends_on,'[]')) THEN RAISE EXCEPTION 'Overlapping approved sale prices'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER product_sale_price_guard BEFORE INSERT OR UPDATE OR DELETE ON product_sale_prices FOR EACH ROW EXECUTE FUNCTION product_guard_sale_price();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M02.price_')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M02', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

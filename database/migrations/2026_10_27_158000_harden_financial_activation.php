<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE accounting_tax_rules ADD COLUMN purchase_recoverable boolean;
ALTER TABLE accounting_tax_rules ADD CONSTRAINT accounting_purchase_tax_treatment CHECK(transaction_type<>'purchase' OR purchase_recoverable IS NOT NULL);
CREATE OR REPLACE FUNCTION accounting_guard_mapping() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM accounting_activation WHERE id=1 AND activated_at IS NOT NULL) THEN RAISE EXCEPTION 'Activated account mappings frozen'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_mapping_guard BEFORE INSERT OR UPDATE OR DELETE ON accounting_account_mappings FOR EACH ROW EXECUTE FUNCTION accounting_guard_mapping();
CREATE OR REPLACE FUNCTION accounting_guard_mapped_account() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM accounting_account_mappings WHERE account_id=OLD.id)
 AND (NOT NEW.active OR NOT NEW.postable OR ROW(NEW.code,NEW.kind,NEW.parent_id) IS DISTINCT FROM ROW(OLD.code,OLD.kind,OLD.parent_id)) THEN RAISE EXCEPTION 'Required mapped account identity must remain active and postable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_mapped_account_guard BEFORE UPDATE ON accounting_accounts FOR EACH ROW EXECUTE FUNCTION accounting_guard_mapped_account();
CREATE OR REPLACE FUNCTION accounting_guard_contributor_opening() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE b accounting_opening_batches%ROWTYPE; setup accounting_activation%ROWTYPE; bid bigint;
BEGIN
 bid:=split_part(NEW.source_key,':',2)::bigint;
 SELECT * INTO b FROM accounting_opening_batches WHERE id=bid;
 SELECT * INTO setup FROM accounting_activation WHERE id=1;
 IF b.id IS NULL OR b.status<>'approved' OR b.configuration_version<>setup.version OR setup.activated_at IS NOT NULL OR NEW.opening_date IS DISTINCT FROM setup.go_live_date THEN RAISE EXCEPTION 'Approved current opening required'; END IF;
 IF TG_TABLE_NAME='payable_opening_items' THEN
  IF NOT EXISTS(SELECT 1 FROM accounting_opening_lines WHERE batch_id=bid AND kind='ap' AND branch_id=NEW.branch_id AND reference=NEW.reference AND supplier_id=NEW.supplier_id AND amount=NEW.amount) THEN RAISE EXCEPTION 'Supplier opening differs from approved detail'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM accounting_opening_lines WHERE batch_id=bid AND kind=NEW.kind AND branch_id=NEW.branch_id AND reference=NEW.reference AND amount=NEW.amount) THEN RAISE EXCEPTION 'Treasury opening differs from approved detail'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payable_opening_source_guard BEFORE INSERT ON payable_opening_items FOR EACH ROW EXECUTE FUNCTION accounting_guard_contributor_opening();
CREATE TRIGGER treasury_opening_source_guard BEFORE INSERT ON treasury_opening_balances FOR EACH ROW EXECUTE FUNCTION accounting_guard_contributor_opening();
CREATE OR REPLACE FUNCTION inventory_guard_fifo_layer() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m inventory_movements%ROWTYPE;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'FIFO source history cannot be deleted'; END IF;
 IF TG_OP='UPDATE' THEN
  IF ROW(NEW.source_key,NEW.movement_id,NEW.warehouse_id,NEW.product_id,NEW.received_on,NEW.original_quantity,NEW.original_value,NEW.unit_cost,NEW.created_at) IS DISTINCT FROM ROW(OLD.source_key,OLD.movement_id,OLD.warehouse_id,OLD.product_id,OLD.received_on,OLD.original_quantity,OLD.original_value,OLD.unit_cost,OLD.created_at) THEN RAISE EXCEPTION 'FIFO source immutable'; END IF;
 ELSE
  SELECT * INTO m FROM inventory_movements WHERE id=NEW.movement_id;
  IF m.id IS NULL OR m.quantity<=0 OR m.warehouse_id<>NEW.warehouse_id OR m.product_id<>NEW.product_id OR m.quantity<>NEW.original_quantity THEN RAISE EXCEPTION 'FIFO layer must match its actual stock movement'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER inventory_fifo_layer_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_fifo_layers FOR EACH ROW EXECUTE FUNCTION inventory_guard_fifo_layer();
CREATE OR REPLACE FUNCTION inventory_reconcile_fifo_layer() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE l inventory_fifo_layers%ROWTYPE; lid bigint; q numeric; raw numeric; posted numeric;
BEGIN
 IF TG_TABLE_NAME='inventory_fifo_layers' THEN lid:=NEW.id; ELSE lid:=NEW.layer_id; END IF;
 SELECT * INTO l FROM inventory_fifo_layers WHERE id=lid;
 SELECT coalesce(sum(quantity),0),coalesce(sum(raw_value),0),coalesce(sum(amount),0) INTO q,raw,posted FROM inventory_fifo_allocations WHERE layer_id=lid;
 IF l.original_quantity-l.remaining_quantity<>q OR l.original_value-l.remaining_value<>raw OR l.allocated_amount<>posted THEN RAISE EXCEPTION 'FIFO quantities/raw values/posted amounts do not reconcile'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER inventory_fifo_layer_reconcile AFTER INSERT OR UPDATE ON inventory_fifo_layers DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile_fifo_layer();
CREATE CONSTRAINT TRIGGER inventory_fifo_allocation_reconcile AFTER INSERT ON inventory_fifo_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile_fifo_layer();
CREATE OR REPLACE FUNCTION accounting_guard_source_identity() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(OLD.opening_batch_id,OLD.source_key) IS DISTINCT FROM ROW(NEW.opening_batch_id,NEW.source_key) THEN RAISE EXCEPTION 'Financial source identity immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER accounting_source_identity_guard BEFORE UPDATE ON accounting_journals FOR EACH ROW EXECUTE FUNCTION accounting_guard_source_identity();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M11.') || $code === 'M05.receipt_post') {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope, 'module' => str_starts_with($code, 'M11.') ? 'M11 · المحاسبة' : 'M05 · المشتريات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

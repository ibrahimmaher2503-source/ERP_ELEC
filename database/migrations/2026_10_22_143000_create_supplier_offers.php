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
        Schema::create('purchasing_offers', function (Blueprint $t) {
            $t->id();
            $t->uuid('creation_key')->unique();
            $t->foreignId('rfq_id')->constrained('purchasing_rfqs')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('supplier_name', 255);
            $t->string('supplier_reference', 160);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('previous_id')->nullable()->constrained('purchasing_offers')->restrictOnDelete();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->date('document_date');
            $t->date('valid_until')->nullable();
            $t->string('currency', 3);
            $t->unsignedInteger('lead_days')->nullable();
            $t->text('payment_terms')->nullable();
            $t->text('warranty')->nullable();
            $t->text('notes')->nullable();
            $t->decimal('net_amount', 32, 8);
            $t->decimal('reported_tax', 32, 8)->nullable();
            $t->timestampsTz();
            $t->index(['rfq_id', 'supplier_id']);
        });
        Schema::create('purchasing_offer_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained('purchasing_offers')->restrictOnDelete();
            $t->foreignId('rfq_line_id')->constrained('purchasing_rfq_lines')->restrictOnDelete();
            $t->decimal('quantity', 24, 8);
            $t->decimal('unit_price', 24, 8);
            $t->decimal('discount_amount', 32, 8)->default('0');
            $t->decimal('net_amount', 32, 8);
            $t->text('specification')->nullable();
            $t->unique(['offer_id', 'rfq_line_id']);
        });
        Schema::create('purchasing_offer_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('offer_id')->constrained('purchasing_offers')->restrictOnDelete();
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 100);
            $t->string('sha256', 64);
            $t->unsignedInteger('size');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
        });
        Schema::create('purchasing_awards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rfq_id')->constrained('purchasing_rfqs')->restrictOnDelete();
            $t->foreignId('offer_id')->constrained('purchasing_offers')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('rationale');
            $t->string('status', 20)->default('proposed');
            $t->jsonb('snapshot');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX purchasing_one_award ON purchasing_awards(rfq_id) WHERE status IN ('proposed','approved')");
        DB::statement("ALTER TABLE purchasing_offers ADD CONSTRAINT offer_states CHECK(status IN ('draft','recorded'))");
        DB::statement('ALTER TABLE purchasing_offer_lines ADD CONSTRAINT offer_line_values CHECK(quantity>0 AND unit_price>=0 AND discount_amount>=0 AND net_amount>=0 AND net_amount=quantity*unit_price-discount_amount)');
        DB::statement("ALTER TABLE purchasing_awards ADD CONSTRAINT award_authority CHECK(status IN ('proposed','approved','rejected') AND (status<>'approved' OR (approved_by IS NOT NULL AND approved_by<>created_by)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_purchasing_offer() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='recorded' THEN RAISE EXCEPTION 'Recorded supplier offers are immutable; create a revision'; END IF;
 IF (NEW.rfq_id,NEW.supplier_id,NEW.branch_id,NEW.created_by,NEW.creation_key,NEW.previous_id) IS DISTINCT FROM (OLD.rfq_id,OLD.supplier_id,OLD.branch_id,OLD.created_by,OLD.creation_key,OLD.previous_id) THEN RAISE EXCEPTION 'Offer identity immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_offer_guard BEFORE UPDATE OR DELETE ON purchasing_offers FOR EACH ROW EXECUTE FUNCTION guard_purchasing_offer();
CREATE OR REPLACE FUNCTION guard_purchasing_offer_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent_id bigint;
BEGIN
 IF TG_OP='UPDATE' AND NEW.offer_id<>OLD.offer_id THEN RAISE EXCEPTION 'Cannot reparent offer line'; END IF;
 IF TG_OP='DELETE' THEN parent_id:=OLD.offer_id; ELSE parent_id:=NEW.offer_id; END IF;
 IF (SELECT status FROM purchasing_offers WHERE id=parent_id)<>'draft' THEN RAISE EXCEPTION 'Recorded lines immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER purchasing_offer_line_guard BEFORE INSERT OR UPDATE OR DELETE ON purchasing_offer_lines FOR EACH ROW EXECUTE FUNCTION guard_purchasing_offer_line();
CREATE OR REPLACE FUNCTION guard_purchasing_original_file() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Original supplier files are append only'; END $$;
CREATE TRIGGER purchasing_original_file_guard BEFORE UPDATE OR DELETE ON purchasing_offer_files FOR EACH ROW EXECUTE FUNCTION guard_purchasing_original_file();
CREATE OR REPLACE FUNCTION guard_purchasing_award() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'proposed' THEN RAISE EXCEPTION 'Final award immutable'; END IF;
 IF (NEW.rfq_id,NEW.offer_id,NEW.branch_id,NEW.created_by,NEW.rationale,NEW.snapshot) IS DISTINCT FROM (OLD.rfq_id,OLD.offer_id,OLD.branch_id,OLD.created_by,OLD.rationale,OLD.snapshot) THEN RAISE EXCEPTION 'Award proposal snapshot immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER purchasing_award_guard BEFORE UPDATE OR DELETE ON purchasing_awards FOR EACH ROW EXECUTE FUNCTION guard_purchasing_award();
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M05.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M05 · المشتريات', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void {}
};

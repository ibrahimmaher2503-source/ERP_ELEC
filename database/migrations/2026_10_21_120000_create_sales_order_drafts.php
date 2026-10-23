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
        Schema::create('sales_orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('draft_key')->unique();
            $t->string('number', 30)->nullable()->unique();
            $t->unsignedBigInteger('branch_id')->index();
            $t->jsonb('branch_snapshot');
            $t->unsignedBigInteger('customer_id')->index();
            $t->jsonb('customer_snapshot');
            $t->string('source_type', 20)->default('direct');
            $t->string('project_name', 200)->nullable();
            $t->string('customer_reference', 100)->nullable();
            $t->date('order_date');
            $t->date('requested_delivery_date')->nullable();
            $t->string('currency', 3)->default('EGP');
            $t->string('status', 30)->default('draft')->index();
            $t->unsignedInteger('version')->default(1);
            $t->string('discount_mode', 10)->default('percent');
            $t->decimal('discount_value', 20, 8)->default('0');
            $t->text('payment_terms')->nullable();
            $t->text('delivery_terms')->nullable();
            $t->text('notes')->nullable();
            $t->jsonb('calculation');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
            $t->index(['branch_id', 'status', 'order_date']);
        });
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_order_draft_state CHECK(status IN ('draft','pending_approval','cancelled')), ADD CONSTRAINT sales_order_currency CHECK(currency='EGP'), ADD CONSTRAINT sales_order_source CHECK(source_type='direct'), ADD CONSTRAINT sales_order_discount CHECK(discount_value >= 0 AND discount_mode IN ('percent','fixed') AND (discount_mode <> 'percent' OR discount_value <= 100)), ADD CONSTRAINT sales_order_version CHECK(version > 0), ADD CONSTRAINT sales_order_dates CHECK(requested_delivery_date IS NULL OR requested_delivery_date >= order_date)");
        Schema::create('sales_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('unit_id');
            $t->jsonb('product_snapshot');
            $t->text('description');
            $t->decimal('quantity', 18, 8);
            $t->decimal('suggested_price', 20, 8)->nullable();
            $t->decimal('requested_price', 20, 8)->nullable();
            $t->jsonb('price_source')->nullable();
            $t->string('discount_mode', 10)->default('percent');
            $t->decimal('discount_value', 20, 8)->default('0');
            $t->text('override_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['sales_order_id', 'position']);
        });
        DB::statement("ALTER TABLE sales_order_lines ADD CONSTRAINT sales_order_line_values CHECK(quantity > 0 AND (suggested_price IS NULL OR suggested_price >= 0) AND (requested_price IS NULL OR requested_price >= 0) AND discount_value >= 0 AND discount_mode IN ('percent','fixed') AND (discount_mode <> 'percent' OR discount_value <= 100))");
        Schema::create('sales_order_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $t->unsignedInteger('target_version');
            $t->string('content_hash', 64);
            $t->jsonb('snapshot');
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
            $t->unique(['sales_order_id', 'target_version']);
        });
        DB::statement("ALTER TABLE sales_order_reviews ADD CONSTRAINT sales_order_review_state CHECK(status IN ('pending','withdrawn','rejected'))");
        DB::statement("CREATE UNIQUE INDEX sales_order_one_pending_review ON sales_order_reviews(sales_order_id) WHERE status='pending'");
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M04.sales_order_')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M04 · أوامر البيع', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales Order records and review history require forward migrations.');
    }
};

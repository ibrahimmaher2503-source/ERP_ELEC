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
        Schema::create('sales_quotations', function (Blueprint $t) {
            $t->id();
            $t->uuid('draft_key')->unique();
            $t->string('number', 30)->nullable()->unique();
            $t->unsignedBigInteger('customer_id')->index();
            $t->jsonb('customer_snapshot');
            $t->string('project_name', 200)->nullable();
            $t->string('rfq_reference', 100)->nullable();
            $t->date('quotation_date');
            $t->date('valid_until')->nullable();
            $t->string('currency', 3)->default('EGP');
            $t->string('status', 30)->default('draft')->index();
            $t->unsignedInteger('revision')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_by')->nullable()->index();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('copied_from_id')->nullable();
            $t->string('discount_mode', 10)->default('percent');
            $t->decimal('discount_value', 20, 8)->default('0');
            $t->text('payment_terms')->nullable();
            $t->text('delivery_terms')->nullable();
            $t->text('notes')->nullable();
            $t->text('exception_reason')->nullable();
            $t->jsonb('calculation')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE sales_quotations ADD CONSTRAINT sales_quote_currency CHECK(currency='EGP'), ADD CONSTRAINT sales_quote_state CHECK(status IN ('draft','pending_approval','cancelled','rejected','expired')), ADD CONSTRAINT sales_quote_discount CHECK(discount_value >= 0 AND discount_mode IN ('percent','fixed'))");
        Schema::create('sales_quotation_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quotation_id')->constrained('sales_quotations')->restrictOnDelete();
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
            $t->unique(['quotation_id', 'position']);
        });
        DB::statement("ALTER TABLE sales_quotation_lines ADD CONSTRAINT sales_quote_line_values CHECK(quantity > 0 AND (requested_price IS NULL OR requested_price >= 0) AND (suggested_price IS NULL OR suggested_price >= 0) AND discount_value >= 0 AND discount_mode IN ('percent','fixed'))");
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M04.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M04 · المبيعات', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Quotation history must be retained. Use a forward migration.');
    }
};

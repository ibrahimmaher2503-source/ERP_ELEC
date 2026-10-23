<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique('uq_products_sku');
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('kind', 30);
            $table->string('tracking', 20);
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->string('model', 200)->nullable();
            $table->foreignId('base_unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->string('barcode', 100)->nullable()->unique('uq_products_barcode');
            $table->boolean('active')->default(true);
            $table->integer('version')->default(1);
            $table->text('internal_notes')->nullable();
            $table->jsonb('attributes')->default('[]');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestampsTz();
            $table->index(['active', 'category_id']);
            $table->index('brand_id');
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT ck_products_kind CHECK (kind IN ('serialized_equipment','bulk','service'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT ck_products_tracking CHECK (tracking IN ('serial','quantity','none'))");
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('factor', 18, 8);
            $table->timestampsTz();
            $table->unique(['product_id', 'unit_id'], 'uq_product_unit_conversions_pair');
        });
        DB::statement('ALTER TABLE product_unit_conversions ADD CONSTRAINT ck_product_unit_conversions_positive CHECK (factor > 0)');
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('path')->unique();
            $table->string('name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->boolean('is_image');
            $table->timestampTz('removed_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Catalog history is retained; use a forward migration.');
    }
};

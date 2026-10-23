<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_operation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('entity', 100);
            $table->string('kind', 20);
            $table->string('mode', 20);
            $table->string('template_version', 30);
            $table->string('status', 20)->default('preview');
            $table->string('filename', 200)->nullable();
            $table->string('source_path')->nullable();
            $table->jsonb('summary')->default('{}');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });
        Schema::create('data_operation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('data_operation_runs')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('identifier', 200);
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->jsonb('plan')->default('{}');
            $table->jsonb('before_values')->default('{}');
            $table->jsonb('after_values')->default('{}');
            $table->jsonb('result')->nullable();
            $table->timestampsTz();
            $table->unique(['run_id', 'row_number']);
            $table->index(['run_id', 'status']);
        });
        foreach (['product_bulk_update' => 'التعديل الجماعي للأصناف', 'product_import' => 'استيراد أصناف Excel', 'product_export' => 'تصدير أصناف Excel', 'product_template' => 'تحميل قالب الأصناف'] as $key => $label) {
            DB::table('permission_catalog')->updateOrInsert(['code' => 'M02.'.$key], ['label' => $label, 'scope' => 'company', 'module' => 'M02 · المنتجات', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Execution receipts are retained; use a forward migration.');
    }
};

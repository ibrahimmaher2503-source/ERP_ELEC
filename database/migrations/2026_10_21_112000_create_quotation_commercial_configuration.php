<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotation_policies', function (Blueprint $t) {
            $t->id();
            $t->jsonb('settings');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
        });
        Schema::create('sales_quotation_terms', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50);
            $t->string('kind', 10);
            $t->unsignedInteger('revision');
            $t->string('name', 200);
            $t->text('body');
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['code', 'revision']);
        });
        DB::statement("ALTER TABLE sales_quotation_terms ADD CONSTRAINT quotation_term_kind CHECK(kind IN ('payment','delivery'))");
        Schema::table('sales_quotations', function (Blueprint $t) {
            $t->foreignId('payment_term_id')->nullable()->constrained('sales_quotation_terms')->restrictOnDelete();
            $t->foreignId('delivery_term_id')->nullable()->constrained('sales_quotation_terms')->restrictOnDelete();
        });
        DB::table('permission_catalog')->insertOrIgnore(['code' => 'M04.quotation_policy_manage', 'label' => 'إدارة سياسات وشروط عروض الأسعار', 'scope' => 'company', 'module' => 'M04 · المبيعات', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Commercial policy history must be retained.');
    }
};

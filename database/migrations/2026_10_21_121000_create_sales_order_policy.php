<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_policies', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedBigInteger('exception_role_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('updated_by');
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE sales_order_policies ADD CONSTRAINT sales_order_policy_singleton CHECK(id=1 AND version>0)');
        DB::table('permission_catalog')->insertOrIgnore(['code' => 'M04.sales_order_exception_approve', 'label' => 'الموافقة الإضافية على استثناءات أمر البيع', 'scope' => 'branch', 'module' => 'M04 · أوامر البيع', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Sales Order security configuration is forward-only.');
    }
};

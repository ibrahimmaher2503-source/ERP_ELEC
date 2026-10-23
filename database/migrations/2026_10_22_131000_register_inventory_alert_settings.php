<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permission_catalog')->insertOrIgnore(['code' => 'M06.reorder_manage', 'label' => 'إدارة حدود تنبيه المخزون', 'scope' => 'warehouse', 'module' => 'M06 · المخزون', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void {}
};

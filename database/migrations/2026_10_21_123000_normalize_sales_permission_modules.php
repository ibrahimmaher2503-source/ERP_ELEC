<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permission_catalog')->where('code', 'like', 'M04.%')->update(['module' => 'M04 · المبيعات', 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Permission module metadata normalization is forward-only.');
    }
};

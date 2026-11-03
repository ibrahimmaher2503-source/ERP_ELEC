<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT sales_order_source');
        DB::statement("ALTER TABLE sales_orders ADD CONSTRAINT sales_order_source CHECK(source_type IN ('direct','quotation'))");
    }

    public function down(): void
    {
        throw new RuntimeException('Quotation order source history must be preserved.');
    }
};

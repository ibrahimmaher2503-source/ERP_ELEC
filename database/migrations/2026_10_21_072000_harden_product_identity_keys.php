<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products' => 'sku', 'product_categories' => 'code', 'brands' => 'code', 'units_of_measure' => 'code'] as $table => $column) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT ck_{$table}_canonical CHECK ({$column} = upper(btrim({$column})) AND length({$column}) > 0)");
        }
        DB::statement('CREATE UNIQUE INDEX uq_brands_normalized_name ON brands (lower(btrim(name)))');
    }

    public function down(): void
    {
        throw new RuntimeException('Identity constraints protect retained master references.');
    }
};

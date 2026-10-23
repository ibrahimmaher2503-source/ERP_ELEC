<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('sales_unit_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Unit definitions are retained with product history.');
    }
};

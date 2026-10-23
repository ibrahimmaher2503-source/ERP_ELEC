<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotation_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quotation_id')->constrained('sales_quotations')->restrictOnDelete();
            $t->string('name', 200);
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->string('sha256', 64);
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Internal quotation documents are retained.');
    }
};

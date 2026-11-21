<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['workshop_estimates', 'sales_quotations', 'sales_orders', 'rp_customer_invoices'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('tax_treatment', 20)->default('rule');
                $table->text('tax_exempt_reason')->nullable();
            });
        }

        DB::statement('ALTER TABLE rp_customer_invoice_lines ALTER COLUMN tax_rule_id DROP NOT NULL');

        Schema::create('organization_self_approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->string('scope', 64);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->unique(['target_type', 'target_id', 'scope'], 'org_self_approval_target_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_self_approval_policies');

        foreach (['rp_customer_invoices', 'sales_orders', 'sales_quotations', 'workshop_estimates'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['tax_treatment', 'tax_exempt_reason']);
            });
        }
    }
};

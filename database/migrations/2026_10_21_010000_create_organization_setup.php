<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true);
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique('uq_companies_code');
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_registration', 100)->nullable();
            $table->text('address')->nullable();
            $table->char('currency', 3)->default('EGP');
            $table->boolean('setup_complete')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT ck_companies_singleton CHECK (id = 1), ADD CONSTRAINT ck_companies_currency CHECK (currency = 'EGP'), ADD CONSTRAINT ck_companies_version CHECK (version > 0)");
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained('companies')->restrictOnDelete();
            $table->string('code', 50)->unique('uq_branches_code');
            $table->string('name', 200);
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE branches ADD CONSTRAINT ck_branches_company CHECK (company_id = 1), ADD CONSTRAINT ck_branches_version CHECK (version > 0)');
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        });
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('permission_code', 100);
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX uq_access_grants_scope ON access_grants (user_id, permission_code, branch_id) NULLS NOT DISTINCT');
        Schema::create('access_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->jsonb('before_snapshot');
            $table->jsonb('after_snapshot');
            $table->timestampTz('occurred_at');
        });
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('source_module', 50);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->string('action', 100);
            $table->jsonb('safe_before')->nullable();
            $table->jsonb('safe_after')->nullable();
            $table->uuid('correlation_id');
            $table->timestampTz('occurred_at');
        });
        DB::unprepared("CREATE OR REPLACE FUNCTION prevent_erp_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Audit history is immutable''; END;'");
        foreach (['audit_entries', 'access_audits'] as $table) {
            DB::statement("CREATE TRIGGER immutable_{$table} BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_erp_audit_mutation()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
        Schema::dropIfExists('access_audits');
        DB::statement('DROP FUNCTION IF EXISTS prevent_erp_audit_mutation()');
        Schema::dropIfExists('access_grants');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('active');
        });
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};

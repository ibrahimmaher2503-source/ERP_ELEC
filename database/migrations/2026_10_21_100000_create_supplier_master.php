<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name', 200);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name', 200);
            $t->string('name_en', 200)->nullable();
            $t->string('trading_name', 200)->nullable();
            $t->string('entity_type', 20);
            $t->string('supplier_type', 30)->index();
            $t->foreignId('category_id')->nullable()->constrained('supplier_categories')->restrictOnDelete();
            $t->string('status', 20)->default('active')->index();
            foreach (['registration_number', 'tax_number', 'country', 'city', 'phone', 'mobile', 'email', 'website'] as $f) {
                $t->string($f)->nullable();
            }
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['country', 'city']);
        });
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT suppliers_entity_check CHECK(entity_type IN ('individual','organization')), ADD CONSTRAINT suppliers_type_check CHECK(supplier_type IN ('manufacturer','distributor','service_provider','other')), ADD CONSTRAINT suppliers_status_check CHECK(status IN ('active','inactive','archived'))");
        DB::statement("CREATE INDEX suppliers_search_idx ON suppliers USING gin(to_tsvector('simple',coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(name_en,'') || ' ' || coalesce(trading_name,'') || ' ' || coalesce(registration_number,'') || ' ' || coalesce(email,'') || ' ' || coalesce(phone,'') || ' ' || coalesce(mobile,'')))");
        Schema::create('supplier_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('name', 200);
            $t->string('job_title', 200)->nullable();
            foreach (['phone', 'mobile', 'email'] as $f) {
                $t->string($f)->nullable();
            }$t->boolean('active')->default(true);
            $t->boolean('is_primary')->default(false);
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX supplier_primary_contact_unique ON supplier_contacts(supplier_id) WHERE is_primary');
        Schema::create('supplier_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('label', 200);
            $t->string('purpose', 20);
            $t->text('line');
            foreach (['country', 'city', 'region', 'postal_code'] as $f) {
                $t->string($f)->nullable();
            }$t->boolean('is_primary')->default(false);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE supplier_addresses ADD CONSTRAINT supplier_address_purpose_check CHECK(purpose IN ('registered','office','pickup','other'))");
        DB::statement('CREATE UNIQUE INDEX supplier_primary_address_unique ON supplier_addresses(supplier_id,purpose) WHERE is_primary');
        Schema::create('supplier_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('name', 200);
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->timestampsTz();
        });
        Schema::create('supplier_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('author_id')->nullable();
            $t->text('body');
            $t->timestampsTz();
        });
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M03.supplier_')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M03 · الموردون', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Supplier history is retained; use a forward migration.');
    }
};

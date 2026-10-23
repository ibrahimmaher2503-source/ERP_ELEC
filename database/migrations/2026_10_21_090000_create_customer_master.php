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
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name', 200);
            $t->string('name_en', 200)->nullable();
            $t->string('trading_name', 200)->nullable();
            $t->string('entity_type', 20);
            $t->string('segment', 100)->nullable()->index();
            $t->string('status', 20)->default('active')->index();
            foreach (['registration_number', 'tax_number', 'country', 'city', 'phone', 'mobile', 'email', 'website'] as $f) {
                $t->string($f)->nullable();
            }
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['country', 'city']);
            $t->index('entity_type');
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (entity_type IN ('individual','organization')), ADD CONSTRAINT customers_status_check CHECK (status IN ('active','inactive','archived'))");
        // A normalized, database-side substring index without requiring pg_trgm privileges.
        DB::statement("CREATE INDEX customers_search_idx ON customers USING gin (to_tsvector('simple', coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(name_en,'') || ' ' || coalesce(trading_name,'') || ' ' || coalesce(email,'') || ' ' || coalesce(phone,'') || ' ' || coalesce(mobile,'')))");
        Schema::create('customer_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('name', 200);
            $t->string('job_title', 200)->nullable();
            foreach (['phone', 'mobile', 'email'] as $f) {
                $t->string($f)->nullable();
            } $t->boolean('active')->default(true);
            $t->boolean('is_primary')->default(false);
            $t->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX customer_primary_contact_unique ON customer_contacts(customer_id) WHERE is_primary');
        Schema::create('customer_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('label', 200);
            $t->string('purpose', 20);
            $t->text('line');
            foreach (['country', 'city', 'region', 'postal_code'] as $f) {
                $t->string($f)->nullable();
            } $t->boolean('is_primary')->default(false);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE customer_addresses ADD CONSTRAINT customer_address_purpose_check CHECK (purpose IN ('billing','shipping','other'))");
        DB::statement('CREATE UNIQUE INDEX customer_primary_address_unique ON customer_addresses(customer_id,purpose) WHERE is_primary');
        Schema::create('customer_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->string('name', 200);
            $t->string('path');
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->timestampsTz();
        });
        Schema::create('customer_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('author_id')->nullable();
            $t->text('body');
            $t->timestampsTz();
        });
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label,$scope]) {
            if (str_starts_with($code, 'M03.')) {
                DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M03 · العملاء', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Customer history is retained; use a forward migration.');
    }
};

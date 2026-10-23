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
        Schema::create('permission_catalog', function (Blueprint $table) {
            $table->string('code', 100)->primary();
            $table->string('label', 200);
            $table->string('module', 100);
            $table->string('scope', 20);
            $table->boolean('locked')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE permission_catalog ADD CONSTRAINT ck_permission_catalog_scope CHECK (scope IN ('company', 'branch', 'warehouse'))");
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique('uq_roles_code');
            $table->string('name', 200);
            $table->boolean('protected')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->string('permission_code', 100);
            $table->foreign('permission_code')->references('code')->on('permission_catalog')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['role_id', 'permission_code'], 'uq_role_permissions_pair');
        });
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'role_id'], 'uq_user_roles_pair');
        });
        Schema::create('user_branch_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['user_id', 'branch_id'], 'uq_user_branch_scopes_pair');
        });
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            DB::table('permission_catalog')->insert(['code' => $code, 'label' => $label, 'module' => 'M01 · التنظيم', 'scope' => $scope, 'locked' => $code === 'M01.security_admin', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (PermissionCatalog::ROLES as $code => $name) {
            DB::table('roles')->insert(['code' => $code, 'name' => $name, 'protected' => $code === 'Administrator', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->where('code', 'Administrator')->value('id'), 'permission_code' => 'M01.security_admin', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_scopes');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permission_catalog');
    }
};

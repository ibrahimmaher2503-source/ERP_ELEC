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
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('session_version')->default(1);
            $table->timestampTz('last_login_at')->nullable();
        });
        // Existing imported case collisions must be resolved explicitly, never silently merged.
        DB::statement('CREATE UNIQUE INDEX uq_users_email_normalized ON users (lower(email))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT ck_users_versions CHECK (version > 0 AND session_version > 0)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX uq_users_email_normalized');
        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_versions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['version', 'session_version', 'last_login_at']);
        });
    }
};

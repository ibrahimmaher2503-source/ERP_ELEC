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
            $table->string('gender', 6)->nullable();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_gender CHECK (gender IS NULL OR gender IN ('male', 'female'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_gender');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};

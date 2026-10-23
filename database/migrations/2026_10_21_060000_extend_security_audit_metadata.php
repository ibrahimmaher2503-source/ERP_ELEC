<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_entries ALTER COLUMN actor_id DROP NOT NULL');
        foreach (['audit_entries', 'access_audits'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('outcome', 16)->nullable();
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT ck_{$name}_outcome CHECK (outcome IN ('succeeded', 'failed'))");
        }
        Schema::table('audit_entries', function (Blueprint $table) {
            $table->index(['source_module', 'source_type', 'source_id', 'occurred_at'], 'ix_audit_user_history');
        });
        Schema::table('access_audits', function (Blueprint $table) {
            $table->index(['subject_user_id', 'occurred_at'], 'ix_access_user_history');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Audit metadata is forward-only; preserve captured outcomes and anonymous actors.');
    }
};

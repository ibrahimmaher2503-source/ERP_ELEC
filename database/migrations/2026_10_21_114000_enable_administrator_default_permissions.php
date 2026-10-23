<?php

use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Automated role synchronization has no fabricated human actor or target account.
        DB::statement('ALTER TABLE access_audits ALTER COLUMN actor_id DROP NOT NULL');
        DB::statement('ALTER TABLE access_audits ALTER COLUMN subject_user_id DROP NOT NULL');
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Owner-approved Administrator defaults and their audit history are forward-only.');
    }
};

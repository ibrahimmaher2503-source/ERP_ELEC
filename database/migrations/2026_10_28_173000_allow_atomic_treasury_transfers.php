<?php

use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DO $$ DECLARE c record;
BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='treasury_movements'::regclass AND contype='u' AND pg_get_constraintdef(oid) ILIKE '%journal_id%' LOOP
  EXECUTE format('ALTER TABLE treasury_movements DROP CONSTRAINT %I',c.conname);
 END LOOP;
END $$;
CREATE UNIQUE INDEX treasury_one_journal_per_external_movement ON treasury_movements(journal_id) WHERE kind IN ('supplier_payment','customer_collection');
DO $$ DECLARE c record;
BEGIN
 FOR c IN SELECT conname FROM pg_constraint WHERE conrelid='treasury_movements'::regclass AND contype='f' AND pg_get_constraintdef(oid) ILIKE '%paired_movement_id%' LOOP
  EXECUTE format('ALTER TABLE treasury_movements ALTER CONSTRAINT %I DEFERRABLE INITIALLY DEFERRED',c.conname);
 END LOOP;
END $$;
SQL);
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M13.')) {
                DB::table('permission_catalog')->updateOrInsert(['code' => $code], ['label' => $label, 'scope' => $scope,
                    'module' => 'M13 · الخزينة', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Treasury transfer evidence requires forward migrations.');
    }
};

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
CREATE TABLE workshop_part_alternatives (
 original_requirement_id bigint PRIMARY KEY REFERENCES workshop_part_requirements,
 replacement_requirement_id bigint NOT NULL UNIQUE REFERENCES workshop_part_requirements,
 approved_by bigint NOT NULL REFERENCES users, reason text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), CHECK(original_requirement_id<>replacement_requirement_id)
);
CREATE TRIGGER workshop_part_alternatives_immutable BEFORE UPDATE OR DELETE ON workshop_part_alternatives FOR EACH ROW EXECUTE FUNCTION accounting_immutable_record();
SQL);
        $code = 'M09.parts_alternative_approve';
        [$label, $scope] = PermissionCatalog::DEFINITIONS[$code];
        DB::table('permission_catalog')->insertOrIgnore(['code' => $code, 'label' => $label, 'scope' => $scope, 'module' => 'M09 · الورش', 'created_at' => now(), 'updated_at' => now()]);
        app(SyncAdministratorPermissions::class)->execute();
    }

    public function down(): void
    {
        throw new RuntimeException('Part replacement history requires forward migrations.');
    }
};

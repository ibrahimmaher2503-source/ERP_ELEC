<?php

use App\Modules\Organization\Application\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::DEFINITIONS as $code => [$label, $scope]) {
            if (str_starts_with($code, 'M03.')) {
                DB::table('permission_catalog')->where('code', $code)->update(['label' => $label, 'module' => 'M03 · العملاء', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Display metadata only; authorization grants and history are preserved.
    }
};

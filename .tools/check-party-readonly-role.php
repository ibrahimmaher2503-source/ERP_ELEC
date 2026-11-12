<?php

use App\Models\User;
use App\Modules\Organization\Application\SaveRolePermissions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};
$rejects = static function (Closure $action, string $message) use ($verify): void {
    try {
        $action();
    } catch (ValidationException) {
        $verify(true, $message);

        return;
    }

    throw new RuntimeException($message.' (write permission was accepted)');
};

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Read-only role check requires the exact separate PostgreSQL test database.');
}

$role = DB::table('roles')->where('code', 'ManagerReadOnly')->first();
if (! $role) {
    throw new RuntimeException('Read-only role check requires the configured ManagerReadOnly role.');
}
$initialPermissions = DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all();
$initialVersion = (int) $role->version;
DB::beginTransaction();

try {
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    DB::table('access_grants')->insert([
        'user_id' => $actor->id,
        'permission_code' => 'M01.security_admin',
        'branch_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $readPermissions = [
        'M01.user_view',
        'M03.supplier_view',
        'M03.supplier_document_view',
        'M03.supplier_notes_view',
        'M03.supplier_history_view',
    ];
    sort($readPermissions);
    $save = app(SaveRolePermissions::class);
    $save->execute($actor, (int) $role->id, $readPermissions, $initialVersion);
    $savedPermissions = DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all();
    $verify($savedPermissions === $readPermissions, 'ManagerReadOnly accepts Supplier view, document, note, and history permissions');

    $versionAfterReadSave = (int) DB::table('roles')->where('id', $role->id)->value('version');
    foreach (['M03.supplier_update', 'M03.supplier_import'] as $writePermission) {
        $rejects(fn () => $save->execute($actor, (int) $role->id, [$writePermission], $versionAfterReadSave), "ManagerReadOnly rejects {$writePermission}");
        $verify(DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all() === $readPermissions, "Rejected {$writePermission} leaves the temporary role matrix unchanged");
    }

    DB::rollBack();
    $verify((int) DB::table('roles')->where('id', $role->id)->value('version') === $initialVersion, 'Read-only role version is unchanged after the rollback-only check');
    $verify(DB::table('role_permissions')->where('role_id', $role->id)->orderBy('permission_code')->pluck('permission_code')->all() === $initialPermissions, 'Original ManagerReadOnly permission matrix is preserved');
    echo "Supplier read-only role checks passed.\n";
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    throw $exception;
}

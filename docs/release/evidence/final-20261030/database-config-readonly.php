<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('select current_database() as db, inet_server_port() as port');
if (DB::getDriverName() !== 'pgsql' || $identity->db !== 'erp_elec_new' || (int) $identity->port !== 5443) {
    throw new RuntimeException('Read-only configuration receipt requires the explicitly identified development database.');
}
$role = DB::selectOne('select rolsuper as superuser, rolcreatedb as can_create_databases, rolcreaterole as can_create_roles, rolreplication as replication from pg_roles where rolname=current_user');
$settings = [];
foreach (['server_version','ssl','password_encryption','statement_timeout','lock_timeout','idle_in_transaction_session_timeout'] as $name) {
    $settings[$name] = DB::selectOne('select current_setting(?) as value', [$name])->value;
}
$ssl = DB::selectOne('select ssl from pg_stat_ssl where pid=pg_backend_pid()');
$result = ['captured_utc'=>gmdate(DATE_ATOM),'scope'=>'read-only development metadata; no role names, passwords or connection secrets emitted','database'=>$identity->db,'port'=>(int)$identity->port,'current_application_role_privilege_flags'=>$role,'server_settings'=>$settings,'connection_uses_ssl'=>(bool)($ssl->ssl??false)];
file_put_contents(__DIR__.'/database-config-readonly.json', json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;

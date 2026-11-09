<?php

// Owner-authorized isolated negative audit. This deliberately records failed security expectations.
use App\Models\User;
use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialReports;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$app = require dirname(__DIR__, 4).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$identity = DB::selectOne('select current_database() as name, inet_server_addr()::text as host, inet_server_port() as port');
if (! app()->environment('testing') || DB::getDriverName() !== 'pgsql' || $identity->name !== 'erp_elec_new_test' || $identity->host !== '127.0.0.1/32' && $identity->host !== '127.0.0.1' || (int)$identity->port !== 55485) {
    throw new RuntimeException('Refusing financial audit outside the disposable 127.0.0.1:55485 PostgreSQL test clone.');
}
$tables = ['users','access_grants','user_roles','user_branch_scopes','accounting_periods','accounting_journals','accounting_journal_lines','audit_entries'];
$counts = static fn () => array_combine($tables, array_map(static fn ($t) => DB::table($t)->count(), $tables));
$before = $counts();
$result = ['captured_utc'=>now()->toIso8601String(),'database'=>'erp_elec_new_test@127.0.0.1:55485','source'=>'app/Modules/Accounting/Application/FinancialReports.php','source_sha256'=>hash_file('sha256',base_path('app/Modules/Accounting/Application/FinancialReports.php')),'findings'=>[]];
DB::beginTransaction();
try {
    $closer = User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    foreach (['M11.period_open','M11.period_close'] as $permission) {
        DB::table('access_grants')->insert(['user_id'=>$closer->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    }
    $visible = app(AccountingAccess::class)->branches($closer->id,'report_view');
    if ($visible !== []) { throw new RuntimeException('Fixture unexpectedly has report authority.'); }
    $period = app(AccountingActions::class)->period($closer->id,['name'=>'ISOLATED-AUDIT-NO-REPORT-SCOPE','starts_on'=>'2035-01-01','ends_on'=>'2035-12-31']);
    $denied = false;
    try { app(FinancialReports::class)->closePeriod($closer->id,$period); }
    catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { $denied = $e->getStatusCode() === 403; }
    $closed = DB::table('accounting_periods')->where('id',$period)->value('status') === 'closed';
    $result['findings'][] = ['id'=>'FIN-P0-01','expectation'=>'Global close must reconcile every company branch and reject missing reconciliation authority.','test_result'=>$denied&&!$closed?'PASS':'FAIL','visible_report_branches'=>count($visible),'actual_period_status'=>DB::table('accounting_periods')->where('id',$period)->value('status'),'reproduced'=>$closed];

    $viewer = User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    $role = DB::table('roles')->where('code','Administrator')->first();
    if (! $role || ! DB::table('branches')->where('id',1)->exists()) { throw new RuntimeException('Missing isolated report prerequisites.'); }
    DB::table('user_roles')->insert(['user_id'=>$viewer->id,'role_id'=>$role->id,'created_at'=>now(),'updated_at'=>now()]);
    DB::table('user_branch_scopes')->insert(['user_id'=>$viewer->id,'branch_id'=>1,'created_at'=>now(),'updated_at'=>now()]);
    $cutoff='2000-12-31';
    $oldJournals=DB::table('accounting_journals')->where('branch_id',1)->where('journal_date','<=',$cutoff)->count();
    if ($oldJournals!==0) { throw new RuntimeException('Fixture has historical journals before the audit cutoff.'); }
    $rows=app(FinancialReports::class)->reconciliations($viewer->id,1,$cutoff);
    $inventory=collect($rows)->firstWhere('key','inventory');
    $mixed=$inventory && bccomp($inventory['gl'],'0',2)===0 && bccomp($inventory['source'],'0',2)!==0;
    $result['findings'][]=['id'=>'FIN-P0-02','expectation'=>'Historical GL and FIFO/subledgers must use the same requested cutoff.','test_result'=>$mixed?'FAIL':'NOT REPRODUCED','cutoff'=>$cutoff,'journal_count_before_cutoff'=>$oldJournals,'inventory_reconciliation'=>$inventory,'reproduced'=>$mixed,'scope'=>'This directly verifies the FIFO cutoff mismatch; AP/AR/GRNI missing-cutoff contracts are also visible in source.'];
} finally {
    DB::rollBack();
    $result['rollback_verified']=$counts()===$before;
}
$result['result']=collect($result['findings'])->contains(fn($f)=>$f['test_result']==='FAIL')?'FAIL':'PASS';
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['result']==='FAIL'?1:0);

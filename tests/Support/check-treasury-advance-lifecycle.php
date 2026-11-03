<?php

declare(strict_types=1);

// Gate5: real M10/M12/Treasury source actions in a guarded outer rollback.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\{AccountingActions, FinancialActivation, OpeningBalances};
use App\Modules\Organization\Domain\{Branch, Company, Employee};
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\{ServiceContractActions, ServiceContractBilling, ServiceContractDocuments};
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
$evidence = $root.'/docs/release/remediation/evidence/gate5';
$taskRoot = $root.'/.tmp/master-remediation-20261101';
$tagRuntime = bin2hex(random_bytes(8));
$privateRoot = $taskRoot.'/treasury-advance-private-'.$tagRuntime;
$stage = 'source preflight';
$checks = [];
$assertions = 0;
$emit = static function (array $receipt) use ($evidence): string {
    if (! is_dir($evidence) && ! mkdir($evidence, 0770, true) && ! is_dir($evidence)) throw new RuntimeException('Unable to create Gate5 evidence directory.');
    $path = $evidence.'/treasury-advance-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, &$checks, &$assertions, $emit): void {
    $receipt = ['all_pass'=>false,'mode'=>'treasury-advance-lifecycle','phase'=>$stage,'assertions'=>$assertions,'rollback_verified'=>false,'checks'=>$checks,
        'failure'=>['class'=>$error::class,'message'=>mb_substr((string)preg_replace('/(password|secret|token)=\S+/i','$1=[redacted]',$error->getMessage()),0,500)]];
    try { $path=$emit($receipt); fwrite(STDERR,json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR,'RESULT all_pass=0 assertions='.$assertions.' rollback_verified=0 receipt='.$path.PHP_EOL);
    } catch(Throwable) { fwrite(STDERR,"RESULT all_pass=0 assertions={$assertions} rollback_verified=0 receipt_write_failed=1\n"); }
    exit(1);
});
putenv('APP_ENV=testing'); $_ENV['APP_ENV']=$_SERVER['APP_ENV']='testing'; require $root.'/vendor/autoload.php';
if(!method_exists(ReceivablesPayablesApi::class,'applyCustomerAdvance')||!method_exists(TreasuryApi::class,'refundCustomerAdvance')) throw new RuntimeException('M12 advance application and M13 refund APIs are incomplete.');
$environment=['APP_ENV'=>'testing','GITHUB_ACTIONS'=>(string)(getenv('GITHUB_ACTIONS')?:''),'DB_HOST'=>(string)(getenv('DB_HOST')?:'127.0.0.1'),
    'DB_PORT'=>(string)(getenv('E2E_DB_PORT')?:(getenv('DB_PORT')?:'49685')),'DB_DATABASE'=>(string)(getenv('DB_DATABASE')?:'erp_elec_new_test'),
    'DB_USERNAME'=>(string)(getenv('DB_USERNAME')?:'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target=Tests\Support\OptimizationRegressionDatabaseGuard::target($root,$environment);
$cache='.tmp/master-remediation-20261101/treasury-advance-cache-'.$tagRuntime;
$views='.tmp/master-remediation-20261101/treasury-advance-views-'.$tagRuntime;
$storage=$taskRoot.'/treasury-advance-storage-'.$tagRuntime;
foreach(['DB_CONNECTION'=>'pgsql','DB_HOST'=>$target['connection_host'],'DB_PORT'=>(string)$target['port'],'DB_DATABASE'=>$target['database'],'DB_USERNAME'=>$target['username'],
    'DB_PASSWORD'=>(string)(getenv('DB_PASSWORD')?:''),'DB_URL'=>'','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH'=>$storage,'APP_CONFIG_CACHE'=>$cache.'/config.php','APP_ROUTES_CACHE'=>$cache.'/routes.php','APP_EVENTS_CACHE'=>$cache.'/events.php',
    'APP_SERVICES_CACHE'=>$cache.'/services.php','APP_PACKAGES_CACHE'=>$cache.'/packages.php','APP_VIEW_COMPILED_PATH'=>$views,'VIEW_COMPILED_PATH'=>$views,
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BROADCAST_CONNECTION'=>'null','DEBUGBAR_ENABLED'=>'false','PULSE_ENABLED'=>'false'] as $key=>$value){
    putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value;
}
foreach([$privateRoot,$storage,$taskRoot.'/treasury-advance-cache-'.$tagRuntime,$taskRoot.'/treasury-advance-views-'.$tagRuntime] as $dir)
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) throw new RuntimeException('Could not create private Gate5 runtime directory.');
$app=require $root.'/bootstrap/app.php'; $app->loadEnvironmentFrom('.env.testing'); $app->useStoragePath($storage); $app->make(Kernel::class)->bootstrap();
config(['database.default'=>'pgsql','database.connections.pgsql.host'=>$target['connection_host'],'database.connections.pgsql.port'=>$target['port'],
    'database.connections.pgsql.database'=>$target['database'],'database.connections.pgsql.username'=>$target['username'],
    'database.connections.pgsql.password'=>(string)(getenv('DB_PASSWORD')?:''),'database.connections.pgsql.url'=>null,
    'filesystems.disks.local.root'=>$privateRoot,'filesystems.disks.local.visibility'=>'private','filesystems.disks.local.throw'=>true,'cache.default'=>'array','session.driver'=>'array']);
DB::purge('pgsql');
$identity=DB::selectOne("select current_database() as database,host(inet_server_addr()) as server_address,inet_server_port() as port,current_setting('data_directory') as data_directory,current_user as current_user,current_setting('server_version_num') as server_version_num,(select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow=$target['ci_receipt']!==null?DB::selectOne('select nonce from codex_test_guard.ownership_receipts'):null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target,$identity,$receiptRow);
if((int)$identity->server_version_num<170000||(int)$identity->server_version_num>=180000) throw new RuntimeException('Gate5 requires the explicitly owned PostgreSQL 17 target.');
$pass=static function(bool $condition,string $name)use(&$assertions,&$checks):void{$assertions++;$checks[]=['name'=>$name,'result'=>$condition?'PASS':'FAIL'];if(!$condition)throw new RuntimeException($name);};
$reject=static function(callable $action,string $name)use($pass):void{try{$action();}catch(ValidationException|HttpExceptionInterface){$pass(true,$name);return;}$pass(false,$name);};
$uuid=static fn():string=>(string)Str::uuid();
$grant=static function(User $actor,array $permissions,int $branch):void{foreach($permissions as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$actor->id,'permission_code'=>$permission,'branch_id'=>$branch,'created_at'=>now(),'updated_at'=>now()]);};
$scope=static function(User $actor,int $branch):void{DB::table('user_branch_scopes')->insertOrIgnore(['user_id'=>$actor->id,'branch_id'=>$branch,'created_at'=>now(),'updated_at'=>now()]);};
$tables=['companies','branches','users','customers','products','organization_employees','service_contracts','service_contract_revisions','service_contract_documents',
    'service_contract_billing_sources','service_contract_billing_allocations','rp_customer_invoices','rp_customer_invoice_lines','rp_open_items','rp_customer_advances',
    'rp_customer_advance_applications','rp_customer_advance_application_lines','rp_customer_advance_refunds','treasury_accounts','treasury_movements','rp_settlement_allocations',
    'accounting_accounts','accounting_account_mappings','accounting_tax_rules','accounting_periods','accounting_opening_batches','accounting_opening_lines',
    'accounting_journals','accounting_journal_lines','system_official_sequences','system_official_documents','access_grants','user_branch_scopes','audit_entries'];
$counts=static function()use($tables):array{$out=[];foreach($tables as $table)if(DB::getSchemaBuilder()->hasTable($table))$out[$table]=(int)DB::table($table)->count();return $out;};
$before=$counts(); $activationBefore=DB::table('accounting_activation')->where('id',1)->first();$companyBefore=DB::table('companies')->where('id',1)->first(); if(!$activationBefore)throw new RuntimeException('M11 activation row is missing.');
$during=[];$activationDuring=null;$failure=null;$failureTrace=[];$stage='rollback-only source fixture and M11 setup';DB::beginTransaction();
try{
    if(!$companyBefore)Company::factory()->create(['id'=>1,'name'=>'Synthetic advance acceptance entity','legal_name'=>'Synthetic advance acceptance entity Ltd.','address'=>'Rollback-only fixture address','setup_complete'=>true]);
    else DB::table('companies')->where('id',1)->update(['legal_name'=>'Synthetic advance acceptance entity Ltd.','address'=>'Rollback-only fixture address','setup_complete'=>true,'updated_at'=>now()]);
    $tag='DEMO-EG-ADV-'.Str::upper(Str::random(6));$branch=Branch::factory()->create(['company_id'=>1,'code'=>$tag]);
    $customer=Customer::factory()->create(['status'=>'active']);$service=Product::factory()->create(['kind'=>'service','tracking'=>'none','active'=>true]);
    $maker=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);$approver=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    $cashier=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);$denied=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    foreach([$maker,$approver,$cashier,$denied] as $actor)$scope($actor,(int)$branch->id);
    $foreignBranch=Branch::factory()->create(['company_id'=>1,'code'=>$tag.'-OTHER']);$foreignActor=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    $scope($foreignActor,(int)$foreignBranch->id);$grant($foreignActor,['M12.ar_view','M12.advance_apply','M13.advance_refund'],(int)$foreignBranch->id);
    $grant($maker,['M01.employee_manage','M01.employee_view','M03.customer_view','M02.product_view','M10.contract_create','M10.contract_edit','M10.contract_sign','M10.contract_activate','M10.contract_view','M10.document_manage','M10.billing_manage','M12.customer_invoice_manage','M11.account_manage','M11.setup_manage','M11.tax_manage','M11.period_open','M11.opening_manage','M11.tax_calculate','M11.source_post','M11.journal_create','M11.journal_submit','M11.journal_approve','M11.journal_post','M13.account_manage'],(int)$branch->id);
    $grant($approver,['M01.employee_view','M03.customer_view','M02.product_view','M10.contract_view','M10.contract_approve','M10.contract_activate','M10.billing_approve','M12.customer_invoice_manage','M12.customer_invoice_post','M12.ar_view','M11.report_view','M11.tax_calculate','M11.source_post','M11.opening_approve','M11.financial_activate','M11.journal_approve','M11.journal_post'],(int)$branch->id);
    $grant($cashier,['M03.customer_view','M12.ar_view','M12.advance_apply','M13.collection_execute','M13.advance_refund','M11.source_post','M11.journal_post','M11.report_view'],(int)$branch->id);
    foreach([$maker,$approver] as $reader)foreach(['M03.customer_view','M02.product_view'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$reader->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    foreach(['M11.account_manage','M11.setup_manage','M11.tax_manage','M11.period_open','M11.opening_manage','M11.tax_calculate','M11.source_post','M11.journal_create','M11.journal_submit','M11.journal_approve','M11.journal_post','M13.account_manage'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$maker->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    foreach(['M11.report_view','M11.tax_calculate','M11.source_post','M11.opening_approve','M11.financial_activate','M11.journal_approve','M11.journal_post'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$approver->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    foreach(['M03.customer_view','M11.report_view','M11.source_post','M11.journal_post'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$cashier->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    $today=now('Africa/Cairo')->toDateString();Carbon::setTestNow(CarbonImmutable::parse($today,'Africa/Cairo')->startOfDay()->addHours(12));
    $accountCode=static fn(string $purpose):string=>'ADV-'.substr(hash('sha256',$tag.':'.$purpose),0,24);
    $employee=Employee::factory()->create(['branch_id'=>$branch->id,'active'=>true,'starts_on'=>$today]);$setup=app(FinancialActivation::class);$accounts=app(AccountingActions::class);$mappings=[];
    foreach(FinancialActivation::MAPPINGS as $purpose=>[$label,$kind])$mappings[$purpose]=$accounts->account((int)$maker->id,null,0,['code'=>$accountCode($purpose),'name'=>'Synthetic advance '.$label,'kind'=>$kind,'parent_id'=>null,'postable'=>true,'active'=>true]);
    $version=(int)$activationBefore->version;$setup->save((int)$maker->id,$version,['go_live_date'=>$today,'mappings'=>$mappings]);$version++;
    foreach([['sale',null,'SALE','percentage'],['purchase',null,'PURCHASE','percentage'],['sale',$service->id,'SERVICE','exempt']] as [$type,$productId,$suffix,$treatment]){
        $setup->addTaxRule((int)$maker->id,$version,['code'=>$tag.'-'.$suffix,'name'=>'Synthetic '.$suffix,'transaction_type'=>$type,'product_id'=>$productId,'rate'=>'0','starts_on'=>$today,'ends_on'=>null,'treatment'=>$treatment,'tax_scale'=>2,'legal_reference'=>'Rollback-only synthetic tax rule','purchase_recoverable'=>$type==='purchase']);$version++;}
    $setup->validateTaxTreatment((int)$maker->id,$version,'Rollback-only synthetic advance tax fixture.');$version++;
    $accounts->period((int)$maker->id,['name'=>'Synthetic advance '.$tag,'starts_on'=>substr($today,0,4).'-01-01','ends_on'=>substr($today,0,4).'-12-31']);
    $opening=app(OpeningBalances::class);$batch=$opening->save((int)$maker->id,$version,$uuid(),[],true,'Explicit rollback-only synthetic no-opening declaration for advance acceptance.');
    $opening->advance((int)$maker->id,$batch,1,'submit');$opening->advance((int)$approver->id,$batch,2,'approve');app(ActivateFinancialPosting::class)->execute((int)$approver->id,$batch,3);
    $activationDuring=DB::table('accounting_activation')->where('id',1)->first();
    $pass($activationDuring?->activated_at!==null&&DB::table('accounting_opening_batches')->where('id',$batch)->where('status','posted')->where('no_balances',true)->exists(),'Synthetic M11 uses real setup and independent no-opening approval inside rollback.');
    foreach(['customer_advance'=>'Synthetic customer advance control','service_contract_deferred'=>'Synthetic contract deferred revenue'] as $purpose=>$name){$id=$accounts->account((int)$maker->id,null,0,['code'=>$accountCode($purpose),'name'=>$name,'kind'=>'liability','parent_id'=>null,'postable'=>true,'active'=>true]);$setup->saveOptionalMapping((int)$maker->id,$purpose,$id);}
    $cashGl=(int)DB::table('accounting_account_mappings')->where('purpose','cash')->value('account_id');
    $cashAccount=app(TreasuryApi::class)->saveOperationalAccount((int)$maker->id,['code'=>$tag.'-CASH','name'=>'Synthetic advance cash account','kind'=>'cash','gl_account_id'=>$cashGl]);
    $terms=['branch_id'=>$branch->id,'customer_id'=>$customer->id,'responsible_employee_id'=>$employee->id,'title'=>'عقد اختبار تطبيق دفعة مقدمة','starts_on'=>$today,'ends_on'=>now('Africa/Cairo')->addMonths(1)->toDateString(),'value'=>'100.00','service_product_id'=>$service->id,'billing_mode'=>'installments','pricing_tax_mode'=>'exclusive','terms'=>'شروط مالية اصطناعية محلية قابلة للتراجع.','sla_response_minutes'=>60,'sla_resolution_minutes'=>120,'covered_labor_minutes'=>0,'coverage'=>[['equipment_ref'=>$tag.'-EQ','site_id'=>null,'preventive_visits'=>0,'corrective_visits'=>1]],'parts'=>[],'excluded_parts'=>[],'boq'=>[],'schedule'=>[['code'=>'ADV-INV-01','due_date'=>$today,'amount'=>'100.00','description'=>'فاتورة خدمة اختبار']]];
    $contractActions=app(ServiceContractActions::class);$contract=$contractActions->save((int)$maker->id,null,0,$uuid(),$terms);
    $signature=app(ServiceContractDocuments::class)->store((int)$maker->id,$contract,UploadedFile::fake()->createWithContent('advance-signature.pdf',"%PDF-1.4\nSynthetic signature\n%%EOF"),'signature','Rollback-only advance acceptance');
    $row=DB::table('service_contracts')->find($contract);$contractActions->submit((int)$maker->id,$contract,(int)$row->version);$row=DB::table('service_contracts')->find($contract);$contractActions->approve((int)$approver->id,$contract,(int)$row->version);$row=DB::table('service_contracts')->find($contract);$contractActions->sign((int)$maker->id,$contract,(int)$row->version,$signature,$today);$row=DB::table('service_contracts')->find($contract);$contractActions->activate((int)$approver->id,$contract,(int)$row->version);
    $billing=app(ServiceContractBilling::class);$source=$billing->proposeInstallment((int)$maker->id,$contract,'ADV-INV-01',$uuid(),['invoice_date'=>$today,'due_date'=>$today,'tax_mode'=>'exclusive','reference'=>'Synthetic later customer invoice','amount'=>'100.00']);$billing->approveSource((int)$approver->id,$source,1);$invoiceId=$billing->invoice((int)$maker->id,$source,$uuid());
    $rp=app(ReceivablesPayablesApi::class);$invoice=DB::table('rp_customer_invoices')->find($invoiceId);$rp->validateCustomerInvoice((int)$approver->id,$invoiceId,(int)$invoice->version);$invoice=DB::table('rp_customer_invoices')->find($invoiceId);$postedInvoice=$rp->postCustomerInvoice((int)$approver->id,$invoiceId,(int)$invoice->version);
    $openId=(int)DB::table('rp_open_items')->where('source_type','customer_invoice')->where('source_id',$invoiceId)->value('id');
    $pass($postedInvoice['journal_id']>0&&DB::table('rp_open_items')->where('id',$openId)->where('remaining_amount','100.00')->exists(),'Later M12 invoice posts through a real M10 contract source.');

    $stage='Treasury collection creates a customer advance';$treasury=app(TreasuryApi::class);$collectionKey=$uuid();
    $collection=$treasury->executeCustomerCollection((int)$cashier->id,['idempotency_key'=>$collectionKey,'branch_id'=>(int)$branch->id,'customer_id'=>(int)$customer->id,
        'account_id'=>$cashAccount,'date'=>$today,'amount'=>'50.00','reference'=>'Synthetic advance source collection','allow_advance'=>true],[]);
    $advanceId=(int)DB::table('rp_customer_advances')->where('movement_id',$collection['movement_id'])->value('id');
    $pass($advanceId>0&&DB::table('rp_customer_advances')->where('id',$advanceId)->where('original_amount','50.00')->exists()
        &&DB::table('treasury_movements')->where('id',$collection['movement_id'])->where('kind','customer_collection')->exists(),
        'A real Treasury collection creates the source-bound customer advance.');

    $stage='dated history and advance application';$rpHistory=app(ReceivablesPayablesApi::class);
    $historyBefore= $rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],CarbonImmutable::parse($today,'Africa/Cairo')->subDay()->toDateString());
    $pass($historyBefore['customer_advances']==='0.00'&&$historyBefore['ar']==='0.00','As-of history excludes sources dated after the requested cutoff.');
    $appKey=$uuid();$application=$rpHistory->applyCustomerAdvance((int)$cashier->id,$advanceId,$appKey,$today,[['open_item_id'=>$openId,'amount'=>'15.00']]);
    $applicationReplay=$rpHistory->applyCustomerAdvance((int)$cashier->id,$advanceId,$appKey,$today,[['open_item_id'=>$openId,'amount'=>'15.00']]);
    $pass($application===$applicationReplay&&$application['amount']==='15.00'
        &&DB::table('rp_customer_advance_applications')->where('id',$application['application_id'])->count()===1,
        'Same-payload advance application replay returns the original immutable application.');
    $snapshot=['applications'=>(int)DB::table('rp_customer_advance_applications')->count(),'lines'=>(int)DB::table('rp_customer_advance_application_lines')->count(),
        'journals'=>(int)DB::table('accounting_journals')->count(),'settled'=>(string)DB::table('rp_open_items')->where('id',$openId)->value('settled_amount')];
    $reject(fn()=>$rpHistory->applyCustomerAdvance((int)$cashier->id,$advanceId,$appKey,$today,[['open_item_id'=>$openId,'amount'=>'14.00']]),'Changed application payload under the same key is rejected.');
    $reject(fn()=>$rpHistory->applyCustomerAdvance((int)$cashier->id,$advanceId,$uuid(),CarbonImmutable::parse($today,'Africa/Cairo')->subDay()->toDateString(),[['open_item_id'=>$openId,'amount'=>'1.00']]),'Application dated before its source collection is rejected.');
    $reject(fn()=>$rpHistory->applyCustomerAdvance((int)$cashier->id,$advanceId,$uuid(),$today,[['open_item_id'=>$openId,'amount'=>'36.00']]),'Application above the remaining advance balance is rejected.');
    $reject(fn()=>$rpHistory->applyCustomerAdvance((int)$denied->id,$advanceId,$uuid(),$today,[['open_item_id'=>$openId,'amount'=>'1.00']]),'Actor without M12 advance authority is denied.');
    $reject(fn()=>$rpHistory->applyCustomerAdvance((int)$foreignActor->id,$advanceId,$uuid(),$today,[['open_item_id'=>$openId,'amount'=>'1.00']]),'M12 advance application cannot cross branch scope.');
    $pass($snapshot===['applications'=>(int)DB::table('rp_customer_advance_applications')->count(),'lines'=>(int)DB::table('rp_customer_advance_application_lines')->count(),
        'journals'=>(int)DB::table('accounting_journals')->count(),'settled'=>(string)DB::table('rp_open_items')->where('id',$openId)->value('settled_amount')],
        'Application conflicts, over-cap and authorization denials leave no partial journal or settlement state.');

    $stage='Treasury refund lifecycle';$refundKey=$uuid();$refund=$treasury->refundCustomerAdvance((int)$cashier->id,$advanceId,$cashAccount,'20.00',$today,$refundKey,'Synthetic partial advance refund');
    $refundReplay=$treasury->refundCustomerAdvance((int)$cashier->id,$advanceId,$cashAccount,'20.00',$today,$refundKey,'Synthetic partial advance refund');
    $pass($refund['movement_id']===$refundReplay['movement_id']&&$refund['journal_id']===$refundReplay['journal_id']
        &&DB::table('rp_customer_advance_refunds')->where('movement_id',$refund['movement_id'])->where('amount','20.00')->count()===1,
        'Same-payload refund replay returns the original Treasury movement and journal.');
    $snapshot=['refunds'=>(int)DB::table('rp_customer_advance_refunds')->count(),'movements'=>(int)DB::table('treasury_movements')->count(),
        'journals'=>(int)DB::table('accounting_journals')->count(),'advance'=>$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today)['customer_advances']];
    $reject(fn()=>$treasury->refundCustomerAdvance((int)$cashier->id,$advanceId,$cashAccount,'19.00',$today,$refundKey,'Synthetic partial advance refund'),'Changed refund payload under the same key is rejected.');
    $reject(fn()=>$treasury->refundCustomerAdvance((int)$cashier->id,$advanceId,$cashAccount,'16.00',$today,$uuid(),'Synthetic over-cap advance refund'),'Refund above the remaining advance balance is rejected.');
    $reject(fn()=>$treasury->refundCustomerAdvance((int)$denied->id,$advanceId,$cashAccount,'1.00',$today,$uuid(),'Synthetic unauthorized advance refund'),'Actor without M13 advance-refund authority is denied.');
    $reject(fn()=>$treasury->refundCustomerAdvance((int)$foreignActor->id,$advanceId,$cashAccount,'1.00',$today,$uuid(),'Synthetic cross-branch advance refund'),'M13 advance refund cannot cross branch scope.');
    $pass($snapshot===['refunds'=>(int)DB::table('rp_customer_advance_refunds')->count(),'movements'=>(int)DB::table('treasury_movements')->count(),
        'journals'=>(int)DB::table('accounting_journals')->count(),'advance'=>$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today)['customer_advances']],
        'Refund conflicts, over-cap and authorization denials leave no partial Treasury or journal state.');

    $stage='source GL and subledger reconciliation';$advanceGl=(int)DB::table('accounting_account_mappings')->where('purpose','customer_advance')->value('account_id');
    $receivableGl=(int)DB::table('accounting_account_mappings')->where('purpose','receivable')->value('account_id');$cashGl=(int)DB::table('treasury_accounts')->where('id',$cashAccount)->value('gl_account_id');
    $lineAmount=static function(int $journal,int $account,string $side):string{return (string)DB::table('accounting_journal_lines')->where('journal_id',$journal)->where('account_id',$account)->value($side);};
    $collectionJournal=(int)$collection['journal_id'];$applicationJournal=(int)$application['journal_id'];$refundJournal=(int)$refund['journal_id'];
    $pass(DB::table('accounting_journals')->where('id',$collectionJournal)->where('source_key','customer-collection:'.$collectionKey)->where('status','posted')->exists()
        &&$lineAmount($collectionJournal,$cashGl,'debit')==='50.00'&&$lineAmount($collectionJournal,$advanceGl,'credit')==='50.00',
        'Collection source journal reconciles cash debit to customer-advance credit.');
    $pass(DB::table('accounting_journals')->where('id',$applicationJournal)->where('source_key','customer-advance-application:'.$appKey)->where('status','posted')->exists()
        &&$lineAmount($applicationJournal,$advanceGl,'debit')==='15.00'&&$lineAmount($applicationJournal,$receivableGl,'credit')==='15.00',
        'Application source journal releases the advance against receivables.');
    $pass(DB::table('accounting_journals')->where('id',$refundJournal)->where('source_key','customer-advance-refund:'.$refundKey)->where('status','posted')->exists()
        &&$lineAmount($refundJournal,$advanceGl,'debit')==='20.00'&&$lineAmount($refundJournal,$cashGl,'credit')==='20.00',
        'Refund source journal reduces advance liability and cash.');
    $historyAfter=$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today);
    $pass($historyAfter['customer_advances']==='15.00'&&$historyAfter['ar']==='85.00'
        &&DB::table('rp_open_items')->where('id',$openId)->where('settled_amount','15.00')->exists(),
        'Dated M12 controls reconcile net advance 15.00 and receivable 85.00 after the actual source chain.');
    $pass(DB::table('rp_customer_advance_applications')->where('advance_id',$advanceId)->count()===1
        &&DB::table('rp_customer_advance_application_lines')->where('application_id',$application['application_id'])->where('open_item_id',$openId)->where('amount','15.00')->exists()
        &&DB::table('rp_customer_advance_refunds')->where('advance_id',$advanceId)->count()===1,
        'Immutable lifecycle history preserves one application line and one partial refund.');
    $during=$counts();
}catch(Throwable $error){$failure=$error;foreach(array_slice($error->getTrace(),0,16) as $frame){$file=isset($frame['file'])?str_replace('\\','/',$frame['file']):null;if($file!==null&&str_starts_with(strtolower($file),strtolower(str_replace('\\','/',$root).'/')))$file=substr($file,strlen(str_replace('\\','/',$root))+1);elseif($file!==null)$file=basename($file);$failureTrace[]=['file'=>$file,'line'=>$frame['line']??null,'class'=>$frame['class']??null,'function'=>$frame['function']??null];}}
finally{
    if(DB::transactionLevel()>0)DB::rollBack();
    Carbon::setTestNow();
    foreach([$privateRoot,$storage,$taskRoot.'/treasury-advance-cache-'.$tagRuntime,$taskRoot.'/treasury-advance-views-'.$tagRuntime] as $dir){$base=basename($dir);if(is_dir($dir)&&realpath(dirname($dir))===realpath($taskRoot)&&str_starts_with($base,'treasury-advance-'))(new Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);}
}
$after=$counts();$activationAfter=DB::table('accounting_activation')->where('id',1)->first();$companyAfter=DB::table('companies')->where('id',1)->first();
$companyRestored=($companyBefore===null&&$companyAfter===null)||(is_object($companyBefore)&&is_object($companyAfter)&&(array)$companyBefore===(array)$companyAfter);
$scratchRemoved=!is_dir($privateRoot)&&!is_dir($storage)&&!is_dir($taskRoot.'/treasury-advance-cache-'.$tagRuntime)&&!is_dir($taskRoot.'/treasury-advance-views-'.$tagRuntime);
$pass($before===$after&&$scratchRemoved&&$companyRestored,'All synthetic customer, M10, M11, M12 and Treasury records, company profile and private scratch roll back.');
$pass($activationBefore==$activationAfter,'Synthetic M11 activation and version roll back to the owned database state.');
$receipt=['all_pass'=>$failure===null&&count(array_filter($checks,fn($check)=>$check['result']!=='PASS'))===0,'mode'=>'treasury-advance-lifecycle','phase'=>$stage,
    'assertions'=>$assertions,'rollback_verified'=>$before===$after&&$activationBefore==$activationAfter&&$companyRestored&&$scratchRemoved,'database'=>['name'=>$identity->database,'server_address'=>$identity->server_address,'port'=>(int)$identity->port,
        'postgres_major'=>intdiv((int)$identity->server_version_num,10000)],'counts'=>['before'=>$before,'during_fixture'=>$during,'after_rollback'=>$after],'checks'=>$checks,
    'evidence'=>['synthetic_sources'=>'real M11 setup and no-opening approval, M10 contract/invoice, M13 collection/refund, and M12 advance application inside outer rollback',
        'synthetic_m11_activation'=>['performed'=>$activationDuring!==null,'rolled_back'=>$activationBefore==$activationAfter,'go_live_date'=>$activationDuring?->go_live_date,'zero_opening'=>true],
        'company_profile'=>['synthetic_fields_set_inside_transaction'=>true,'exact_baseline_restored'=>$companyRestored],
        'failure_trace'=>$failureTrace]];
if($failure){$receipt['failure']=['class'=>$failure::class,'message'=>mb_substr((string)preg_replace('/(password|secret|token)=\S+/i','$1=[redacted]',$failure->getMessage()),0,500)];}
$path=$emit($receipt);fwrite(($receipt['all_pass']?STDOUT:STDERR),json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
fwrite(($receipt['all_pass']?STDOUT:STDERR),'RESULT all_pass='.($receipt['all_pass']?'1':'0').' assertions='.$assertions.' rollback_verified='.($receipt['rollback_verified']?'1':'0').' receipt='.$path.PHP_EOL);
if(!$receipt['all_pass'])exit(1);

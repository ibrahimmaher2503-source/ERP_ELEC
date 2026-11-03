<?php

declare(strict_types=1);

// Gate5: source-linked Treasury refunds/corrections in a guarded outer rollback.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\{AccountingActions, FinancialActivation, OpeningBalances};
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Domain\{Branch, Company, Employee};
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Parties\Domain\{Customer, Supplier};
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\{PurchaseOrders, PurchaseReceiving};
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
$privateRoot = $taskRoot.'/treasury-credit-refunds-private-'.$tagRuntime;
$stage = 'source preflight';
$checks = [];
$assertions = 0;
$emit = static function (array $receipt) use ($evidence): string {
    if (! is_dir($evidence) && ! mkdir($evidence, 0770, true) && ! is_dir($evidence)) throw new RuntimeException('Unable to create Gate5 evidence directory.');
    $path = $evidence.'/treasury-credit-refunds-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
    return $path;
};
set_exception_handler(static function (Throwable $error) use (&$stage, &$checks, &$assertions, $emit): void {
    $receipt = ['all_pass'=>false,'mode'=>'treasury-credit-refunds-and-cash-corrections','phase'=>$stage,'assertions'=>$assertions,'rollback_verified'=>false,'checks'=>$checks,
        'failure'=>['class'=>$error::class,'message'=>mb_substr((string)preg_replace('/(password|secret|token)=\S+/i','$1=[redacted]',$error->getMessage()),0,500)]];
    try { $path=$emit($receipt); fwrite(STDERR,json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
        fwrite(STDERR,'RESULT all_pass=0 assertions='.$assertions.' rollback_verified=0 receipt='.$path.PHP_EOL);
    } catch(Throwable) { fwrite(STDERR,"RESULT all_pass=0 assertions={$assertions} rollback_verified=0 receipt_write_failed=1\n"); }
    exit(1);
});
putenv('APP_ENV=testing'); $_ENV['APP_ENV']=$_SERVER['APP_ENV']='testing'; require $root.'/vendor/autoload.php';
if(!method_exists(TreasuryApi::class,'refundPartyCredit')||!method_exists(TreasuryApi::class,'correctMovement')) throw new RuntimeException('M13 source-linked refund/correction APIs are incomplete.');
$environment=['APP_ENV'=>'testing','GITHUB_ACTIONS'=>(string)(getenv('GITHUB_ACTIONS')?:''),'DB_HOST'=>(string)(getenv('DB_HOST')?:'127.0.0.1'),
    'DB_PORT'=>(string)(getenv('E2E_DB_PORT')?:(getenv('DB_PORT')?:'49685')),'DB_DATABASE'=>(string)(getenv('DB_DATABASE')?:'erp_elec_new_test'),
    'DB_USERNAME'=>(string)(getenv('DB_USERNAME')?:'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target=Tests\Support\OptimizationRegressionDatabaseGuard::target($root,$environment);
$cache='.tmp/master-remediation-20261101/treasury-credit-refunds-cache-'.$tagRuntime;
$views='.tmp/master-remediation-20261101/treasury-credit-refunds-views-'.$tagRuntime;
$storage=$taskRoot.'/treasury-credit-refunds-storage-'.$tagRuntime;
foreach(['DB_CONNECTION'=>'pgsql','DB_HOST'=>$target['connection_host'],'DB_PORT'=>(string)$target['port'],'DB_DATABASE'=>$target['database'],'DB_USERNAME'=>$target['username'],
    'DB_PASSWORD'=>(string)(getenv('DB_PASSWORD')?:''),'DB_URL'=>'','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH'=>$storage,'APP_CONFIG_CACHE'=>$cache.'/config.php','APP_ROUTES_CACHE'=>$cache.'/routes.php','APP_EVENTS_CACHE'=>$cache.'/events.php',
    'APP_SERVICES_CACHE'=>$cache.'/services.php','APP_PACKAGES_CACHE'=>$cache.'/packages.php','APP_VIEW_COMPILED_PATH'=>$views,'VIEW_COMPILED_PATH'=>$views,
    'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','BROADCAST_CONNECTION'=>'null','DEBUGBAR_ENABLED'=>'false','PULSE_ENABLED'=>'false'] as $key=>$value){
    putenv($key.'='.$value); $_ENV[$key]=$_SERVER[$key]=$value;
}
foreach([$privateRoot,$storage,$taskRoot.'/treasury-credit-refunds-cache-'.$tagRuntime,$taskRoot.'/treasury-credit-refunds-views-'.$tagRuntime] as $dir)
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
$reject=static function(callable $action,string $name,?int $expectedStatus=null,?string $expectedMessage=null)use($pass):void{
    try{$action();}catch(ValidationException $error){$message=json_encode($error->errors(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:$error->getMessage();
        $pass(($expectedStatus===null||$error->status===$expectedStatus)&&($expectedMessage===null||str_contains($message,$expectedMessage)),$name);return;
    }catch(HttpExceptionInterface $error){$pass(($expectedStatus===null||$error->getStatusCode()===$expectedStatus)&&($expectedMessage===null||str_contains($error->getMessage(),$expectedMessage)),$name);return;
    }catch(ContractException $error){$pass(($expectedStatus===null&&$error->getCode()===403)||$expectedStatus===$error->getCode(),$name);return;}
    $pass(false,$name);
};
$uuid=static fn():string=>(string)Str::uuid();
$grant=static function(User $actor,array $permissions,int $branch):void{foreach($permissions as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$actor->id,'permission_code'=>$permission,'branch_id'=>$branch,'created_at'=>now(),'updated_at'=>now()]);};
$scope=static function(User $actor,int $branch):void{DB::table('user_branch_scopes')->insertOrIgnore(['user_id'=>$actor->id,'branch_id'=>$branch,'created_at'=>now(),'updated_at'=>now()]);};
$tables=['companies','branches','users','customers','suppliers','products','warehouses','user_warehouse_scopes','purchasing_orders','purchasing_order_lines','purchasing_receipts','purchasing_receipt_lines','inventory_movements','inventory_fifo_layers','inventory_balances','rp_supplier_invoices','rp_supplier_invoice_lines','rp_supplier_invoice_matches','service_contracts','service_contract_revisions','service_contract_documents',
    'service_contract_billing_sources','service_contract_billing_allocations','rp_customer_invoices','rp_customer_invoice_lines','rp_credit_notes','rp_party_credits','rp_party_credit_refunds','rp_open_items','rp_customer_advances',
    'rp_customer_advance_applications','rp_customer_advance_application_lines','rp_customer_advance_refunds','rp_cash_reversals','treasury_accounts','treasury_movements','rp_settlement_allocations',
    'accounting_accounts','accounting_account_mappings','accounting_tax_rules','accounting_periods','accounting_period_closures','accounting_opening_batches','accounting_opening_lines',
    'accounting_journals','accounting_journal_lines','treasury_payment_requests','system_official_sequences','system_official_documents','access_grants','user_branch_scopes','audit_entries'];
$counts=static function()use($tables):array{$out=[];foreach($tables as $table)if(DB::getSchemaBuilder()->hasTable($table))$out[$table]=(int)DB::table($table)->count();return $out;};
$before=$counts(); $activationBefore=DB::table('accounting_activation')->where('id',1)->first();$companyBefore=DB::table('companies')->where('id',1)->first(); if(!$activationBefore)throw new RuntimeException('M11 activation row is missing.');
$during=[];$activationDuring=null;$failure=null;$failureTrace=[];$stage='rollback-only source fixture and M11 setup';DB::beginTransaction();
try{
    if(!$companyBefore)Company::factory()->create(['id'=>1,'name'=>'Synthetic advance acceptance entity','legal_name'=>'Synthetic advance acceptance entity Ltd.','address'=>'Rollback-only fixture address','setup_complete'=>true]);
    else DB::table('companies')->where('id',1)->update(['legal_name'=>'Synthetic advance acceptance entity Ltd.','address'=>'Rollback-only fixture address','setup_complete'=>true,'updated_at'=>now()]);
    $tag='DEMO-EG-ADV-'.Str::upper(Str::random(6));$branch=Branch::factory()->create(['company_id'=>1,'code'=>$tag]);
    $customer=Customer::factory()->create(['status'=>'active']);$supplier=Supplier::factory()->create(['status'=>'active']);$service=Product::factory()->create(['kind'=>'service','tracking'=>'none','active'=>true]);
    $stockProduct=Product::factory()->create(['kind'=>'bulk','tracking'=>'quantity','active'=>true]);
    $maker=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);$approver=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    $cashier=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);$denied=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    foreach([$maker,$approver,$cashier,$denied] as $actor)$scope($actor,(int)$branch->id);
    $foreignBranch=Branch::factory()->create(['company_id'=>1,'code'=>$tag.'-OTHER']);$foreignActor=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);
    $scope($approver,(int)$foreignBranch->id);$grant($approver,['M11.report_view'],(int)$foreignBranch->id);
    $scope($foreignActor,(int)$foreignBranch->id);$grant($foreignActor,['M12.ar_view','M12.ap_view','M13.credit_refund','M13.cash_correct'],(int)$foreignBranch->id);
    DB::table('access_grants')->insertOrIgnore(['user_id'=>$foreignActor->id,'permission_code'=>'M03.customer_view','branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    $grant($maker,['M01.branch_view','M01.employee_manage','M01.employee_view','M03.customer_view','M03.supplier_view','M02.product_view','M05.order_view','M05.order_create','M05.order_submit','M05.receipt_view','M05.receipt_create','M06.warehouse_create','M06.warehouse_view','M06.receipt_create','M11.receipt_post','M10.contract_create','M10.contract_edit','M10.contract_sign','M10.contract_activate','M10.contract_view','M10.document_manage','M10.billing_manage','M12.customer_invoice_manage','M12.supplier_invoice_manage','M12.credit_note_manage','M12.ar_view','M12.ap_view','M13.payment_prepare','M13.payment_execute','M13.transfer_execute','M11.account_manage','M11.setup_manage','M11.tax_manage','M11.period_open','M11.opening_manage','M11.tax_calculate','M11.source_post','M11.journal_create','M11.journal_submit','M11.journal_approve','M11.journal_post','M13.account_manage'],(int)$branch->id);
    $grant($approver,['M01.branch_view','M01.employee_view','M03.customer_view','M03.supplier_view','M02.product_view','M05.order_view','M05.order_approve','M05.order_issue','M05.receipt_view','M05.receipt_post','M06.warehouse_view','M06.receipt_post','M11.receipt_post','M10.contract_view','M10.contract_approve','M10.contract_activate','M10.billing_approve','M12.customer_invoice_manage','M12.customer_invoice_post','M12.supplier_invoice_post','M12.credit_note_approve','M12.ar_view','M12.ap_view','M11.report_view','M11.period_close','M11.tax_calculate','M11.source_post','M11.opening_approve','M11.financial_activate','M11.journal_approve','M11.journal_post','M13.payment_approve'],(int)$branch->id);
    $grant($cashier,['M03.customer_view','M03.supplier_view','M12.ar_view','M12.ap_view','M12.advance_apply','M13.collection_execute','M13.payment_execute','M13.advance_refund','M13.credit_refund','M13.cash_correct','M11.source_post','M11.journal_post','M11.report_view'],(int)$branch->id);
    $grant($approver,['M13.cash_correct'],(int)$branch->id);
    $grant($denied,['M12.ar_view','M12.ap_view','M11.source_post'],(int)$branch->id);
    $noApCorrector=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);$scope($noApCorrector,(int)$branch->id);
    $grant($noApCorrector,['M13.cash_correct','M11.source_post'],(int)$branch->id);
    $noSourcePostCorrector=User::factory()->create(['active'=>true,'email_verified_at'=>now()]);$scope($noSourcePostCorrector,(int)$branch->id);
    $grant($noSourcePostCorrector,['M13.cash_correct','M12.ap_view'],(int)$branch->id);
    foreach([$maker,$approver] as $reader)foreach(['M03.customer_view','M03.supplier_view','M02.product_view'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$reader->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    foreach(['M01.branch_view','M06.warehouse_create','M06.warehouse_view','M11.account_manage','M11.setup_manage','M11.tax_manage','M11.period_open','M11.opening_manage','M11.tax_calculate','M11.source_post','M11.journal_create','M11.journal_submit','M11.journal_approve','M11.journal_post','M13.account_manage'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$maker->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    foreach(['M11.report_view','M11.period_close','M11.tax_calculate','M11.source_post','M11.opening_approve','M11.financial_activate','M11.journal_approve','M11.journal_post','M13.payment_approve'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$approver->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    foreach(DB::table('branches')->where('company_id',1)->orderBy('id')->pluck('id') as $companyBranch){
        $scope($approver,(int)$companyBranch);
        $grant($approver,['M11.report_view'],(int)$companyBranch);
    }
    foreach(['M03.customer_view','M03.supplier_view','M11.report_view','M11.source_post','M11.journal_post'] as $permission)DB::table('access_grants')->insertOrIgnore(['user_id'=>$cashier->id,'permission_code'=>$permission,'branch_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
    $today=now('Africa/Cairo')->toDateString();Carbon::setTestNow(CarbonImmutable::parse($today,'Africa/Cairo')->startOfDay()->addHours(12));
    $accountCode=static fn(string $purpose):string=>'ADV-'.substr(hash('sha256',$tag.':'.$purpose),0,24);
    $employee=Employee::factory()->create(['branch_id'=>$branch->id,'active'=>true,'starts_on'=>$today]);$setup=app(FinancialActivation::class);$accounts=app(AccountingActions::class);$mappings=[];
    foreach(FinancialActivation::MAPPINGS as $purpose=>[$label,$kind])$mappings[$purpose]=$accounts->account((int)$maker->id,null,0,['code'=>$accountCode($purpose),'name'=>'Synthetic advance '.$label,'kind'=>$kind,'parent_id'=>null,'postable'=>true,'active'=>true]);
    $version=(int)$activationBefore->version;$setup->save((int)$maker->id,$version,['go_live_date'=>$today,'mappings'=>$mappings]);$version++;
    foreach([['sale',null,'SALE','percentage'],['purchase',null,'PURCHASE','percentage'],['sale',$service->id,'SERVICE','exempt']] as [$type,$productId,$suffix,$treatment]){
        $setup->addTaxRule((int)$maker->id,$version,['code'=>$tag.'-'.$suffix,'name'=>'Synthetic '.$suffix,'transaction_type'=>$type,'product_id'=>$productId,'rate'=>'0','starts_on'=>$today,'ends_on'=>null,'treatment'=>$treatment,'tax_scale'=>2,'legal_reference'=>'Rollback-only synthetic tax rule','purchase_recoverable'=>$type==='purchase']);$version++;}
    $setup->validateTaxTreatment((int)$maker->id,$version,'Rollback-only synthetic advance tax fixture.');$version++;
    $periodId=$accounts->period((int)$maker->id,['name'=>'Synthetic advance '.$tag,'starts_on'=>substr($today,0,4).'-01-01','ends_on'=>substr($today,0,4).'-12-31']);
    $opening=app(OpeningBalances::class);$batch=$opening->save((int)$maker->id,$version,$uuid(),[],true,'Explicit rollback-only synthetic no-opening declaration for advance acceptance.');
    $opening->advance((int)$maker->id,$batch,1,'submit');$opening->advance((int)$approver->id,$batch,2,'approve');app(ActivateFinancialPosting::class)->execute((int)$approver->id,$batch,3);
    $activationDuring=DB::table('accounting_activation')->where('id',1)->first();
    $pass($activationDuring?->activated_at!==null&&DB::table('accounting_opening_batches')->where('id',$batch)->where('status','posted')->where('no_balances',true)->exists(),'Synthetic M11 uses real setup and independent no-opening approval inside rollback.');
    foreach(['customer_advance'=>'Synthetic customer advance control','service_contract_deferred'=>'Synthetic contract deferred revenue'] as $purpose=>$name){$id=$accounts->account((int)$maker->id,null,0,['code'=>$accountCode($purpose),'name'=>$name,'kind'=>'liability','parent_id'=>null,'postable'=>true,'active'=>true]);$setup->saveOptionalMapping((int)$maker->id,$purpose,$id);}
    $cashGl=(int)DB::table('accounting_account_mappings')->where('purpose','cash')->value('account_id');
    $cashAccount=app(TreasuryApi::class)->saveOperationalAccount((int)$maker->id,['code'=>$tag.'-CASH','name'=>'Synthetic advance cash account','kind'=>'cash','gl_account_id'=>$cashGl]);
    $bankGl=(int)DB::table('accounting_account_mappings')->where('purpose','bank')->value('account_id');
    $bankAccount=app(TreasuryApi::class)->saveOperationalAccount((int)$maker->id,['code'=>$tag.'-BANK','name'=>'Synthetic correction transfer bank','kind'=>'bank','gl_account_id'=>$bankGl]);
    $warehouse=app(InventoryActions::class)->saveWarehouse((int)$maker->id,['code'=>$tag.'-WH','name'=>'Rollback-only supplier credit warehouse','branch_id'=>(int)$branch->id,'active'=>true]);
    foreach([$maker,$approver] as $actor)DB::table('user_warehouse_scopes')->insert(['user_id'=>$actor->id,'warehouse_id'=>$warehouse['id'],'branch_id'=>$branch->id,'created_at'=>now(),'updated_at'=>now()]);
    $terms=['branch_id'=>$branch->id,'customer_id'=>$customer->id,'responsible_employee_id'=>$employee->id,'title'=>'عقد اختبار تطبيق دفعة مقدمة','starts_on'=>$today,'ends_on'=>now('Africa/Cairo')->addMonths(1)->toDateString(),'value'=>'100.00','service_product_id'=>$service->id,'billing_mode'=>'installments','pricing_tax_mode'=>'exclusive','terms'=>'شروط مالية اصطناعية محلية قابلة للتراجع.','sla_response_minutes'=>60,'sla_resolution_minutes'=>120,'covered_labor_minutes'=>0,'coverage'=>[['equipment_ref'=>$tag.'-EQ','site_id'=>null,'preventive_visits'=>0,'corrective_visits'=>1]],'parts'=>[],'excluded_parts'=>[],'boq'=>[],'schedule'=>[['code'=>'ADV-INV-01','due_date'=>$today,'amount'=>'100.00','description'=>'فاتورة خدمة اختبار']]];
    $contractActions=app(ServiceContractActions::class);$contract=$contractActions->save((int)$maker->id,null,0,$uuid(),$terms);
    $signature=app(ServiceContractDocuments::class)->store((int)$maker->id,$contract,UploadedFile::fake()->createWithContent('advance-signature.pdf',"%PDF-1.4\nSynthetic signature\n%%EOF"),'signature','Rollback-only advance acceptance');
    $row=DB::table('service_contracts')->find($contract);$contractActions->submit((int)$maker->id,$contract,(int)$row->version);$row=DB::table('service_contracts')->find($contract);$contractActions->approve((int)$approver->id,$contract,(int)$row->version);$row=DB::table('service_contracts')->find($contract);$contractActions->sign((int)$maker->id,$contract,(int)$row->version,$signature,$today);$row=DB::table('service_contracts')->find($contract);$contractActions->activate((int)$approver->id,$contract,(int)$row->version);
    $billing=app(ServiceContractBilling::class);$source=$billing->proposeInstallment((int)$maker->id,$contract,'ADV-INV-01',$uuid(),['invoice_date'=>$today,'due_date'=>$today,'tax_mode'=>'exclusive','reference'=>'Synthetic later customer invoice','amount'=>'100.00']);$billing->approveSource((int)$approver->id,$source,1);$invoiceId=$billing->invoice((int)$maker->id,$source,$uuid());
    $rp=app(ReceivablesPayablesApi::class);$invoice=DB::table('rp_customer_invoices')->find($invoiceId);$rp->validateCustomerInvoice((int)$approver->id,$invoiceId,(int)$invoice->version);$invoice=DB::table('rp_customer_invoices')->find($invoiceId);$postedInvoice=$rp->postCustomerInvoice((int)$approver->id,$invoiceId,(int)$invoice->version);
    $openId=(int)DB::table('rp_open_items')->where('source_type','customer_invoice')->where('source_id',$invoiceId)->value('id');
    $pass($postedInvoice['journal_id']>0&&DB::table('rp_open_items')->where('id',$openId)->where('remaining_amount','100.00')->exists(),'Later M12 invoice posts through a real M10 contract source.');

    // A supplier credit must descend from a posted invoice built from a real approved purchase order and receipt.
    $purchasing=app(PurchaseOrders::class);$receiving=app(PurchaseReceiving::class);$purchaseProduct=$stockProduct;
    $order=$purchasing->save((int)$maker->id,['creation_key'=>$uuid(),'branch_id'=>(int)$branch->id,'supplier_id'=>(int)$supplier->id,'order_date'=>$today,
        'expected_date'=>null,'currency'=>'EGP','payment_terms'=>'Rollback-only synthetic credit-refund acceptance','notes'=>'Real source chain for supplier credit refund','tax_mode'=>'exclusive','reported_tax'=>null,
        'lines'=>[['product_id'=>(int)$purchaseProduct->id,'quantity'=>'1.00000000','unit_price'=>'100.00000000','discount_amount'=>'0.00']]],null,0);
    $purchasing->transition((int)$maker->id,$order,1,'submit');$purchasing->transition((int)$approver->id,$order,2,'approve');$purchasing->issue((int)$approver->id,$order);
    $orderLine=(int)DB::table('purchasing_order_lines')->where('order_id',$order)->value('id');
    $receipt=$receiving->save((int)$maker->id,['creation_key'=>$uuid(),'order_id'=>$order,'warehouse_id'=>$warehouse['id'],'receipt_date'=>$today,
        'delivery_reference'=>$tag.'-SUP-R1','notes'=>'Actual synthetic supplier delivery for refund acceptance','lines'=>[['order_line_id'=>$orderLine,'quantity'=>'1.00000000']]],null,0);
    $receiving->submit((int)$maker->id,$receipt,1);app(PostPurchaseReceipt::class)->execute((int)$approver->id,$receipt,2);
    $receiptLine=(int)DB::table('purchasing_receipt_lines')->where('receipt_id',$receipt)->value('id');
    $supplierInvoice=$rp->createSupplierInvoice((int)$maker->id,['creation_key'=>$uuid(),'supplier_id'=>(int)$supplier->id,'supplier_invoice_number'=>$tag.'-SUP-01',
        'invoice_date'=>$today,'due_date'=>$today,'tax_mode'=>'exclusive','lines'=>[['order_line_id'=>$orderLine,'quantity'=>'1.00000000','unit_price'=>'100.00000000',
            'discount_amount'=>'0.00','matches'=>[['receipt_line_id'=>$receiptLine,'quantity'=>'1.00000000']]]]]);
    $rp->validateSupplierInvoice((int)$maker->id,$supplierInvoice,1);$rp->matchSupplierInvoice((int)$maker->id,$supplierInvoice,2);
    $rp->approveSupplierInvoice((int)$approver->id,$supplierInvoice,3);$rp->postSupplierInvoice((int)$approver->id,$supplierInvoice,4);
    $supplierOpenId=(int)DB::table('rp_open_items')->where('source_type','supplier_invoice')->where('source_id',$supplierInvoice)->value('id');
    $pass($supplierOpenId>0&&DB::table('rp_open_items')->where('id',$supplierOpenId)->where('original_amount','100.00')->exists(),
        'Supplier payable originates from an independently approved real PO/receipt/invoice chain.');

    $stage='settle real customer and supplier source invoices';$treasury=app(TreasuryApi::class);$rpHistory=app(ReceivablesPayablesApi::class);
    $collectionKey=$uuid();$collection=$treasury->executeCustomerCollection((int)$cashier->id,['idempotency_key'=>$collectionKey,'branch_id'=>(int)$branch->id,
        'customer_id'=>(int)$customer->id,'account_id'=>$cashAccount,'date'=>$today,'amount'=>'100.00','reference'=>'Synthetic paid customer invoice'],[['open_item_id'=>$openId,'amount'=>'100.00']]);
    $payment=$treasury->prepareSupplierPayment((int)$maker->id,['creation_key'=>$uuid(),'branch_id'=>(int)$branch->id,'supplier_id'=>(int)$supplier->id,
        'account_id'=>$cashAccount,'request_date'=>$today,'amount'=>'100.00','reference'=>'Synthetic paid supplier invoice']);
    $treasury->approveSupplierPayment((int)$approver->id,$payment,1);$supplierPayment=$treasury->executeSupplierPayment((int)$maker->id,$payment,2,$uuid(),[['open_item_id'=>$supplierOpenId,'amount'=>'100.00']]);
    $pass(DB::table('rp_open_items')->where('id',$openId)->where('settled_amount','100.00')->exists()
        &&DB::table('rp_open_items')->where('id',$supplierOpenId)->where('settled_amount','100.00')->exists(),
        'Customer collection and supplier payment settle the real source invoices before credit notes are issued.');

    $stage='post independently approved source-linked customer and supplier credits';
    $correction=$billing->correctContractBilling((int)$maker->id,$source,$uuid(),'20.00',$today,'Synthetic post-settlement customer credit refund');
    $rp->approveCreditNote((int)$approver->id,$correction);$customerCredit=$rp->postCreditNote((int)$approver->id,$correction);
    $supplierCreditNote=$rp->createCreditNote((int)$maker->id,['creation_key'=>$uuid(),'kind'=>'supplier','invoice_id'=>$supplierInvoice,'note_date'=>$today,
        'reason'=>'Synthetic post-settlement supplier credit refund','amount'=>'20.00']);
    $rp->approveCreditNote((int)$approver->id,$supplierCreditNote);$supplierCredit=$rp->postCreditNote((int)$approver->id,$supplierCreditNote);
    $customerPartyCredit=DB::table('rp_party_credits')->where('credit_note_id',$correction)->first();
    $supplierPartyCredit=DB::table('rp_party_credits')->where('credit_note_id',$supplierCreditNote)->first();
    $pass($customerPartyCredit&&$supplierPartyCredit&&$customerPartyCredit->original_amount==='20.00'&&$supplierPartyCredit->original_amount==='20.00'
        &&$customerPartyCredit->party_type==='customer'&&$supplierPartyCredit->party_type==='supplier',
        'Independent approval/posting creates customer and supplier party credits from their paid original invoices.');

    $creditHistory=$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today);
    $cashBeforeRefunds=$treasury->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today);
    $pass($creditHistory['ar']==='-20.00'&&$creditHistory['customer_credits']==='20.00'&&$creditHistory['ap']==='-20.00'
        &&$creditHistory['supplier_credits']==='20.00'&&$cashBeforeRefunds['cash']==='0.00',
        'As-of the credit-note date, paid customer/supplier invoices reconcile to 20.00 source credits and zero cash.');
    $stage='refund actual customer/supplier party credits';$refundDate=CarbonImmutable::parse($today,'Africa/Cairo')->addDay()->toDateString();$customerRefundKey=$uuid();$supplierRefundKey=$uuid();
    // The supplier inflow is posted first; it is the real source of cash for the subsequent customer outflow.
    $supplierRefund=$treasury->refundPartyCredit((int)$cashier->id,(int)$supplierPartyCredit->id,$cashAccount,'10.00',$refundDate,$supplierRefundKey,'Synthetic partial supplier credit refund');
    $supplierRefundReplay=$treasury->refundPartyCredit((int)$cashier->id,(int)$supplierPartyCredit->id,$cashAccount,'10.00',$refundDate,$supplierRefundKey,'Synthetic partial supplier credit refund');
    $pass($treasury->reconciliationValues((int)$cashier->id,[(int)$branch->id],$refundDate)['cash']==='10.00',
        'Supplier refund inflow posts before and funds the subsequent customer refund outflow.');
    $customerRefund=$treasury->refundPartyCredit((int)$cashier->id,(int)$customerPartyCredit->id,$cashAccount,'10.00',$refundDate,$customerRefundKey,'Synthetic partial customer credit refund');
    $customerRefundReplay=$treasury->refundPartyCredit((int)$cashier->id,(int)$customerPartyCredit->id,$cashAccount,'10.00',$refundDate,$customerRefundKey,'Synthetic partial customer credit refund');
    $pass($customerRefund['movement_id']===$customerRefundReplay['movement_id']&&$customerRefund['journal_id']===$customerRefundReplay['journal_id']
        &&$supplierRefund['movement_id']===$supplierRefundReplay['movement_id']&&$supplierRefund['journal_id']===$supplierRefundReplay['journal_id']
        &&DB::table('rp_party_credit_refunds')->whereIn('movement_id',[$customerRefund['movement_id'],$supplierRefund['movement_id']])->count()===2,
        'Same-key replay returns the unique source-linked Treasury movement and journal for each credit refund.');
    $snapshot=['refunds'=>(int)DB::table('rp_party_credit_refunds')->count(),'movements'=>(int)DB::table('treasury_movements')->count(),
        'journals'=>(int)DB::table('accounting_journals')->count(),'customer_credit'=>$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today)['customer_credits']];
    $reject(fn()=>$treasury->refundPartyCredit((int)$cashier->id,(int)$customerPartyCredit->id,$cashAccount,'9.00',$refundDate,$customerRefundKey,'Synthetic partial customer credit refund'),'Changed payload under a refund idempotency key conflicts.');
    $reject(fn()=>$treasury->refundPartyCredit((int)$cashier->id,(int)$customerPartyCredit->id,$cashAccount,'10.01',$refundDate,$uuid(),'Synthetic over-cap customer credit refund'),'Refund above remaining source credit is rejected.');
    $reject(fn()=>$treasury->refundPartyCredit((int)$denied->id,(int)$supplierPartyCredit->id,$cashAccount,'1.00',$refundDate,$uuid(),'Synthetic unauthorized credit refund'),'Actor without M13 credit-refund authority is denied.');
    $reject(fn()=>$treasury->refundPartyCredit((int)$foreignActor->id,(int)$customerPartyCredit->id,$cashAccount,'1.00',$refundDate,$uuid(),'Synthetic cross-branch credit refund'),'M13 credit refund cannot cross branch scope.');
    $pass($snapshot===['refunds'=>(int)DB::table('rp_party_credit_refunds')->count(),'movements'=>(int)DB::table('treasury_movements')->count(),
        'journals'=>(int)DB::table('accounting_journals')->count(),'customer_credit'=>$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today)['customer_credits']],
        'Conflict, cap and authority denials leave no partial refund, movement or journal state.');

    $stage='refund accounting and dated subledger reconciliation';$receivableGl=(int)DB::table('accounting_account_mappings')->where('purpose','receivable')->value('account_id');
    $apGl=(int)DB::table('accounting_account_mappings')->where('purpose','ap')->value('account_id');$cashGl=(int)DB::table('treasury_accounts')->where('id',$cashAccount)->value('gl_account_id');
    $lineAmount=static function(int $journal,int $account,string $side):string{return (string)DB::table('accounting_journal_lines')->where('journal_id',$journal)->where('account_id',$account)->value($side);};
    $customerRefundJournal=(int)$customerRefund['journal_id'];$supplierRefundJournal=(int)$supplierRefund['journal_id'];
    $pass(DB::table('accounting_journals')->where('id',$customerRefundJournal)->where('source_key','party-credit-refund:'.$customerRefundKey)->where('status','posted')->exists()
        &&$lineAmount($customerRefundJournal,$receivableGl,'debit')==='10.00'&&$lineAmount($customerRefundJournal,$cashGl,'credit')==='10.00',
        'Customer credit refund posts Dr receivable / Cr cash from its credit source.');
    $pass(DB::table('accounting_journals')->where('id',$supplierRefundJournal)->where('source_key','party-credit-refund:'.$supplierRefundKey)->where('status','posted')->exists()
        &&$lineAmount($supplierRefundJournal,$cashGl,'debit')==='10.00'&&$lineAmount($supplierRefundJournal,$apGl,'credit')==='10.00',
        'Supplier credit refund posts Dr cash / Cr AP from its credit source.');
    $historyAfter=$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$refundDate);
    $pass($historyAfter['ar']==='-10.00'&&$historyAfter['customer_credits']==='10.00'&&$historyAfter['ap']==='-10.00'&&$historyAfter['supplier_credits']==='10.00',
        'Dated AR/AP control and remaining party-credit balances reconcile after partial refunds.');
    $cashHistory=$treasury->reconciliationValues((int)$cashier->id,[(int)$branch->id],$refundDate);
    $pass(($cashHistory['cash']??null)==='0.00'&&($cashHistory['bank']??null)==='0.00',
        'Dated Treasury cash reconciles collection, supplier payment, customer refund and supplier refund to zero.');
    $glNet=static function(int $account,string $to)use($branch):string{return bcadd((string)DB::table('accounting_journal_lines as l')->join('accounting_journals as j','j.id','=','l.journal_id')
        ->where('j.branch_id',$branch->id)->where('j.status','posted')->where('j.journal_date','<=',$to)->where('l.account_id',$account)
        ->selectRaw('coalesce(sum(l.debit-l.credit),0)::numeric(20,2) as amount')->value('amount'),'0',2);};
    $pass($glNet($receivableGl,$refundDate)==='-10.00'&&$glNet($apGl,$refundDate)==='10.00'&&$glNet($cashGl,$refundDate)==='0.00',
        'Dated posted GL receivable, AP and cash control balances reconcile to the source-linked refund subledger and Treasury totals.');
    $priorCutoffAfter=$rpHistory->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today);
    $priorCashAfter=$treasury->reconciliationValues((int)$cashier->id,[(int)$branch->id],$today);
    $pass($priorCutoffAfter===$creditHistory&&$priorCashAfter===$cashBeforeRefunds,
        'Refunds dated after the credit notes do not rewrite earlier AR/AP or Treasury cutoffs.');
    $pass(DB::table('rp_party_credits')->where('id',$customerPartyCredit->id)->where('allocated_amount','10.00')->exists()
        &&DB::table('rp_party_credits')->where('id',$supplierPartyCredit->id)->where('allocated_amount','10.00')->exists()
        &&DB::table('rp_party_credit_refunds')->whereIn('movement_id',[$customerRefund['movement_id'],$supplierRefund['movement_id']])->count()===2,
        'Immutable refund rows atomically consume exactly the partial customer and supplier credits.');
    $creditImmutable=false;$movementImmutable=false;
    try{DB::transaction(fn()=>DB::table('rp_credit_notes')->where('id',$correction)->update(['amount'=>'19.99']));}catch(\Illuminate\Database\QueryException){$creditImmutable=true;}
    try{DB::transaction(fn()=>DB::table('treasury_movements')->where('id',$customerRefund['movement_id'])->update(['amount'=>'9.99']));}catch(\Illuminate\Database\QueryException){$movementImmutable=true;}
    $pass($creditImmutable&&$movementImmutable&&DB::table('rp_credit_notes')->where('id',$correction)->where('amount','20.00')->exists()
        &&DB::table('treasury_movements')->where('id',$customerRefund['movement_id'])->where('amount','10.00')->exists(),
        'Posted source credit notes and Treasury refund movements reject direct edits and retain their recorded amounts.');
    $stage='source-linked Treasury cash corrections';
    $correctionDate=CarbonImmutable::parse($refundDate,'Africa/Cairo')->addDay()->toDateString();
    $advanceRefundDate=CarbonImmutable::parse($correctionDate,'Africa/Cairo')->addDay()->toDateString();
    $advanceReversalDate=CarbonImmutable::parse($advanceRefundDate,'Africa/Cairo')->addDay()->toDateString();
    $transferDate=$advanceReversalDate;
    $transferReversalDate=CarbonImmutable::parse($transferDate,'Africa/Cairo')->addDay()->toDateString();
    $correctionActor=(int)$approver->id;
    $cashActor=(int)$cashier->id;
    $correctionKey=$uuid();
    $movementCount=static fn():int=>(int)DB::table('treasury_movements')->count();
    $journalCount=static fn():int=>(int)DB::table('accounting_journals')->count();
    $reversalCount=static fn():int=>(int)DB::table('rp_cash_reversals')->count();
    $journalSides=static function(int $journal):array {
        $rows=DB::table('accounting_journal_lines')->where('journal_id',$journal)->selectRaw('account_id,sum(debit) debit,sum(credit) credit')->groupBy('account_id')->orderBy('account_id')->get();
        $out=[];foreach($rows as $row)$out[(int)$row->account_id]=['debit'=>bcadd((string)$row->debit,'0',2),'credit'=>bcadd((string)$row->credit,'0',2)];return $out;
    };
    $isExactReversal=static function(int $originalJournal,int $reversalJournal)use($journalSides):bool {
        $original=$journalSides($originalJournal);$reversal=$journalSides($reversalJournal);if(array_keys($original)!==array_keys($reversal))return false;
        foreach($original as $account=>$sides)if($reversal[$account]['debit']!==$sides['credit']||$reversal[$account]['credit']!==$sides['debit'])return false;return true;
    };
    $sideEffects=static fn():array=>['movements'=>$movementCount(),'journals'=>$journalCount(),'reversals'=>$reversalCount()];

    $beforeDenials=$sideEffects();
    $reject(fn()=>$treasury->correctMovement($cashActor,(int)$collection['movement_id'],$correctionDate,$uuid(),'Synthetic self-correction attempt'),
        'The original cashier cannot correct their own customer collection.',403);
    $reject(fn()=>$treasury->correctMovement((int)$denied->id,(int)$collection['movement_id'],$correctionDate,$uuid(),'Synthetic missing permission attempt'),
        'Cash correction requires explicit M13.cash_correct permission.',403);
    $reject(fn()=>$treasury->correctMovement((int)$foreignActor->id,(int)$collection['movement_id'],$correctionDate,$uuid(),'Synthetic cross-branch correction attempt'),
        'Cash correction cannot cross the original movement branch scope.',403);
    $reject(fn()=>$treasury->correctMovement((int)$noApCorrector->id,(int)$supplierPayment['movement_id'],$correctionDate,$uuid(),'Synthetic missing AP view attempt'),
        'Supplier payment correction also requires M12.ap_view.',403);
    $reject(fn()=>$treasury->correctMovement((int)$noSourcePostCorrector->id,(int)$supplierPayment['movement_id'],$correctionDate,$uuid(),'Synthetic missing source-post attempt'),
        'Cash correction requires M11.source_post authority.',403);
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$collection['movement_id'],CarbonImmutable::parse($today,'Africa/Cairo')->subDay()->toDateString(),$uuid(),'Synthetic earlier correction attempt'),
        'A cash correction cannot precede its original movement date.',422);
    $afterOpenPeriod=CarbonImmutable::parse($today,'Africa/Cairo')->endOfYear()->addDay()->toDateString();
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$supplierPayment['movement_id'],$afterOpenPeriod,$uuid(),'Synthetic closed future period attempt'),
        'A cash correction cannot post outside an open accounting period.',422,'تاريخ القيد خارج الفترات المفتوحة');
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$collection['movement_id'],$correctionDate,$correctionKey,'Synthetic insufficient source funds attempt'),
        'Customer collection correction outflow requires actual cash funds.',422,'الرصيد الفعلي للحساب لا يكفي');
    $pass($sideEffects()===$beforeDenials,'Self, permission, branch, party-scope, date, closed-period and insufficient-funds denials leave no movement, journal or subledger reversal.');

    // Supplier payment reversal is an actual +100 cash source for reversing the collection.
    $paymentCorrection=$treasury->correctMovement($cashActor,(int)$supplierPayment['movement_id'],$correctionDate,$uuid(),'Restore payable settlement from source payment');
    $pass(DB::table('treasury_movements')->where('id',$paymentCorrection['movement_id'])->where('original_movement_id',$supplierPayment['movement_id'])
        ->where('kind','cash_correction_in')->where('journal_id',$paymentCorrection['journal_id'])->exists()
        &&DB::table('rp_cash_reversals')->where('original_movement_id',$supplierPayment['movement_id'])->where('movement_id',$paymentCorrection['movement_id'])->where('effective_date',$correctionDate)->exists()
        &&$isExactReversal((int)$supplierPayment['journal_id'],(int)$paymentCorrection['journal_id'])
        &&DB::table('rp_open_items')->where('id',$supplierOpenId)->where('settled_amount','0.00')->exists(),
        'Supplier payment correction preserves the original and reverses its exact GL journal and M12 settlement.');
    $collectionCorrection=$treasury->correctMovement($correctionActor,(int)$collection['movement_id'],$correctionDate,$correctionKey,'Restore receivable settlement from source collection');
    $pass(DB::table('treasury_movements')->where('id',$collectionCorrection['movement_id'])->where('original_movement_id',$collection['movement_id'])
        ->where('kind','cash_correction_out')->where('journal_id',$collectionCorrection['journal_id'])->exists()
        &&DB::table('rp_cash_reversals')->where('original_movement_id',$collection['movement_id'])->where('movement_id',$collectionCorrection['movement_id'])->where('effective_date',$correctionDate)->exists()
        &&$isExactReversal((int)$collection['journal_id'],(int)$collectionCorrection['journal_id'])
        &&DB::table('rp_open_items')->where('id',$openId)->where('settled_amount','0.00')->where('credited_amount','0.00')->where('remaining_amount','100.00')->exists()
        &&DB::table('rp_party_credits')->where('id',$customerPartyCredit->id)->where('original_amount','20.00')->exists()
        &&DB::table('rp_customer_invoices')->where('id',$invoiceId)->where('status','posted')->exists(),
        'Customer collection correction reopens the 100.00 invoice while retaining the separate 20.00 unapplied party credit.');
    $collectionReplay=$treasury->correctMovement($correctionActor,(int)$collection['movement_id'],$correctionDate,$correctionKey,'Restore receivable settlement from source collection');
    $pass($collectionReplay['movement_id']===$collectionCorrection['movement_id']&&$collectionReplay['journal_id']===$collectionCorrection['journal_id'],
        'Same-key customer correction replay returns the existing source correction.');
    $conflictBefore=$sideEffects();
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$collection['movement_id'],$correctionDate,$correctionKey,'Different correction reason'),
        'Changing a correction payload under the same idempotency key conflicts.',409);
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$collection['movement_id'],$correctionDate,$uuid(),'Second correction under a new key'),
        'A source movement cannot receive a second correction under another key.',409);
    $pass($sideEffects()===$conflictBefore,'Correction replay conflict and duplicate-source denial do not create a second correction or journal.');

    // Restore the two source credit refunds. The first adds real cash to fund the second correction outflow.
    $customerRefundCorrection=$treasury->correctMovement($correctionActor,(int)$customerRefund['movement_id'],$correctionDate,$uuid(),'Restore customer credit after cash refund correction');
    $pass(DB::table('rp_cash_reversals')->where('original_movement_id',$customerRefund['movement_id'])->where('movement_id',$customerRefundCorrection['movement_id'])->exists()
        &&DB::table('rp_party_credits')->where('id',$customerPartyCredit->id)->where('allocated_amount','0.00')->exists()
        &&$isExactReversal((int)$customerRefund['journal_id'],(int)$customerRefundCorrection['journal_id']),
        'Customer credit-refund correction restores the credit balance and exactly reverses its original journal.');
    $supplierRefundCorrection=$treasury->correctMovement($correctionActor,(int)$supplierRefund['movement_id'],$correctionDate,$uuid(),'Restore supplier credit after cash refund correction');
    $pass(DB::table('rp_cash_reversals')->where('original_movement_id',$supplierRefund['movement_id'])->where('movement_id',$supplierRefundCorrection['movement_id'])->exists()
        &&DB::table('rp_party_credits')->where('id',$supplierPartyCredit->id)->where('allocated_amount','0.00')->exists()
        &&$isExactReversal((int)$supplierRefund['journal_id'],(int)$supplierRefundCorrection['journal_id']),
        'Supplier credit-refund correction restores payable credit and exactly reverses its original journal.');

    $correctionHistory=$rpHistory->reconciliationValues($cashActor,[(int)$branch->id],$correctionDate);
    $correctionCash=$treasury->reconciliationValues($cashActor,[(int)$branch->id],$correctionDate);
    $pass($correctionHistory['ar']==='80.00'&&$correctionHistory['customer_credits']==='20.00'&&$correctionHistory['ap']==='80.00'&&$correctionHistory['supplier_credits']==='20.00'
        &&$correctionCash['cash']==='0.00'&&$correctionCash['bank']==='0.00'
        &&DB::table('rp_party_credits')->where('id',$customerPartyCredit->id)->where('original_amount','20.00')->where('allocated_amount','0.00')->exists()
        &&$glNet($receivableGl,$correctionDate)==='80.00'&&$glNet($apGl,$correctionDate)==='-80.00'&&$glNet($cashGl,$correctionDate)==='0.00',
        'After all four corrections, the 100.00 open invoice and restored 20.00 unapplied credit reconcile to net AR 80.00 and matching GL controls.');
    $cutoffHistoryAfter=$rpHistory->reconciliationValues($cashActor,[(int)$branch->id],$refundDate);
    $cutoffCashAfter=$treasury->reconciliationValues($cashActor,[(int)$branch->id],$refundDate);
    $pass($cutoffHistoryAfter===$historyAfter&&$cutoffCashAfter===$cashHistory,
        'Cash corrections effective after the refund date preserve earlier AR/AP and Treasury history cutoffs.');

    // A real partially consumed advance makes correction of its creating collection invalid.
    $advanceCollection=$treasury->executeCustomerCollection($cashActor,['idempotency_key'=>$uuid(),'branch_id'=>(int)$branch->id,'customer_id'=>(int)$customer->id,
        'account_id'=>$cashAccount,'date'=>$correctionDate,'amount'=>'15.00','reference'=>'Synthetic collection creating a customer advance','allow_advance'=>true],[]);
    $advanceId=(int)DB::table('rp_customer_advances')->where('movement_id',$advanceCollection['movement_id'])->value('id');
    $rp->applyCustomerAdvance($cashActor,$advanceId,$uuid(),$correctionDate,[['open_item_id'=>$openId,'amount'=>'5.00']]);
    $consumedBefore=$sideEffects()+['applications'=>(int)DB::table('rp_customer_advance_applications')->count()];
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$advanceCollection['movement_id'],$advanceRefundDate,$uuid(),'Reject correction of consumed customer advance'),
        'A source collection whose customer advance has been applied cannot be corrected.',409);
    $pass($consumedBefore===$sideEffects()+['applications'=>(int)DB::table('rp_customer_advance_applications')->count()],
        'Consumed-advance collection denial leaves all Treasury, journal, reversal and application rows unchanged.');
    $advanceRefund=$treasury->refundCustomerAdvance($cashActor,$advanceId,$cashAccount,'2.00',$advanceRefundDate,$uuid(),'Synthetic partial refund of customer advance');
    $advanceRefundCorrection=$treasury->correctMovement($correctionActor,(int)$advanceRefund['movement_id'],$advanceReversalDate,$uuid(),'Restore customer advance from source refund');
    $pass(DB::table('rp_cash_reversals')->where('original_movement_id',$advanceRefund['movement_id'])->where('movement_id',$advanceRefundCorrection['movement_id'])->exists()
        &&$isExactReversal((int)$advanceRefund['journal_id'],(int)$advanceRefundCorrection['journal_id'])
        &&$rpHistory->reconciliationValues($cashActor,[(int)$branch->id],$advanceRefundDate)['customer_advances']==='8.00'
        &&$rpHistory->reconciliationValues($cashActor,[(int)$branch->id],$advanceReversalDate)['customer_advances']==='10.00',
        'Advance refund correction restores the dated customer advance while preserving the earlier refund cutoff.');

    $transfer=$treasury->transfer((int)$maker->id,['idempotency_key'=>$uuid(),'branch_id'=>(int)$branch->id,'from_account_id'=>$cashAccount,'to_account_id'=>$bankAccount,
        'date'=>$transferDate,'amount'=>'10.00','reference'=>'Synthetic correction transfer pair']);
    $transferOriginal=(int)DB::table('treasury_movements')->where('id',$transfer['out_movement_id'])->value('journal_id');
    $transferCorrection=$treasury->correctMovement($correctionActor,(int)$transfer['in_movement_id'],$transferReversalDate,$uuid(),'Reverse both legs of source transfer atomically');
    $transferOutCorrection=(int)$transferCorrection['movement_id'];$transferInCorrection=(int)$transferCorrection['paired_movement_id'];
    $transferReplay=$treasury->correctMovement($correctionActor,(int)$transfer['out_movement_id'],$transferReversalDate,
        (string)DB::table('treasury_movements')->where('id',$transferOutCorrection)->value('idempotency_key'),'Reverse both legs of source transfer atomically');
    $pass($transferCorrection['paired_movement_id']!==null&&$transferReplay['movement_id']===$transferOutCorrection&&$transferReplay['paired_movement_id']===$transferInCorrection
        &&DB::table('treasury_movements')->where('id',$transferOutCorrection)->where('original_movement_id',$transfer['out_movement_id'])->where('kind','cash_correction_in')
            ->where('paired_movement_id',$transferInCorrection)->where('journal_id',$transferCorrection['journal_id'])->exists()
        &&DB::table('treasury_movements')->where('id',$transferInCorrection)->where('original_movement_id',$transfer['in_movement_id'])->where('kind','cash_correction_out')
            ->where('paired_movement_id',$transferOutCorrection)->where('journal_id',$transferCorrection['journal_id'])->exists()
        &&$isExactReversal($transferOriginal,(int)$transferCorrection['journal_id']),
        'Transfer correction from either source leg returns one atomic paired correction and exactly reverses its shared journal.');
    $transferConflict=$sideEffects();
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$transfer['out_movement_id'],$transferReversalDate,$uuid(),'Second transfer correction'),
        'A transfer cannot be corrected a second time from its other source leg.',409);
    $pass($transferConflict===$sideEffects(),'Duplicate transfer correction leaves pair, journal and source history unchanged.');

    $finalHistory=$rpHistory->reconciliationValues($cashActor,[(int)$branch->id],$transferReversalDate);
    $finalCash=$treasury->reconciliationValues($cashActor,[(int)$branch->id],$transferReversalDate);
    $pass($finalHistory['ar']==='75.00'&&$finalHistory['customer_credits']==='20.00'&&$finalHistory['ap']==='80.00'&&$finalHistory['supplier_credits']==='20.00'
        &&$finalHistory['customer_advances']==='10.00'&&$finalCash['cash']==='15.00'&&$finalCash['bank']==='0.00'
        &&$glNet($receivableGl,$transferReversalDate)==='75.00'&&$glNet($apGl,$transferReversalDate)==='-80.00'
        &&$glNet($cashGl,$transferReversalDate)==='15.00'&&$glNet($bankGl,$transferReversalDate)==='0.00',
        'Historical AR/AP, customer advance, credit, cash/bank and GL controls reconcile after advance and transfer corrections.');

    $unusedAdvanceCollection=$treasury->executeCustomerCollection($cashActor,['idempotency_key'=>$uuid(),'branch_id'=>(int)$branch->id,'customer_id'=>(int)$customer->id,
        'account_id'=>$cashAccount,'date'=>$transferReversalDate,'amount'=>'3.00','reference'=>'Synthetic unconsumed advance source','allow_advance'=>true],[]);
    $unusedAdvanceId=(int)DB::table('rp_customer_advances')->where('movement_id',$unusedAdvanceCollection['movement_id'])->value('id');
    $unusedAdvanceCorrection=$treasury->correctMovement($correctionActor,(int)$unusedAdvanceCollection['movement_id'],$transferReversalDate,$uuid(),'Reverse unconsumed advance collection at source');
    $pass($unusedAdvanceId>0&&DB::table('rp_cash_reversals')->where('original_movement_id',$unusedAdvanceCollection['movement_id'])
        ->where('movement_id',$unusedAdvanceCorrection['movement_id'])->exists()
        &&$isExactReversal((int)$unusedAdvanceCollection['journal_id'],(int)$unusedAdvanceCorrection['journal_id'])
        &&$rpHistory->reconciliationValues($cashActor,[(int)$branch->id],$transferReversalDate)['customer_advances']==='10.00'
        &&$treasury->reconciliationValues($cashActor,[(int)$branch->id],$transferReversalDate)['cash']==='15.00',
        'A wholly unconsumed advance is removed with its source collection correction and exact GL reversal.');

    $stage='closed-period refusal';
    $lateSource=$treasury->executeCustomerCollection($cashActor,['idempotency_key'=>$uuid(),'branch_id'=>(int)$branch->id,'customer_id'=>(int)$customer->id,
        'account_id'=>$cashAccount,'date'=>$transferReversalDate,'amount'=>'1.00','reference'=>'Synthetic source for closed-period correction denial','allow_advance'=>true],[]);
    app(\App\Modules\Accounting\Application\FinancialReports::class)->closePeriod((int)$approver->id,$periodId);
    $closedCorrectionState=$sideEffects();
    $reject(fn()=>$treasury->correctMovement($correctionActor,(int)$lateSource['movement_id'],$transferReversalDate,$uuid(),'Synthetic correction during closed period'),
        'A source correction cannot post into a closed accounting period.',422,'تاريخ القيد خارج الفترات المفتوحة');
    $pass($closedCorrectionState===$sideEffects(),'Closed-period correction denial leaves the source correction graph and journal counts unchanged.');
    $refundState=['refunds'=>(int)DB::table('rp_party_credit_refunds')->count(),'movements'=>(int)DB::table('treasury_movements')->count(),'journals'=>(int)DB::table('accounting_journals')->count()];
    $reject(fn()=>$treasury->refundPartyCredit((int)$cashier->id,(int)$customerPartyCredit->id,$cashAccount,'1.00',$transferReversalDate,$uuid(),'Synthetic refund during closed accounting period'),
        'A new credit refund cannot post into a closed accounting period.',422,'تاريخ القيد خارج الفترات المفتوحة');
    $pass($refundState===['refunds'=>(int)DB::table('rp_party_credit_refunds')->count(),'movements'=>(int)DB::table('treasury_movements')->count(),'journals'=>(int)DB::table('accounting_journals')->count()],
        'Closed-period refund denial leaves party-credit, Treasury movement and journal state unchanged.');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $pass(true,'All deferred source, exact-cents, pair and M12 reversal constraints validate before rollback.');
    $during=$counts();
}catch(Throwable $error){$failure=$error;foreach(array_slice($error->getTrace(),0,16) as $frame){$file=isset($frame['file'])?str_replace('\\','/',$frame['file']):null;if($file!==null&&str_starts_with(strtolower($file),strtolower(str_replace('\\','/',$root).'/')))$file=substr($file,strlen(str_replace('\\','/',$root))+1);elseif($file!==null)$file=basename($file);$failureTrace[]=['file'=>$file,'line'=>$frame['line']??null,'class'=>$frame['class']??null,'function'=>$frame['function']??null];}}
finally{
    if(DB::transactionLevel()>0)DB::rollBack();
    Carbon::setTestNow();
    foreach([$privateRoot,$storage,$taskRoot.'/treasury-credit-refunds-cache-'.$tagRuntime,$taskRoot.'/treasury-credit-refunds-views-'.$tagRuntime] as $dir){$base=basename($dir);if(is_dir($dir)&&realpath(dirname($dir))===realpath($taskRoot)&&str_starts_with($base,'treasury-credit-refunds-'))(new Illuminate\Filesystem\Filesystem)->deleteDirectory($dir);}
}
$after=$counts();$activationAfter=DB::table('accounting_activation')->where('id',1)->first();$companyAfter=DB::table('companies')->where('id',1)->first();
$companyRestored=($companyBefore===null&&$companyAfter===null)||(is_object($companyBefore)&&is_object($companyAfter)&&(array)$companyBefore===(array)$companyAfter);
$scratchRemoved=!is_dir($privateRoot)&&!is_dir($storage)&&!is_dir($taskRoot.'/treasury-credit-refunds-cache-'.$tagRuntime)&&!is_dir($taskRoot.'/treasury-credit-refunds-views-'.$tagRuntime);
$pass($before===$after&&$scratchRemoved&&$companyRestored,'All synthetic customer, supplier, M05/M06, M10, M11, M12 and Treasury records, company profile and private scratch roll back.');
$pass($activationBefore==$activationAfter,'Synthetic M11 activation and version roll back to the owned database state.');
$receipt=['all_pass'=>$failure===null&&count(array_filter($checks,fn($check)=>$check['result']!=='PASS'))===0,'mode'=>'treasury-credit-refunds-and-cash-corrections','phase'=>$stage,
    'assertions'=>$assertions,'rollback_verified'=>$before===$after&&$activationBefore==$activationAfter&&$companyRestored&&$scratchRemoved,'database'=>['name'=>$identity->database,'server_address'=>$identity->server_address,'port'=>(int)$identity->port,
        'postgres_major'=>intdiv((int)$identity->server_version_num,10000)],'counts'=>['before'=>$before,'during_fixture'=>$during,'after_rollback'=>$after],'checks'=>$checks,
    'evidence'=>['synthetic_sources'=>'real M11 setup and no-opening approval, M10 customer contract/invoice/correction, real M05/M06 supplier order/receipt, M12 invoices/credits and M13 settlement/refunds/source-linked cash corrections inside outer rollback',
        'synthetic_m11_activation'=>['performed'=>$activationDuring!==null,'rolled_back'=>$activationBefore==$activationAfter,'go_live_date'=>$activationDuring?->go_live_date,'zero_opening'=>true],
        'company_profile'=>['synthetic_fields_set_inside_transaction'=>true,'exact_baseline_restored'=>$companyRestored],
        'failure_trace'=>$failureTrace]];
if($failure){$receipt['failure']=['class'=>$failure::class,'message'=>mb_substr((string)preg_replace('/(password|secret|token)=\S+/i','$1=[redacted]',$failure->getMessage()),0,500)];}
$path=$emit($receipt);fwrite(($receipt['all_pass']?STDOUT:STDERR),json_encode($receipt,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
fwrite(($receipt['all_pass']?STDOUT:STDERR),'RESULT all_pass='.($receipt['all_pass']?'1':'0').' assertions='.$assertions.' rollback_verified='.($receipt['rollback_verified']?'1':'0').' receipt='.$path.PHP_EOL);
if(!$receipt['all_pass'])exit(1);

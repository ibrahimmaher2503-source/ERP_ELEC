<?php
namespace Database\Seeders;
use App\Modules\Accounting\Domain\AccountingAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
final class AccountingDemoSeeder extends Seeder
{
 public function run():void {
  abort_unless(app()->environment(['local','testing']),403);
  DB::transaction(function(){
   DB::select('select pg_advisory_xact_lock(10401)');DB::select('select pg_advisory_xact_lock(11001)');
   foreach(['asset'=>'أصول تجريبية مصرية','liability'=>'التزامات تجريبية مصرية','equity'=>'حقوق ملكية تجريبية','income'=>'إيرادات تجريبية','expense'=>'مصروفات تجريبية'] as $kind=>$name){
    AccountingAccount::firstOrCreate(['code'=>'DEMO-EG-ACC-'.strtoupper($kind)],['name'=>$name,'kind'=>$kind,'active'=>true,'postable'=>true]);
   }
  });
 }
}

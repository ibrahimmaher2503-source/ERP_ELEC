<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
class AccountingAccountFactory extends Factory { protected $model=\App\Modules\Accounting\Domain\AccountingAccount::class; public function definition():array { return ['code'=>'DEMO-EG-ACC-'.fake()->unique()->numerify('########'),'name'=>'حساب تجريبي مصري','kind'=>'expense','postable'=>true,'active'=>true]; } }

<?php
namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
class AccountingJournalFactory extends Factory { protected $model=\App\Modules\Accounting\Domain\AccountingJournal::class; public function definition():array { return ['creation_key'=>(string)\Illuminate\Support\Str::uuid(),'branch_id'=>BranchFactory::new(),'created_by'=>UserFactory::new()->disabledDemo(),'journal_date'=>'2040-01-15','description'=>'مسودة مصرية تجريبية غير مرحلة','currency'=>'EGP','status'=>'draft']; } }

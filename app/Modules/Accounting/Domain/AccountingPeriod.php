<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\AccountingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AccountingPeriodFactory::class)]
class AccountingPeriod extends Model
{
    use HasFactory;

    protected $table = 'accounting_periods';

    protected $guarded = ['id'];
}

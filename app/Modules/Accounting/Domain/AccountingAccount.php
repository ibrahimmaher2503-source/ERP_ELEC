<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\AccountingAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AccountingAccountFactory::class)]
class AccountingAccount extends Model
{
    use HasFactory;

    protected $table = 'accounting_accounts';

    protected $guarded = ['id'];
}

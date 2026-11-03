<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\AccountingJournalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AccountingJournalFactory::class)]
class AccountingJournal extends Model
{
    use HasFactory;

    protected $table = 'accounting_journals';

    protected $guarded = ['id'];
}

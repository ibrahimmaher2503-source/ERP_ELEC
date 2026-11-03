<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\AccountingJournalLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(AccountingJournalLineFactory::class)]
class AccountingJournalLine extends Model
{
    use HasFactory;

    protected $table = 'accounting_journal_lines';

    protected $guarded = ['id'];

    public $timestamps = false;
}

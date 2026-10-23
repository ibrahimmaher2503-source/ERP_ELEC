<?php
namespace App\Modules\Accounting\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
#[UseFactory(\Database\Factories\AccountingJournalFactory::class)]
class AccountingJournal extends Model { use HasFactory; protected $table='accounting_journals'; protected $guarded=['id'];  }

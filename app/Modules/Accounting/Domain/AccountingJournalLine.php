<?php
namespace App\Modules\Accounting\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
#[UseFactory(\Database\Factories\AccountingJournalLineFactory::class)]
class AccountingJournalLine extends Model { use HasFactory; protected $table='accounting_journal_lines'; protected $guarded=['id']; public $timestamps=false; }

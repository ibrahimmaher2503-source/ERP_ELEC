<?php
namespace App\Modules\Accounting\Domain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
#[UseFactory(\Database\Factories\AccountingPeriodFactory::class)]
class AccountingPeriod extends Model { use HasFactory; protected $table='accounting_periods'; protected $guarded=['id'];  }

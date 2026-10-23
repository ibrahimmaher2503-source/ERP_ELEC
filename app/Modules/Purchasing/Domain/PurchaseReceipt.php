<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseReceiptFactory::class)]
class PurchaseReceipt extends Model
{
    use HasFactory;

    protected $table = 'purchasing_receipts';

    protected $guarded = ['id'];
}

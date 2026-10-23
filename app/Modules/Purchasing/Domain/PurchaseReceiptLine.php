<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseReceiptLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseReceiptLineFactory::class)]
class PurchaseReceiptLine extends Model
{
    use HasFactory;

    protected $table = 'purchasing_receipt_lines';

    protected $guarded = ['id'];

    public $timestamps = false;
}

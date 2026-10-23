<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseRfqSupplierFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseRfqSupplierFactory::class)]
class PurchaseRfqSupplier extends Model
{
    use HasFactory;

    protected $table = 'purchasing_rfq_suppliers';

    protected $guarded = ['id'];

    public $timestamps = false;
}

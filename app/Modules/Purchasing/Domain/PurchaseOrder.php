<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseOrderFactory::class)]
class PurchaseOrder extends Model
{
    use HasFactory;

    protected $table = 'purchasing_orders';

    protected $guarded = ['id'];
}

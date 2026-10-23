<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseOrderLineFactory::class)]
class PurchaseOrderLine extends Model
{
    use HasFactory;

    protected $table = 'purchasing_order_lines';

    protected $guarded = ['id'];

    public $timestamps = false;
}

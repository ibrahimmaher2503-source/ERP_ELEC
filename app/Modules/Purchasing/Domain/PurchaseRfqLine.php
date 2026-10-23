<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseRfqLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseRfqLineFactory::class)]
class PurchaseRfqLine extends Model
{
    use HasFactory;

    protected $table = 'purchasing_rfq_lines';

    protected $guarded = ['id'];

    public $timestamps = false;
}

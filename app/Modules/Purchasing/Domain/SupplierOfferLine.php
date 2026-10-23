<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\SupplierOfferLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierOfferLineFactory::class)]
class SupplierOfferLine extends Model
{
    use HasFactory;

    protected $table = 'purchasing_offer_lines';

    protected $guarded = ['id'];

    public $timestamps = false;
}

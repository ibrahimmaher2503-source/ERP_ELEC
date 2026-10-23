<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\SupplierOfferFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierOfferFactory::class)]
class SupplierOffer extends Model
{
    use HasFactory;

    protected $table = 'purchasing_offers';

    protected $guarded = ['id'];
}

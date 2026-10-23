<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\SupplierOfferFileFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierOfferFileFactory::class)]
class SupplierOfferFile extends Model
{
    use HasFactory;

    protected $table = 'purchasing_offer_files';

    protected $guarded = ['id'];

    public $timestamps = false;
}

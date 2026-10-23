<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseRequisitionLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseRequisitionLineFactory::class)]
class PurchaseRequisitionLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];
}

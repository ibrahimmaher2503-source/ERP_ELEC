<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseRequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseRequisitionFactory::class)]
class PurchaseRequisition extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(PurchaseRequisitionLine::class, 'requisition_id');
    }
}

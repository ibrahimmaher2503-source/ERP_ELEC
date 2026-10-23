<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseRfqFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseRfqFactory::class)]
class PurchaseRfq extends Model
{
    use HasFactory;

    protected $table = 'purchasing_rfqs';

    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(PurchaseRfqLine::class, 'rfq_id');
    }

    public function suppliers()
    {
        return $this->hasMany(PurchaseRfqSupplier::class, 'rfq_id');
    }
}

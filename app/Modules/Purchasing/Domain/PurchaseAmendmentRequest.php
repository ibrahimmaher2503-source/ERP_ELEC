<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\PurchaseAmendmentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(PurchaseAmendmentRequestFactory::class)]
class PurchaseAmendmentRequest extends Model
{
    use HasFactory;

    protected $table = 'purchasing_amendment_requests';

    protected $guarded = ['id'];
}

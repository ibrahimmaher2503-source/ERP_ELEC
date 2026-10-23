<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\QuotationPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(QuotationPolicyFactory::class)]
class QuotationPolicy extends Model
{
    use HasFactory;

    protected $table = 'sales_quotation_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'version' => 'integer'];
    }
}

<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\QuotationLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(QuotationLineFactory::class)]
class QuotationLine extends Model
{
    use HasFactory;

    protected $table = 'sales_quotation_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_snapshot' => 'array', 'price_source' => 'array', 'quantity' => 'decimal:8', 'requested_price' => 'decimal:8', 'suggested_price' => 'decimal:8', 'discount_value' => 'decimal:8', 'product_id' => 'integer', 'unit_id' => 'integer'];
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }
}

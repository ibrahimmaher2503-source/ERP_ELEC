<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\SalesOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SalesOrderLineFactory::class)]
class SalesOrderLine extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_snapshot' => 'array', 'price_source' => 'array', 'quantity' => 'decimal:8', 'suggested_price' => 'decimal:8', 'requested_price' => 'decimal:8', 'discount_value' => 'decimal:8', 'product_id' => 'integer', 'unit_id' => 'integer', 'position' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}

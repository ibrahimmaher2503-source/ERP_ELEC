<?php

namespace App\Modules\Products\Domain;

use Database\Factories\ProductConversionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProductConversionFactory::class)]
class ProductConversion extends Model
{
    use HasFactory;

    protected $table = 'product_unit_conversions';

    protected $guarded = ['id'];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    protected function casts(): array
    {
        return ['factor' => 'decimal:8'];
    }
}

<?php

namespace App\Modules\Products\Domain;

use Database\Factories\ProductMediaFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProductMediaFactory::class)]
class ProductMedia extends Model
{
    use HasFactory;

    protected $table = 'product_media';

    protected $guarded = ['id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['is_image' => 'boolean', 'removed_at' => 'immutable_datetime'];
    }
}

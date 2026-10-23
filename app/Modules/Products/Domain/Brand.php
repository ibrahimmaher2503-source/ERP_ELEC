<?php

namespace App\Modules\Products\Domain;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(BrandFactory::class)]
class Brand extends Model
{
    use HasFactory;

    protected $table = 'brands';

    protected $guarded = ['id'];

    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
}

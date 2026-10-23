<?php

namespace App\Modules\Products\Domain;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(ProductFactory::class)]
class Product extends Model
{
    use HasFactory;

    protected $table = 'products';

    protected $guarded = ['id'];

    protected $hidden = ['internal_notes'];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function baseUnit()
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function conversions()
    {
        return $this->hasMany(ProductConversion::class);
    }

    public function purchaseUnit()
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function salesUnit()
    {
        return $this->belongsTo(Unit::class, 'sales_unit_id');
    }

    public function media()
    {
        return $this->hasMany(ProductMedia::class)->whereNull('removed_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'attributes' => 'array', 'version' => 'integer'];
    }
}

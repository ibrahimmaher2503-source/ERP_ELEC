<?php

namespace App\Modules\Products\Domain;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CategoryFactory::class)]
class Category extends Model
{
    use HasFactory;

    protected $table = 'product_categories';

    protected $guarded = ['id'];

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}

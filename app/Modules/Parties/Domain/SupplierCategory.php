<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\SupplierCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierCategoryFactory::class)]
class SupplierCategory extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'category_id');
    }
}

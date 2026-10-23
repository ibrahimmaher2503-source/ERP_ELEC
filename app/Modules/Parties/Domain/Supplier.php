<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierFactory::class)]
class Supplier extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'name_en', 'trading_name', 'entity_type', 'supplier_type', 'category_id', 'status', 'registration_number', 'tax_number', 'country', 'city', 'phone', 'mobile', 'email', 'website', 'version'];

    public function category()
    {
        return $this->belongsTo(SupplierCategory::class);
    }

    public function contacts()
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function addresses()
    {
        return $this->hasMany(SupplierAddress::class);
    }

    public function documents()
    {
        return $this->hasMany(SupplierDocument::class);
    }

    public function notes()
    {
        return $this->hasMany(SupplierNote::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'category_id' => 'integer'];
    }
}

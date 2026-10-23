<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\SupplierAddressFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierAddressFactory::class)]
class SupplierAddress extends Model
{
    use HasFactory;

    protected $fillable = ['supplier_id', 'label', 'purpose', 'line', 'country', 'city', 'region', 'postal_code', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}

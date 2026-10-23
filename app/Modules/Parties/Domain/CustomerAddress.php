<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CustomerAddressFactory::class)]
class CustomerAddress extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'label', 'purpose', 'line', 'country', 'city', 'region', 'postal_code', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }
}

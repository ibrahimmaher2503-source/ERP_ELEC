<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CustomerFactory::class)]
class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'name_en', 'trading_name', 'entity_type', 'segment', 'status', 'registration_number', 'tax_number', 'country', 'city', 'phone', 'mobile', 'email', 'website', 'credit_limit', 'payment_terms', 'version'];

    public function contacts()
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function notes()
    {
        return $this->hasMany(CustomerNote::class);
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}

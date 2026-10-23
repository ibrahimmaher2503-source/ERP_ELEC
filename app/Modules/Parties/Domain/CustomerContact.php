<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\CustomerContactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CustomerContactFactory::class)]
class CustomerContact extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'name', 'job_title', 'phone', 'mobile', 'email', 'active', 'is_primary'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_primary' => 'boolean'];
    }
}

<?php

namespace App\Modules\Parties\Domain;

use Database\Factories\SupplierContactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierContactFactory::class)]
class SupplierContact extends Model
{
    use HasFactory;

    protected $fillable = ['supplier_id', 'name', 'job_title', 'phone', 'mobile', 'email', 'active', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'active' => 'boolean'];
    }
}

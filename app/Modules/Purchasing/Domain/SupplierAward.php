<?php

namespace App\Modules\Purchasing\Domain;

use Database\Factories\SupplierAwardFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(SupplierAwardFactory::class)]
class SupplierAward extends Model
{
    use HasFactory;

    protected $table = 'purchasing_awards';

    protected $guarded = ['id'];
}

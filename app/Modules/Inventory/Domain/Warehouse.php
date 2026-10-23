<?php

namespace App\Modules\Inventory\Domain;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(WarehouseFactory::class)]
class Warehouse extends Model
{
    use HasFactory;

    protected $table = 'inventory_warehouses';

    protected $guarded = ['id'];
}

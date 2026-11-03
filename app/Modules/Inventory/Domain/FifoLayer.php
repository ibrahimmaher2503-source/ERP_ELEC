<?php

namespace App\Modules\Inventory\Domain;

use Database\Factories\FifoLayerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(FifoLayerFactory::class)]
final class FifoLayer extends Model
{
    use HasFactory;

    protected $table = 'inventory_fifo_layers';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['original_quantity' => 'decimal:8', 'remaining_quantity' => 'decimal:8', 'original_value' => 'decimal:2',
            'remaining_value' => 'decimal:16', 'allocated_amount' => 'decimal:2', 'unit_cost' => 'decimal:16'];
    }
}

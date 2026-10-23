<?php

namespace App\Modules\System\Domain;

use Database\Factories\DataOperationItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(DataOperationItemFactory::class)]
class DataOperationItem extends Model
{
    use HasFactory;

    protected $table = 'data_operation_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'before_values' => 'array', 'after_values' => 'array', 'result' => 'array'];
    }
}

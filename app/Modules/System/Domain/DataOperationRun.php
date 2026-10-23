<?php

namespace App\Modules\System\Domain;

use Database\Factories\DataOperationRunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(DataOperationRunFactory::class)]
class DataOperationRun extends Model
{
    use HasFactory;

    protected $table = 'data_operation_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    public function items()
    {
        return $this->hasMany(DataOperationItem::class, 'run_id');
    }
}

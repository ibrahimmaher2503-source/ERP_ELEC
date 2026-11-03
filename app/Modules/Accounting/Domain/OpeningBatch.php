<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\OpeningBatchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(OpeningBatchFactory::class)]
final class OpeningBatch extends Model
{
    use HasFactory;

    protected $table = 'accounting_opening_batches';

    protected $guarded = ['id'];

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningLine::class, 'batch_id');
    }
}

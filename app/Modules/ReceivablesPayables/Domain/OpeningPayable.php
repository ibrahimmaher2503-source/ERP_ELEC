<?php

namespace App\Modules\ReceivablesPayables\Domain;

use Database\Factories\OpeningPayableFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(OpeningPayableFactory::class)]
final class OpeningPayable extends Model
{
    use HasFactory;

    protected $table = 'payable_opening_items';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}

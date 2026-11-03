<?php

namespace App\Modules\Treasury\Domain;

use Database\Factories\TreasuryOpeningFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TreasuryOpeningFactory::class)]
final class OpeningBalance extends Model
{
    use HasFactory;

    protected $table = 'treasury_opening_balances';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}

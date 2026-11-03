<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\OpeningLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(OpeningLineFactory::class)]
final class OpeningLine extends Model
{
    use HasFactory;

    protected $table = 'accounting_opening_lines';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'amount' => 'decimal:2', 'quantity' => 'decimal:8', 'unit_cost' => 'decimal:16'];
    }
}

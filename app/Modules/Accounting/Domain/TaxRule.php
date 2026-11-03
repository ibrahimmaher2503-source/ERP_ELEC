<?php

namespace App\Modules\Accounting\Domain;

use Database\Factories\TaxRuleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(TaxRuleFactory::class)]
final class TaxRule extends Model
{
    use HasFactory;

    protected $table = 'accounting_tax_rules';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['rate' => 'decimal:5', 'tax_scale' => 'integer'];
    }
}

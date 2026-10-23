<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\QuotationTermFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(QuotationTermFactory::class)]
class QuotationTerm extends Model
{
    use HasFactory;

    protected $table = 'sales_quotation_terms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'revision' => 'integer'];
    }
}

<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\QuotationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(QuotationFactory::class)]
class Quotation extends Model
{
    use HasFactory;

    protected $table = 'sales_quotations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['customer_snapshot' => 'array', 'calculation' => 'array', 'quotation_date' => 'immutable_date', 'valid_until' => 'immutable_date', 'version' => 'integer', 'revision' => 'integer', 'customer_id' => 'integer', 'discount_value' => 'decimal:8'];
    }

    public function lines()
    {
        return $this->hasMany(QuotationLine::class)->orderBy('position');
    }

    public function attachments()
    {
        return $this->hasMany(QuotationAttachment::class);
    }

    public function approvals()
    {
        return $this->hasMany(QuotationApproval::class)->orderByDesc('id');
    }

    public function label(): string
    {
        return $this->number ?? 'مسودة '.substr($this->draft_key, 0, 8);
    }

    public function editable(): bool
    {
        return $this->status === 'draft';
    }
}

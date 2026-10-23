<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\QuotationApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(QuotationApprovalFactory::class)]
class QuotationApproval extends Model
{
    use HasFactory;

    protected $table = 'sales_quotation_approvals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['exceptions' => 'array', 'quotation_snapshot' => 'array', 'target_version' => 'integer', 'policy_version' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }
}

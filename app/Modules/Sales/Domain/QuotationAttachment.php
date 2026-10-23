<?php

namespace App\Modules\Sales\Domain;

use Database\Factories\QuotationAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(QuotationAttachmentFactory::class)]
class QuotationAttachment extends Model
{
    use HasFactory;

    protected $table = 'sales_quotation_attachments';

    protected $guarded = ['id'];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }
}

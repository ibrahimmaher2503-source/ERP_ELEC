<?php

namespace App\Modules\ReceivablesPayables\Domain;

use Database\Factories\CustomerInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(CustomerInvoiceFactory::class)]
final class CustomerInvoice extends Model
{
    use HasFactory;

    protected $table = 'rp_customer_invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'net_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
            'invoice_date' => 'date', 'due_date' => 'date'];
    }
}

<?php

namespace App\Modules\ReceivablesPayables\Presentation;

use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class InvoiceDetailsPage extends Component
{
    #[Locked]
    public string $type;

    #[Locked]
    public int $invoice;

    public function mount(string $type, int $invoice): void
    {
        abort_unless(in_array($type, ['supplier', 'customer'], true), 404);
        $this->type = $type;
        $this->invoice = $invoice;
    }

    public function render()
    {
        $supplier = $this->type === 'supplier';
        $table = $supplier ? 'rp_supplier_invoices' : 'rp_customer_invoices';
        $row = DB::table($table)->find($this->invoice);
        abort_unless($row, 404);
        $permission = $supplier ? 'supplier_invoice_view' : 'customer_invoice_view';
        app(ReceivablesPayablesAccess::class)->authorize(auth()->id(), $permission, (int) $row->branch_id);
        $lines = DB::table($supplier ? 'rp_supplier_invoice_lines' : 'rp_customer_invoice_lines')
            ->where('invoice_id', $row->id)->orderBy('id')->get();
        $matches = $supplier ? DB::table('rp_supplier_invoice_matches as m')->join('rp_supplier_invoice_lines as l', 'l.id', '=', 'm.invoice_line_id')
            ->where('l.invoice_id', $row->id)->orderBy('m.id')->get(['m.*', 'l.description']) : collect();
        $openItem = DB::table('rp_open_items')->where('source_type', $supplier ? 'supplier_invoice' : 'customer_invoice')
            ->where('source_id', $row->id)->first();

        return view('receivables-payables.invoice-details', compact('row', 'lines', 'matches', 'openItem'))
            ->layout('layouts::app', ['title' => $supplier ? 'تفاصيل فاتورة المورد' : 'تفاصيل فاتورة العميل']);
    }
}

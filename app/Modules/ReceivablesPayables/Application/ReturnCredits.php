<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Credit notes for physical returns, valued from the invoice lines being returned. */
final class ReturnCredits
{
    private const POSTED = ['supplier' => ['posted', 'partially_paid', 'paid', 'credited'], 'customer' => ['posted', 'partially_collected', 'collected', 'credited']];

    /** Invoiced quantity not yet returned, per source line. The owning caller authorizes the source. */
    public function remaining(string $kind, array $sourceLines): array
    {
        $result = array_fill_keys(array_map('intval', $sourceLines), '0.00000000');
        foreach ($this->positions($kind, $sourceLines) as $row) {
            $result[(int) $row->source_line_id] = bcadd($result[(int) $row->source_line_id], $row->remaining, 8);
        }

        return $result;
    }

    /** @param list<array{source_line_id:int,quantity:string}> $lines */
    public function post(int $actor, int $creator, string $kind, string $source, string $date, string $reason, array $lines): array
    {
        abort_unless(DB::transactionLevel() > 0 && isset(self::POSTED[$kind]), 500);
        app(AccountingApi::class)->lockPosting();
        app(AccountingApi::class)->requirePostingDate($date);
        $groups = [];
        foreach ($lines as $line) {
            $left = bcadd($line['quantity'], '0', 8);
            foreach ($this->positions($kind, [$line['source_line_id']]) as $position) {
                if (bccomp($left, '0', 8) === 0) {
                    break;
                }
                $take = bccomp($left, $position->remaining, 8) < 0 ? $left : $position->remaining;
                if (bccomp($take, '0', 8) > 0) {
                    $groups[(int) $position->invoice_id][] = ['invoice_line_id' => (int) $position->invoice_line_id, 'source_line_id' => (int) $line['source_line_id'], 'quantity' => $take];
                    $left = bcsub($left, $take, 8);
                }
            }
            if (bccomp($left, '0', 8) !== 0) {
                $this->fail('الكمية تتجاوز المفوتر غير المرتجع. أصدر الفاتورة أولًا.');
            }
        }
        $notes = [];
        ksort($groups);
        foreach ($groups as $invoice => $items) {
            $notes[] = $this->note($actor, $creator, $kind, $source.':'.$invoice, (int) $invoice, $date, $reason, $items);
        }

        return $notes;
    }

    private function note(int $actor, int $creator, string $kind, string $source, int $invoiceId, string $date, string $reason, array $items): array
    {
        $table = $kind === 'supplier' ? 'rp_supplier_invoices' : 'rp_customer_invoices';
        $lineTable = $kind === 'supplier' ? 'rp_supplier_invoice_lines' : 'rp_customer_invoice_lines';
        $invoice = DB::table($table)->lockForUpdate()->find($invoiceId);
        abort_unless($invoice && in_array($invoice->status, self::POSTED[$kind], true), 409, 'يلزم فاتورة مرحّلة.');
        app(ReceivablesPayablesAccess::class)->authorize($actor, 'credit_note_approve', $invoice->branch_id);
        $net = '0.00';
        $tax = '0.00';
        $rows = [];
        $seen = [];
        foreach ($items as $item) {
            $line = DB::table($lineTable)->where('invoice_id', $invoiceId)->lockForUpdate()->find($item['invoice_line_id']);
            abort_unless($line, 409);
            $id = (int) $line->id;
            $seen[$id] ??= $this->priorLine($kind, $id);
            $cumulative = bcadd($seen[$id]['quantity'], $item['quantity'], 8);
            $whole = bccomp($cumulative, $line->quantity, 8) === 0;
            $cumulativeNet = $whole ? $line->net_amount : Decimal::round(bcdiv(bcmul($line->net_amount, $cumulative, Decimal::SCALE), $line->quantity, Decimal::SCALE));
            $cumulativeTax = $whole ? $line->tax_amount : Decimal::round(bcdiv(bcmul($line->tax_amount, $cumulative, Decimal::SCALE), $line->quantity, Decimal::SCALE));
            $lineNet = bcsub($cumulativeNet, $seen[$id]['net'], 2);
            $lineTax = bcsub($cumulativeTax, $seen[$id]['tax'], 2);
            $seen[$id] = ['quantity' => $cumulative, 'net' => $cumulativeNet, 'tax' => $cumulativeTax];
            $rows[] = $item + ['net_amount' => $lineNet, 'tax_amount' => $lineTax];
            $net = bcadd($net, $lineNet, 2);
            $tax = bcadd($tax, $lineTax, 2);
        }
        $amount = bcadd($net, $tax, 2);
        $used = (string) DB::table('rp_credit_notes')->where('kind', $kind)->where('original_invoice_id', $invoiceId)->whereIn('status', ['draft', 'approved', 'posted'])->sum('amount');
        if (bccomp($amount, '0', 2) <= 0 || bccomp(bcadd($used, $amount, 2), $invoice->total_amount, 2) > 0) {
            $this->fail('قيمة المرتجع تتجاوز المتبقي القابل للتصحيح من الفاتورة، أو لا قيمة لها.');
        }
        $id = DB::table('rp_credit_notes')->insertGetId(['creation_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'return-credit:'.$source)->toString(), 'branch_id' => $invoice->branch_id,
            'kind' => $kind, 'original_invoice_id' => $invoiceId, 'party_id' => $kind === 'supplier' ? $invoice->supplier_id : $invoice->customer_id, 'note_date' => $date,
            'reason' => $reason, 'amount' => $amount, 'net_amount' => $net, 'tax_amount' => $tax, 'status' => 'approved', 'created_by' => $creator, 'approved_by' => $actor, 'approved_at' => now(),
            'return_source' => $source, 'source_snapshot' => json_encode(['invoice_id' => $invoiceId, 'number' => $invoice->official_number, 'return' => $source], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
        foreach ($rows as $row) {
            DB::table('rp_credit_note_return_lines')->insert($row + ['credit_note_id' => $id]);
        }

        return app(CreditNotes::class)->post($actor, $id) + ['invoice_id' => $invoiceId, 'amount' => $amount];
    }

    private function priorLine(string $kind, int $line): array
    {
        $row = DB::table('rp_credit_note_return_lines as r')->join('rp_credit_notes as n', 'n.id', '=', 'r.credit_note_id')
            ->where('n.kind', $kind)->where('n.status', 'posted')->where('r.invoice_line_id', $line)
            ->selectRaw('coalesce(sum(r.quantity),0) quantity,coalesce(sum(r.net_amount),0) net,coalesce(sum(r.tax_amount),0) tax')->first();

        return ['quantity' => bcadd($row->quantity, '0', 8), 'net' => bcadd($row->net, '0', 2), 'tax' => bcadd($row->tax, '0', 2)];
    }

    private function positions(string $kind, array $sourceLines): array
    {
        $ids = array_map('intval', $sourceLines);
        $rows = $kind === 'supplier'
            ? DB::table('rp_supplier_invoice_matches as m')->join('rp_supplier_invoice_lines as l', 'l.id', '=', 'm.invoice_line_id')->join('rp_supplier_invoices as i', 'i.id', '=', 'l.invoice_id')
                ->whereIn('m.receipt_line_id', $ids)->whereIn('i.status', self::POSTED[$kind])->orderBy('i.id')->orderBy('l.id')
                ->get(['m.receipt_line_id as source_line_id', 'l.id as invoice_line_id', 'i.id as invoice_id', 'm.quantity'])
            : DB::table('rp_customer_invoice_lines as l')->join('rp_customer_invoices as i', 'i.id', '=', 'l.invoice_id')
                ->whereIn('l.delivery_line_id', $ids)->whereIn('i.status', self::POSTED[$kind])->orderBy('i.id')->orderBy('l.id')
                ->get(['l.delivery_line_id as source_line_id', 'l.id as invoice_line_id', 'i.id as invoice_id', 'l.quantity']);

        return $rows->map(function ($row) use ($kind) {
            $returned = (string) DB::table('rp_credit_note_return_lines as r')->join('rp_credit_notes as n', 'n.id', '=', 'r.credit_note_id')
                ->where('n.kind', $kind)->where('n.status', 'posted')->where('r.invoice_line_id', $row->invoice_line_id)->where('r.source_line_id', $row->source_line_id)->sum('r.quantity');
            $row->remaining = bcsub($row->quantity, $returned, 8);

            return $row;
        })->all();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['return' => $message]);
    }
}

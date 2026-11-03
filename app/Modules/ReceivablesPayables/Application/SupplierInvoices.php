<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupplierInvoices
{
    public function create(int $actor, array $data): int
    {
        Validator::make($data, [
            'creation_key' => 'required|uuid', 'supplier_id' => 'required|integer|min:1',
            'supplier_invoice_number' => 'required|string|max:100|not_regex:/\x00/', 'invoice_date' => 'required|date_format:Y-m-d',
            'due_date' => 'required|date_format:Y-m-d|after_or_equal:invoice_date', 'tax_mode' => 'required|in:inclusive,exclusive',
            'lines' => 'required|array|min:1|max:500', 'lines.*.order_line_id' => 'required|integer|min:1',
            'lines.*.quantity' => 'required|string', 'lines.*.unit_price' => 'required|string', 'lines.*.discount_amount' => 'required|string',
            'lines.*.matches' => 'required|array|min:1', 'lines.*.matches.*.receipt_line_id' => 'required|integer|min:1',
            'lines.*.matches.*.quantity' => 'required|string',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['supplier-invoice:'.$data['creation_key']]);
            $existing = DB::table('rp_supplier_invoices')->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $receiptIds = [];
            foreach ($data['lines'] as $line) {
                Decimal::value($line['quantity'], 8);
                Decimal::value($line['unit_price'], 8);
                Decimal::value($line['discount_amount'], 2);
                foreach ($line['matches'] as $match) {
                    Decimal::value($match['quantity'], 8);
                    $receiptIds[] = (int) $match['receipt_line_id'];
                }
            }
            if (count($receiptIds) !== count(array_unique($receiptIds))) {
                $this->fail('لا يجوز تكرار بند الاستلام في الفاتورة نفسها.');
            }
            $source = app(PurchasingApi::class)->supplierInvoiceSource($actor, $receiptIds);
            $projects = collect($source['lines'])->map(fn ($line) => app(ProjectsApi::class)->sourceProject($actor, 'purchase_order', (int) $line['order_id'], (int) $source['branch_id']))->unique()->values();
            abort_unless($projects->count() === 1, 422, 'مصادر الفاتورة يجب أن تتبع مشروعاً واحداً أو تكون كلها غير مخصصة.');
            $project = $projects->first();
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'supplier_invoice_manage', $source['branch_id']);
            $supplier = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $data['supplier_id']));
            if ($supplier->id !== $source['supplier_id']) {
                $this->fail('هوية المورد لا تطابق أوامر واستلامات المطابقة.');
            }
            $taxInput = array_map(fn ($line) => ['product_id' => $this->sourceLine($source, (int) $line['order_line_id'])['product_id'],
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount' => $line['discount_amount']], $data['lines']);
            $tax = app(AccountingApi::class)->calculateTax($actor, 'purchase', $data['invoice_date'], $data['tax_mode'], $taxInput);
            $id = DB::table('rp_supplier_invoices')->insertGetId([
                'creation_key' => $data['creation_key'], 'branch_id' => $source['branch_id'], 'project_id' => $project, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
                'supplier_invoice_number' => trim($data['supplier_invoice_number']), 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'],
                'tax_mode' => $data['tax_mode'], 'net_amount' => $tax['net'], 'tax_amount' => $tax['tax'], 'total_amount' => $tax['total'],
                'created_by' => $actor, 'source_snapshot' => json_encode(['purchasing' => $source, 'tax' => $tax], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $grni = '0.00';
            $capitalizedInvoice = '0.00';
            foreach (array_values($data['lines']) as $index => $line) {
                $sourceLine = $this->sourceLine($source, (int) $line['order_line_id']);
                $matchedQuantity = '0';
                foreach ($line['matches'] as $match) {
                    $matchedQuantity = bcadd($matchedQuantity, $match['quantity'], 8);
                    $matchedSource = collect($source['lines'])->firstWhere('receipt_line_id', (int) $match['receipt_line_id']);
                    if (! $matchedSource || (int) $matchedSource['order_line_id'] !== (int) $line['order_line_id']) {
                        $this->fail('بند الاستلام لا يطابق بند أمر الشراء المحدد.');
                    }
                }
                if (bccomp($matchedQuantity, $line['quantity'], 8) !== 0) {
                    $this->fail('كمية بند الفاتورة يجب أن تساوي مجموع تخصيصات الاستلام.');
                }
                $taxLine = $tax['lines'][$index];
                $invoiceLine = DB::table('rp_supplier_invoice_lines')->insertGetId([
                    'invoice_id' => $id, 'order_line_id' => $line['order_line_id'], 'product_id' => $sourceLine['product_id'],
                    'description' => $sourceLine['product_name'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'], 'net_amount' => $taxLine['net'], 'tax_amount' => $taxLine['tax'], 'total_amount' => $taxLine['total'],
                    'tax_rule_id' => $taxLine['tax_rule_id'], 'tax_snapshot' => json_encode($taxLine, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'purchase_recoverable' => $taxLine['purchase_recoverable'],
                ]);
                $capitalizedInvoice = bcadd($capitalizedInvoice, $taxLine['purchase_recoverable'] ? $taxLine['net'] : $taxLine['total'], 2);
                foreach ($line['matches'] as $match) {
                    $matchedSource = collect($source['lines'])->firstWhere('receipt_line_id', (int) $match['receipt_line_id']);
                    DB::select('SELECT pg_advisory_xact_lock(12012, ?)', [(int) $match['receipt_line_id']]);
                    $prior = DB::table('rp_supplier_invoice_matches')->where('receipt_line_id', $match['receipt_line_id'])
                        ->selectRaw('coalesce(sum(quantity),0) quantity,coalesce(sum(grni_amount),0) amount')->first();
                    $newQuantity = bcadd($prior->quantity, $match['quantity'], 8);
                    if (bccomp($newQuantity, $matchedSource['received_quantity'], 8) > 0) {
                        $this->fail('كمية المطابقة تتجاوز المتبقي غير المفوتر من الاستلام.');
                    }
                    $cumulative = bccomp($newQuantity, $matchedSource['received_quantity'], 8) === 0 ? $matchedSource['grni_amount']
                        : Decimal::round(bcdiv(bcmul($matchedSource['grni_amount'], $newQuantity, Decimal::SCALE), $matchedSource['received_quantity'], Decimal::SCALE));
                    $amount = bcsub($cumulative, $prior->amount, 2);
                    DB::table('rp_supplier_invoice_matches')->insert(['invoice_line_id' => $invoiceLine, 'receipt_line_id' => $match['receipt_line_id'],
                        'quantity' => $match['quantity'], 'grni_amount' => $amount, 'created_at' => now()]);
                    $grni = bcadd($grni, $amount, 2);
                }
            }
            $ppv = bcsub($capitalizedInvoice, $grni, 2);
            DB::table('rp_supplier_invoices')->where('id', $id)->update(['grni_amount' => $grni, 'ppv_amount' => $ppv]);
            app(RecordAudit::class)->execute($actor, 'supplier_invoice', $id, 'supplier_invoice.created', null,
                ['supplier_id' => $supplier->id, 'supplier_invoice_number' => trim($data['supplier_invoice_number']), 'total' => $tax['total']], 'succeeded', 'ReceivablesPayables');

            return $id;
        }, 5);
    }

    public function transition(int $actor, int $id, int $version, string $from, string $to): void
    {
        DB::transaction(function () use ($actor, $id, $version, $from, $to) {
            $row = DB::table('rp_supplier_invoices')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'supplier_invoice_manage', $row->branch_id);
            if ($row->status !== $from || (int) $row->version !== $version) {
                abort(409, 'تغيرت الفاتورة أو لا تطابق المرحلة المطلوبة.');
            }
            app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $row->supplier_id));
            DB::table('rp_supplier_invoices')->where('id', $id)->update(['status' => $to, 'version' => $version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'supplier_invoice', $id, 'supplier_invoice.'.$to, ['status' => $from], ['status' => $to], 'succeeded', 'ReceivablesPayables');
        });
    }

    public function approve(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            $row = DB::table('rp_supplier_invoices')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'supplier_invoice_post', $row->branch_id);
            if ($row->status !== 'matched' || (int) $row->version !== $version) {
                abort(409, 'يلزم إتمام التحقق والمطابقة قبل الاعتماد.');
            }
            DB::table('rp_supplier_invoices')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'version' => $version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'supplier_invoice', $id, 'supplier_invoice.approved', ['status' => 'matched'], ['status' => 'approved'], 'succeeded', 'ReceivablesPayables');
        });
    }

    public function post(int $actor, int $id, int $version): array
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            app(AccountingApi::class)->lockPosting();
            $row = DB::table('rp_supplier_invoices')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'supplier_invoice_post', $row->branch_id);
            if (in_array($row->status, ['posted', 'partially_paid', 'paid', 'credited'], true)) {
                return ['invoice_id' => $id, 'journal_id' => (int) $row->journal_id, 'number' => $row->official_number];
            }
            if ($row->status !== 'approved' || (int) $row->version !== $version) {
                abort(409, 'الفاتورة غير معتمدة أو تغير إصدارها.');
            }
            app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) $row->supplier_id));
            $lines = [['purpose' => 'grni', 'debit' => $row->grni_amount, 'credit' => '0.00']];
            $recoverableTax = DB::table('rp_supplier_invoice_lines')->where('invoice_id', $id)->where('purchase_recoverable', true)->sum('tax_amount');
            $recoverableTax = Decimal::round((string) $recoverableTax);
            if (bccomp($recoverableTax, '0', 2) > 0) {
                $lines[] = ['purpose' => 'tax_input', 'debit' => $recoverableTax, 'credit' => '0.00'];
            }
            if (bccomp($row->ppv_amount, '0', 2) > 0) {
                $lines[] = ['purpose' => 'ppv', 'debit' => $row->ppv_amount, 'credit' => '0.00'];
            } elseif (bccomp($row->ppv_amount, '0', 2) < 0) {
                $lines[] = ['purpose' => 'ppv', 'debit' => '0.00', 'credit' => bcsub('0', $row->ppv_amount, 2)];
            }
            $lines[] = ['purpose' => 'ap', 'debit' => '0.00', 'credit' => $row->total_amount];
            $journal = app(AccountingApi::class)->postSourceDocument($actor, (int) $row->created_by, (int) $row->branch_id, $row->invoice_date,
                'supplier-invoice:'.$id, 'فاتورة مورد '.$row->supplier_invoice_number, $lines, $row->project_id === null ? null : (int) $row->project_id);
            $document = app(OfficialDocuments::class)->issue($actor, 'supplier_invoice', (string) $id, 1, $row->invoice_date,
                ['title' => 'فاتورة مورد', 'fields' => ['المورد' => $row->supplier_name, 'رقم المورد' => $row->supplier_invoice_number],
                    'columns' => ['البيان', 'الصافي', 'الضريبة', 'الإجمالي'], 'rows' => DB::table('rp_supplier_invoice_lines')->where('invoice_id', $id)
                        ->orderBy('id')->get()->map(fn ($line) => [$line->description, $line->net_amount, $line->tax_amount, $line->total_amount])->all(),
                    'totals' => ['الصافي' => $row->net_amount, 'الضريبة' => $row->tax_amount, 'الإجمالي' => $row->total_amount]]);
            DB::table('rp_open_items')->insert(['branch_id' => $row->branch_id, 'party_type' => 'supplier', 'party_id' => $row->supplier_id,
                'source_type' => 'supplier_invoice', 'source_id' => $id, 'source_number' => $document['number'], 'document_date' => $row->invoice_date,
                'due_date' => $row->due_date, 'original_amount' => $row->total_amount, 'created_at' => now()]);
            DB::table('rp_supplier_invoices')->where('id', $id)->update(['status' => 'posted', 'posted_by' => $actor, 'posted_at' => now(),
                'journal_id' => $journal, 'official_document_id' => $document['id'], 'official_number' => $document['number'], 'version' => $version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'supplier_invoice', $id, 'supplier_invoice.posted', ['status' => 'approved'],
                ['status' => 'posted', 'journal_id' => $journal, 'number' => $document['number'], 'sha256' => $document['sha256']], 'succeeded', 'ReceivablesPayables');

            return ['invoice_id' => $id, 'journal_id' => $journal, 'number' => $document['number']];
        }, 5);
    }

    private function sourceLine(array $source, int $orderLine): array
    {
        $line = collect($source['lines'])->firstWhere('order_line_id', $orderLine);
        if (! $line) {
            $this->fail('بند أمر الشراء ليس ضمن مصادر الاستلام المحددة.');
        }

        return $line;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['supplier_invoice' => $message]);
    }
}

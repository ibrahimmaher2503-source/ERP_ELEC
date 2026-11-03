<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreditNotes
{
    public function create(int $actor, array $data, ?int $contractCorrection = null): int
    {
        Validator::make($data, ['creation_key' => 'required|uuid', 'kind' => 'required|in:supplier,customer', 'invoice_id' => 'required|integer|min:1',
            'note_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|min:5|max:2000', 'amount' => 'required|string'])->validate();
        $amount = Decimal::round(Decimal::value($data['amount'], 2));

        return DB::transaction(function () use ($actor, $data, $amount, $contractCorrection) {
            app(AccountingApi::class)->lockPosting();
            $table = $data['kind'] === 'supplier' ? 'rp_supplier_invoices' : 'rp_customer_invoices';
            $invoice = DB::table($table)->lockForUpdate()->find((int) $data['invoice_id']);
            $allowed = $data['kind'] === 'supplier' ? ['posted', 'partially_paid', 'paid', 'credited'] : ['posted', 'partially_collected', 'collected', 'credited'];
            abort_unless($invoice && in_array($invoice->status, $allowed, true), 409, 'يلزم مستند فاتورة مرحّل.');
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'credit_note_manage', $invoice->branch_id);
            if ($data['kind'] === 'customer') {
                abort_unless(($invoice->service_contract_source_id !== null) === ($contractCorrection !== null), 409, 'تصحيح فاتورة العقد يبدأ من مصدر العقد.');
            } else {
                abort_unless($contractCorrection === null, 422);
            }
            $existing = DB::table('rp_credit_notes')->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                abort_unless((int) $existing->created_by === $actor && $existing->kind === $data['kind'] && (int) $existing->original_invoice_id === (int) $invoice->id
                    && $existing->note_date === $data['note_date'] && $existing->reason === $data['reason'] && bccomp($existing->amount, $amount, 2) === 0 && $existing->contract_correction_id === $contractCorrection, 409);

                return (int) $existing->id;
            }
            app(AccountingApi::class)->requirePostingDate($data['note_date']);
            $used = (string) DB::table('rp_credit_notes')->where('kind', $data['kind'])->where('original_invoice_id', $invoice->id)
                ->whereIn('status', ['draft', 'approved', 'posted'])->sum('amount');
            if (bccomp($amount, '0', 2) <= 0 || bccomp(bcadd($used, $amount, 2), $invoice->total_amount, 2) > 0) {
                $this->fail('الإشعار يتجاوز القيمة المتبقية القابلة للتصحيح من الفاتورة الأصلية.');
            }
            $tax = Decimal::round(bcdiv(bcmul($invoice->tax_amount, $amount, Decimal::SCALE), $invoice->total_amount, Decimal::SCALE));
            $net = bcsub($amount, $tax, 2);
            if ($contractCorrection !== null) {
                app(ServiceContractsApi::class)->validateBillingCredit($actor, $contractCorrection, (int) $invoice->service_contract_source_id, (int) $invoice->branch_id, $net, $amount, $data['note_date']);
            }
            $party = $data['kind'] === 'supplier' ? $invoice->supplier_id : $invoice->customer_id;
            $id = DB::table('rp_credit_notes')->insertGetId(['creation_key' => $data['creation_key'], 'branch_id' => $invoice->branch_id,
                'kind' => $data['kind'], 'original_invoice_id' => $invoice->id, 'party_id' => $party, 'note_date' => $data['note_date'],
                'reason' => $data['reason'], 'amount' => $amount, 'net_amount' => $net, 'tax_amount' => $tax, 'created_by' => $actor, 'contract_correction_id' => $contractCorrection,
                'source_snapshot' => json_encode(['invoice_id' => $invoice->id, 'number' => $invoice->official_number, 'total' => $invoice->total_amount,
                    'net' => $invoice->net_amount, 'tax' => $invoice->tax_amount], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'credit_note', $id, 'credit_note.created', null,
                ['kind' => $data['kind'], 'invoice_id' => $invoice->id, 'amount' => $amount], 'succeeded', 'ReceivablesPayables');

            return $id;
        }, 5);
    }

    public function approve(int $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $note = DB::table('rp_credit_notes')->lockForUpdate()->find($id);
            abort_unless($note, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'credit_note_approve', $note->branch_id);
            if ($note->status !== 'draft' || (int) $note->created_by === $actor) {
                abort(409, 'اعتماد الإشعار يجب أن يكون مستقلاً.');
            }
            DB::table('rp_credit_notes')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()]);
        });
    }

    public function post(int $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            app(AccountingApi::class)->lockPosting();
            $note = DB::table('rp_credit_notes')->lockForUpdate()->find($id);
            abort_unless($note, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'credit_note_approve', $note->branch_id);
            if ($note->status === 'posted') {
                return ['credit_note_id' => $id, 'journal_id' => (int) $note->journal_id, 'number' => $note->official_number];
            }
            abort_unless($note->status === 'approved', 409, 'يلزم اعتماد الإشعار قبل الترحيل.');
            $sourceType = $note->kind === 'supplier' ? 'supplier_invoice' : 'customer_invoice';
            $open = DB::table('rp_open_items')->where('source_type', $sourceType)->where('source_id', $note->original_invoice_id)->lockForUpdate()->first();
            abort_unless($open, 409, 'بند الذمة الأصلي غير موجود.');
            $apply = bccomp($note->amount, $open->remaining_amount, 2) <= 0 ? $note->amount : $open->remaining_amount;
            $excess = bcsub($note->amount, $apply, 2);
            $lines = $note->kind === 'supplier' ? $this->supplierJournal($note) : $this->customerJournal($actor, $note);
            $project = DB::table($note->kind === 'supplier' ? 'rp_supplier_invoices' : 'rp_customer_invoices')->where('id', $note->original_invoice_id)->value('project_id');
            $journal = app(AccountingApi::class)->postSourceDocument($actor, (int) $note->created_by, (int) $note->branch_id, $note->note_date,
                $note->kind.'-credit-note:'.$id, 'إشعار تصحيح مرتبط بالفاتورة #'.$note->original_invoice_id, $lines, $project === null ? null : (int) $project);
            $document = app(OfficialDocuments::class)->issue($actor, $note->kind === 'supplier' ? 'supplier_credit_note' : 'customer_credit_note',
                (string) $id, 1, $note->note_date, ['title' => 'إشعار دائن', 'fields' => ['السبب' => $note->reason, 'الفاتورة' => (string) $note->original_invoice_id],
                    'columns' => ['الصافي', 'الضريبة', 'الإجمالي'], 'rows' => [[$note->net_amount, $note->tax_amount, $note->amount]], 'totals' => ['الإجمالي' => $note->amount]]);
            if (bccomp($apply, '0', 2) > 0) {
                DB::table('rp_open_items')->where('id', $open->id)->update(['credited_amount' => bcadd($open->credited_amount, $apply, 2)]);
            }
            if (bccomp($excess, '0', 2) > 0) {
                DB::table('rp_party_credits')->insert(['credit_note_id' => $id, 'branch_id' => $note->branch_id, 'party_type' => $note->kind,
                    'party_id' => $note->party_id, 'original_amount' => $excess, 'created_at' => now()]);
            }
            DB::table('rp_credit_notes')->where('id', $id)->update(['status' => 'posted', 'posted_by' => $actor, 'posted_at' => now(),
                'journal_id' => $journal, 'official_document_id' => $document['id'], 'official_number' => $document['number']]);
            $this->markFullyCredited($note);
            app(RecordAudit::class)->execute($actor, 'credit_note', $id, 'credit_note.posted', ['status' => 'approved'],
                ['status' => 'posted', 'journal_id' => $journal, 'applied' => $apply, 'party_credit' => $excess], 'succeeded', 'ReceivablesPayables');

            return ['credit_note_id' => $id, 'journal_id' => $journal, 'number' => $document['number']];
        }, 5);
    }

    private function supplierJournal(object $note): array
    {
        $invoice = DB::table('rp_supplier_invoices')->find($note->original_invoice_id);
        $recoverable = (string) DB::table('rp_supplier_invoice_lines')->where('invoice_id', $invoice->id)->where('purchase_recoverable', true)->sum('tax_amount');
        $tax = Decimal::round(bcdiv(bcmul($recoverable, $note->amount, Decimal::SCALE), $invoice->total_amount, Decimal::SCALE));
        $lines = [['purpose' => 'ap', 'debit' => $note->amount, 'credit' => '0.00'],
            ['purpose' => 'ppv', 'debit' => '0.00', 'credit' => bcsub($note->amount, $tax, 2)]];
        if (bccomp($tax, '0', 2) > 0) {
            $lines[] = ['purpose' => 'tax_input', 'debit' => '0.00', 'credit' => $tax];
        }

        return $lines;
    }

    private function customerJournal(int $actor, object $note): array
    {
        $invoice = DB::table('rp_customer_invoices')->find($note->original_invoice_id);
        if ($invoice->service_contract_source_id !== null) {
            abort_unless($note->contract_correction_id !== null, 409, 'يلزم تصحيح مرتبط بمصدر العقد.');
            app(ServiceContractsApi::class)->validateBillingCredit($actor, (int) $note->contract_correction_id, (int) $invoice->service_contract_source_id, (int) $invoice->branch_id, $note->net_amount, $note->amount, $note->note_date);
        }
        $lines = [['purpose' => app(ContractInvoices::class)->revenuePurpose($invoice), 'debit' => $note->net_amount, 'credit' => '0.00']];
        if (bccomp($note->tax_amount, '0', 2) > 0) {
            $lines[] = ['purpose' => 'tax_output', 'debit' => $note->tax_amount, 'credit' => '0.00'];
        }
        $lines[] = ['purpose' => 'receivable', 'debit' => '0.00', 'credit' => $note->amount];

        return $lines;
    }

    private function markFullyCredited(object $note): void
    {
        $total = (string) DB::table('rp_credit_notes')->where('kind', $note->kind)->where('original_invoice_id', $note->original_invoice_id)->where('status', 'posted')->sum('amount');
        $table = $note->kind === 'supplier' ? 'rp_supplier_invoices' : 'rp_customer_invoices';
        $invoice = DB::table($table)->find($note->original_invoice_id);
        if (bccomp($total, $invoice->total_amount, 2) === 0) {
            DB::table($table)->where('id', $invoice->id)->update(['status' => 'credited', 'updated_at' => now()]);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['credit_note' => $message]);
    }
}

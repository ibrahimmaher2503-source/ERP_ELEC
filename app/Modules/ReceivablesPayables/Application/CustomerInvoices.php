<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Application\TaxCalculator;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Sales\Application\Contracts\SalesDeliverySources;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CustomerInvoices
{
    public function create(int $actor, array $data): int
    {
        Validator::make($data, [
            'creation_key' => 'required|uuid', 'customer_id' => 'required|integer|min:1', 'invoice_date' => 'required|date_format:Y-m-d',
            'due_date' => 'required|date_format:Y-m-d|after_or_equal:invoice_date',
            'tax_treatment' => 'sometimes|nullable|in:inherit,rule,vat_14,exempt_0',
            'tax_exempt_reason' => 'nullable|string|max:2000',
            'lines' => 'required|array|min:1|max:500',
            'lines.*.delivery_line_id' => 'required|integer|min:1', 'lines.*.quantity' => 'required|string',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['customer-invoice:'.$data['creation_key']]);
            $existing = DB::table('rp_customer_invoices')->where('creation_key', $data['creation_key'])->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $ids = array_map(fn ($line) => (int) $line['delivery_line_id'], $data['lines']);
            if (count($ids) !== count(array_unique($ids))) {
                $this->fail('لا يجوز تكرار بند التسليم في الفاتورة.');
            }
            foreach ($data['lines'] as $line) {
                Decimal::value($line['quantity'], 8);
            }
            $source = app(SalesDeliverySources::class)->customerInvoiceSource($actor, $ids);
            $projects = collect($source['lines'])->map(fn ($line) => app(ProjectsApi::class)->sourceProject($actor, 'sales_order', (int) $line['order_id'], (int) $source['branch_id'], (int) $source['customer_id']))->unique()->values();
            abort_unless($projects->count() === 1, 422, 'مصادر الفاتورة يجب أن تتبع مشروعاً واحداً أو تكون كلها غير مخصصة.');
            $project = $projects->first();
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'customer_invoice_manage', $source['branch_id']);
            $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $data['customer_id']));
            if ($customer->id !== $source['customer_id']) {
                $this->fail('هوية العميل لا تطابق أمر البيع والتسليم.');
            }
            app(AccountingApi::class)->requirePostingDate($data['invoice_date']);

            $orderTreatment = (string) ($source['tax_treatment'] ?? 'rule');
            $orderReason = $source['tax_exempt_reason'] ?? null;
            $requested = (string) ($data['tax_treatment'] ?? 'inherit');
            $effectiveTreatment = $requested === 'inherit' ? $orderTreatment : $requested;
            $effectiveReason = $effectiveTreatment === 'exempt_0'
                ? trim((string) ($data['tax_exempt_reason'] ?? ($requested === 'inherit' ? ($orderReason ?? '') : '')))
                : null;
            if ($effectiveTreatment === 'exempt_0' && ($effectiveReason === null || mb_strlen($effectiveReason) < 5)) {
                $this->fail('يجب كتابة سبب واضح (5 أحرف على الأقل) عند اختيار "بدون ضريبة".');
            }
            $overrodeOrderTax = $effectiveTreatment !== $orderTreatment;

            $id = DB::table('rp_customer_invoices')->insertGetId([
                'creation_key' => $data['creation_key'], 'branch_id' => $source['branch_id'], 'project_id' => $project,
                'customer_id' => $customer->id, 'customer_name' => $customer->name, 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'],
                'tax_treatment' => $effectiveTreatment, 'tax_exempt_reason' => $effectiveReason,
                'created_by' => $actor, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $net = '0.00';
            $tax = '0.00';
            foreach ($data['lines'] as $input) {
                $sourceLine = collect($source['lines'])->firstWhere('delivery_line_id', (int) $input['delivery_line_id']);
                if (! $sourceLine) {
                    $this->fail('بند التسليم غير موجود في المصدر المعتمد.');
                }
                DB::select('SELECT pg_advisory_xact_lock(12013, ?)', [(int) $input['delivery_line_id']]);
                $priorDelivery = DB::table('rp_customer_invoice_lines')->where('delivery_line_id', $input['delivery_line_id'])
                    ->selectRaw('coalesce(sum(quantity),0) quantity')->value('quantity');
                if (bccomp(bcadd((string) $priorDelivery, $input['quantity'], 8), $sourceLine['delivered_quantity'], 8) > 0) {
                    $this->fail('الكمية المفوترة تتجاوز الكمية المسلمة المتبقية.');
                }
                DB::select('SELECT pg_advisory_xact_lock(12014, ?)', [(int) $sourceLine['order_line_id']]);
                $priorOrder = DB::table('rp_customer_invoice_lines')->where('sales_order_line_id', $sourceLine['order_line_id'])
                    ->selectRaw('coalesce(sum(quantity),0) quantity,coalesce(sum(net_amount),0) net,coalesce(sum(tax_amount),0) tax')->first();
                $cumulativeQty = bcadd($priorOrder->quantity, $input['quantity'], 8);
                if (bccomp($cumulativeQty, $sourceLine['ordered_quantity'], 8) > 0) {
                    $this->fail('الفوترة تتجاوز كمية أمر البيع المعتمدة.');
                }
                $frozen = $sourceLine['tax'];
                $cumulativeNet = bccomp($cumulativeQty, $sourceLine['ordered_quantity'], 8) === 0 ? $frozen['net']
                    : Decimal::round(bcdiv(bcmul($frozen['net'], $cumulativeQty, Decimal::SCALE), $sourceLine['ordered_quantity'], Decimal::SCALE));
                $lineNet = bcsub($cumulativeNet, $priorOrder->net, 2);

                if (! $overrodeOrderTax) {
                    $cumulativeTax = bccomp($cumulativeQty, $sourceLine['ordered_quantity'], 8) === 0 ? $frozen['tax']
                        : Decimal::round(bcdiv(bcmul($frozen['tax'], $cumulativeQty, Decimal::SCALE), $sourceLine['ordered_quantity'], Decimal::SCALE));
                    $lineTax = bcsub($cumulativeTax, $priorOrder->tax, 2);
                    $lineTaxRuleId = $frozen['tax_rule_id'] ?? null;
                    $lineSnapshot = $frozen;
                } elseif ($effectiveTreatment === 'exempt_0') {
                    $lineTax = '0.00';
                    $lineTaxRuleId = null;
                    $lineSnapshot = array_replace($frozen, [
                        'tax_rule_id' => null, 'tax_code' => 'DOC-NO-TAX', 'rate' => '0.00000', 'treatment' => 'exempt',
                        'net' => $lineNet, 'tax' => '0.00', 'total' => $lineNet,
                        'document_tax_choice' => 'exempt_0', 'tax_exempt_reason' => $effectiveReason, 'overrode_order_tax' => true,
                    ]);
                } else {
                    $calc = app(AccountingApi::class)->calculateTax(
                        $actor,
                        'sale',
                        $data['invoice_date'],
                        'exclusive',
                        [['product_id' => $sourceLine['product_id'], 'quantity' => '1', 'unit_price' => $lineNet, 'discount' => '0']],
                        '0',
                        ['treatment' => $effectiveTreatment]
                    );
                    $lineTax = $calc['lines'][0]['tax'];
                    $lineTaxRuleId = $calc['lines'][0]['tax_rule_id'] ?? null;
                    $lineSnapshot = $calc['lines'][0] + ['overrode_order_tax' => true];
                }

                DB::table('rp_customer_invoice_lines')->insert([
                    'invoice_id' => $id, 'sales_order_line_id' => $sourceLine['order_line_id'],
                    'delivery_line_id' => $input['delivery_line_id'], 'product_id' => $sourceLine['product_id'], 'description' => $sourceLine['description'],
                    'quantity' => $input['quantity'], 'net_amount' => $lineNet, 'tax_amount' => $lineTax, 'total_amount' => bcadd($lineNet, $lineTax, 2),
                    'tax_rule_id' => $lineTaxRuleId, 'tax_snapshot' => json_encode($lineSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                $net = bcadd($net, $lineNet, 2);
                $tax = bcadd($tax, $lineTax, 2);
            }
            DB::table('rp_customer_invoices')->where('id', $id)->update(['net_amount' => $net, 'tax_amount' => $tax, 'total_amount' => bcadd($net, $tax, 2)]);
            app(RecordAudit::class)->execute($actor, 'customer_invoice', $id, 'customer_invoice.created', null, [
                'customer_id' => $customer->id, 'total' => bcadd($net, $tax, 2),
                'tax_treatment' => $effectiveTreatment, 'tax_exempt_reason' => $effectiveReason, 'overrode_order_tax' => $overrodeOrderTax,
            ], 'succeeded', 'ReceivablesPayables');

            return $id;
        }, 5);
    }

    public function validate(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            app(AccountingApi::class)->lockPosting();
            $row = DB::table('rp_customer_invoices')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'customer_invoice_manage', $row->branch_id);
            if ($row->status !== 'draft' || (int) $row->version !== $version) {
                abort(409, 'تغيرت فاتورة العميل أو سبق التحقق منها.');
            }
            app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $row->customer_id));
            if ($row->workshop_job_id !== null) {
                app(WorkshopInvoices::class)->validateSource($actor, $row);
            }
            if ($row->maintenance_work_order_id !== null) {
                app(ServiceInvoices::class)->validateSource($actor, $row);
            }
            if ($row->service_contract_source_id !== null) {
                app(ContractInvoices::class)->validateSource($actor, $row);
            }
            DB::table('rp_customer_invoices')->where('id', $id)->update(['status' => 'validated', 'version' => $version + 1, 'updated_at' => now()]);
        });
    }

    public function post(int $actor, int $id, int $version): array
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            app(AccountingApi::class)->lockPosting();
            $row = DB::table('rp_customer_invoices')->lockForUpdate()->find($id);
            abort_unless($row, 404);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'customer_invoice_post', $row->branch_id);
            if (in_array($row->status, ['posted', 'partially_collected', 'collected', 'credited'], true)) {
                return ['invoice_id' => $id, 'journal_id' => (int) $row->journal_id, 'number' => $row->official_number];
            }
            if ($row->status !== 'validated' || (int) $row->version !== $version) {
                abort(409, 'يلزم التحقق من الفاتورة قبل الإصدار والترحيل.');
            }
            if ($row->workshop_job_id !== null) {
                app(WorkshopInvoices::class)->validateSource($actor, $row);
            }
            if ($row->maintenance_work_order_id !== null) {
                app(ServiceInvoices::class)->validateSource($actor, $row);
            }
            if ($row->service_contract_source_id !== null) {
                app(ContractInvoices::class)->validateSource($actor, $row);
            }
            app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) $row->customer_id));
            $journalLines = [['purpose' => 'receivable', 'debit' => $row->total_amount, 'credit' => '0.00'],
                ['purpose' => app(ContractInvoices::class)->revenuePurpose($row), 'debit' => '0.00', 'credit' => $row->net_amount]];
            if (bccomp($row->tax_amount, '0', 2) > 0) {
                $journalLines[] = ['purpose' => 'tax_output', 'debit' => '0.00', 'credit' => $row->tax_amount];
            }
            $journal = app(AccountingApi::class)->postSourceDocument($actor, (int) $row->created_by, (int) $row->branch_id, $row->invoice_date,
                'customer-invoice:'.$id, 'فاتورة عميل #'.$id, $journalLines, $row->project_id === null ? null : (int) $row->project_id);
            $taxTreatment = $row->tax_treatment ?? 'rule';
            $fields = ['العميل' => $row->customer_name, 'المعاملة الضريبية' => TaxCalculator::treatmentLabel($taxTreatment)];
            if ($taxTreatment === 'exempt_0' && $row->tax_exempt_reason) {
                $fields['سبب عدم احتساب الضريبة'] = $row->tax_exempt_reason;
            }
            $document = app(OfficialDocuments::class)->issue($actor, 'customer_invoice', (string) $id, 1, $row->invoice_date,
                ['title' => 'فاتورة عميل', 'fields' => $fields, 'columns' => ['البيان', 'الصافي', 'الضريبة', 'الإجمالي'],
                    'rows' => DB::table('rp_customer_invoice_lines')->where('invoice_id', $id)->orderBy('id')->get()
                        ->map(fn ($line) => [$line->description, $line->net_amount, $line->tax_amount, $line->total_amount])->all(),
                    'totals' => ['الصافي' => $row->net_amount, 'الضريبة' => $row->tax_amount, 'الإجمالي' => $row->total_amount]]);
            DB::table('rp_open_items')->insert(['branch_id' => $row->branch_id, 'party_type' => 'customer', 'party_id' => $row->customer_id,
                'source_type' => 'customer_invoice', 'source_id' => $id, 'source_number' => $document['number'], 'document_date' => $row->invoice_date,
                'due_date' => $row->due_date, 'original_amount' => $row->total_amount, 'created_at' => now()]);
            DB::table('rp_customer_invoices')->where('id', $id)->update(['status' => 'posted', 'posted_by' => $actor, 'posted_at' => now(),
                'journal_id' => $journal, 'official_document_id' => $document['id'], 'official_number' => $document['number'], 'version' => $version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute($actor, 'customer_invoice', $id, 'customer_invoice.posted', ['status' => 'validated'],
                ['status' => 'posted', 'journal_id' => $journal, 'number' => $document['number'], 'sha256' => $document['sha256']], 'succeeded', 'ReceivablesPayables');

            return ['invoice_id' => $id, 'journal_id' => $journal, 'number' => $document['number']];
        }, 5);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['customer_invoice' => $message]);
    }
}

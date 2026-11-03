<?php

namespace App\Modules\ReceivablesPayables\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ServiceInvoices
{
    public function handoff(int $actor, int $work, int $branch): ?array
    {
        app(FieldMaintenanceApi::class)->authorizeReconciliation($actor, $work, $branch);
        $i = DB::table('rp_customer_invoices')->where('maintenance_work_order_id', $work)->where('branch_id', $branch)->first();

        return $i ? ['id' => $i->id, 'status' => $i->status, 'number' => $i->official_number, 'version' => $i->version, 'total' => null, 'remaining' => null, 'balance_available' => false, 'can_view' => app(ReceivablesPayablesAccess::class)->allows($actor, 'customer_invoice_view', $branch)] : null;
    }

    public function validateSource(int $actor, object $invoice): void
    {
        $source = json_decode($invoice->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
        app(FieldMaintenanceApi::class)->validateBillingEvidence($actor, (int) $invoice->maintenance_work_order_id, (int) $invoice->branch_id, (int) $invoice->customer_id, (int) $source['approval_id']);
    }

    public function create(int $actor, array $input): int
    {
        $d = Validator::make($input, ['work_order_id' => 'required|integer|min:1', 'creation_key' => 'required|uuid', 'invoice_date' => 'required|date_format:Y-m-d', 'due_date' => 'required|date_format:Y-m-d|after_or_equal:invoice_date'])->validate();

        return DB::transaction(function () use ($actor, $d) {
            $source = app(FieldMaintenanceApi::class)->billingSource($actor, (int) $d['work_order_id']);
            app(ReceivablesPayablesAccess::class)->authorize($actor, 'customer_invoice_manage', $source['branch_id']);
            $old = DB::table('rp_customer_invoices')->where('creation_key', $d['creation_key'])->first();
            if ($old) {
                abort_unless($old->maintenance_work_order_id === (int) $d['work_order_id'] && $old->created_by === $actor && $old->invoice_date === $d['invoice_date'] && $old->due_date === $d['due_date'], 409);

                return $old->id;
            }
            abort_if(DB::table('rp_customer_invoices')->where('maintenance_work_order_id', $d['work_order_id'])->exists(), 409, 'الخدمة محالة للفوترة بالفعل.');
            $customer = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $source['customer_id']));
            app(AccountingApi::class)->requirePostingDate($d['invoice_date']);
            abort_unless($d['invoice_date'] === ($source['tax']['approved_tax_date'] ?? null), 422, 'تاريخ الفاتورة يجب أن يطابق المعاملة الضريبية المعتمدة للخدمة.');
            $total = bcadd($source['net_amount'], $source['tax_amount'], 2);
            $id = DB::table('rp_customer_invoices')->insertGetId(['creation_key' => $d['creation_key'], 'branch_id' => $source['branch_id'], 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'project_id' => $source['project_id'], 'maintenance_work_order_id' => $d['work_order_id'], 'invoice_date' => $d['invoice_date'], 'due_date' => $d['due_date'], 'net_amount' => $source['net_amount'], 'tax_amount' => $source['tax_amount'], 'total_amount' => $total, 'created_by' => $actor, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR)]);
            DB::table('rp_customer_invoice_lines')->insert(['invoice_id' => $id, 'maintenance_approval_id' => $source['approval_id'], 'product_id' => $source['product_id'], 'description' => mb_substr($source['description'], 0, 500), 'quantity' => '1', 'net_amount' => $source['net_amount'], 'tax_amount' => $source['tax_amount'], 'total_amount' => $total, 'tax_rule_id' => $source['tax']['tax_rule_id'], 'tax_snapshot' => json_encode($source['tax'], JSON_THROW_ON_ERROR)]);
            app(RecordAudit::class)->execute($actor, 'customer_invoice', $id, 'service_invoice.created', null, ['work_order_id' => $d['work_order_id'], 'approval_id' => $source['approval_id']], 'succeeded', 'ReceivablesPayables');

            return $id;
        }, 5);
    }

    public function status(int $actor, int $work, int $branch): ?array
    {
        app(ReceivablesPayablesAccess::class)->authorize($actor, 'ar_view', $branch);
        $i = DB::table('rp_customer_invoices')->where('maintenance_work_order_id', $work)->where('branch_id', $branch)->first();
        if (! $i) {
            return null;
        }
        $open = DB::table('rp_open_items')->where('source_type', 'customer_invoice')->where('source_id', $i->id)->first();

        return ['id' => $i->id, 'status' => $i->status, 'version' => $i->version, 'number' => $i->official_number, 'total' => $i->total_amount, 'remaining' => $open?->remaining_amount, 'journal_id' => $i->journal_id, 'balance_available' => true, 'can_view' => app(ReceivablesPayablesAccess::class)->allows($actor, 'customer_invoice_view', $branch)];
    }
}

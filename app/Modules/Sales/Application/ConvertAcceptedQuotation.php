<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationChanged;
use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** One controlled full conversion. Commercial acceptance remains a separate state. */
final class ConvertAcceptedQuotation
{
    public function execute(int $actor, int $id, int $version, int $branch, string $key): SalesOrder
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, 'convert');
        app(TenderQuotationDraft::class)->authorize($actor, $id, null, $branch);
        app(SalesOrderAccess::class)->authorize($actor, 'convert', $branch);
        Validator::make(['key' => $key], ['key' => 'required|uuid'])->validate();

        return DB::transaction(function () use ($actor, $id, $version, $branch, $key) {
            $r = Quotation::with('lines')->lockForUpdate()->findOrFail($id);
            $existing = DB::table('sales_quotation_conversions')->where('quotation_id', $id)->first();
            if ($existing) {
                $order = SalesOrder::with('lines')->findOrFail($existing->order_id);
                app(SalesOrderAccess::class)->authorize($actor, 'view', $order->branch_id);
                abort_unless($existing->creation_key === $key && $order->branch_id === $branch, 409, 'تم تحويل كامل العرض سابقًا.');

                return $order;
            }
            app(QuotationActions::class)->version($r, $version);
            abort_if(SalesOrder::where('draft_key', $key)->exists(), 409, 'مرجع الإنشاء مستخدم لأمر آخر.');
            $issue = DB::table('sales_quotation_issues')->where('quotation_id', $id)->first();
            $acceptance = DB::table('sales_quotation_acceptances')->where('quotation_id', $id)->first();
            abort_unless($r->status === 'accepted' && $issue && $acceptance && $acceptance->issue_id === $issue->id, 409, 'يلزم إصدار رسمي وقبول مكتوب مرتبط به.');
            abort_unless($r->valid_until && $r->valid_until->toDateString() >= now('Africa/Cairo')->toDateString(), 422, 'انتهت صلاحية العرض قبل التحويل.');
            $snapshot = json_decode($issue->commercial_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $quote = $snapshot['quotation'];
            $input = array_intersect_key($quote, array_flip(['customer_id', 'project_name', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'notes']));
            $input += ['creation_key' => $key, 'branch_id' => $branch, 'order_date' => now('Africa/Cairo')->toDateString(),
                'customer_reference' => $acceptance->reference, 'lines' => []];
            foreach ($snapshot['lines'] as $line) {
                $input['lines'][] = array_intersect_key($line, array_flip(['product_id', 'unit_id', 'description', 'quantity', 'discount_mode', 'discount_value', 'override_reason']))
                    + ['requested_price' => $line['requested_price'] ?? $line['suggested_price']];
            }
            $order = app(SalesOrderActions::class)->save($actor, $input);
            // Saving validates live master identities; accepted commercial facts come
            // exclusively from the immutable issued revision, never current repricing.
            foreach ($order->lines as $i => $line) {
                $source = $snapshot['lines'][$i];
                $line->update(['suggested_price' => $source['suggested_price'], 'requested_price' => $source['requested_price'],
                    'price_source' => $source['price_source'], 'product_snapshot' => $source['product_snapshot']]);
            }
            $order->update(['source_type' => 'quotation', 'customer_snapshot' => $quote['customer_snapshot'],
                'calculation' => $order->calculation + ['accepted_tax_snapshot' => json_decode($issue->tax_snapshot, true, 512, JSON_THROW_ON_ERROR)]]);
            DB::table('sales_quotation_conversions')->insert(['quotation_id' => $id, 'acceptance_id' => $acceptance->id, 'order_id' => $order->id,
                'creation_key' => $key, 'converted_by' => $actor, 'converted_at' => now()]);
            $r->update(['version' => $version + 1, 'updated_by' => $actor]);
            event(new QuotationChanged($actor, $id, 'fully_converted', null, ['order_id' => $order->id, 'acceptance_id' => $acceptance->id, 'issue_id' => $issue->id]));

            return $order->fresh('lines');
        });
    }
}

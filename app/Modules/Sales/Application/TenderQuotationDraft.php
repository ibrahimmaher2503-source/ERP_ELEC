<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TenderQuotationDraft
{
    public function create(int $actor, int $tender, int $result, array $input): int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $source = app(ProjectsApi::class)->tenderAward($actor, $tender, $result, $input);
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, 'create');
        if ($old = DB::table('sales_tender_sources')->where('tender_id', $tender)->first()) {
            abort_unless($old->result_id === $result, 409);

            return $old->quotation_id;
        }
        $d = Validator::make($input, ['date' => 'required|date_format:Y-m-d', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:date', 'reference' => 'required|string|min:5|max:2000', 'lines' => 'required|array|min:1|max:500', 'lines.*.product_id' => 'required|integer', 'lines.*.unit_id' => 'required|integer'])->validate();
        $financial = $source['financial'];
        abort_unless(count($d['lines']) === count($financial['lines']), 422, 'يلزم تعيين كل بند من العرض الفائز.');
        $lines = [];
        foreach ($financial['lines'] as $i => $line) {
            $mapped = $d['lines'][$i];
            abort_if($line['product_id'] && ((int) $mapped['product_id'] !== $line['product_id'] || (int) $mapped['unit_id'] !== $line['unit_id']), 422, 'تعيين الصنف المعتمد ثابت.');
            if (! empty($line['unit_mapping'])) {
                $conversion = app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor, (int) $mapped['product_id'], (int) $mapped['unit_id'], $line['quantity']));
                abort_unless($conversion->baseUnitId === $line['unit_mapping']['base_unit_id'] && bccomp($conversion->baseQuantity, $line['unit_mapping']['base_quantity'], 8) === 0, 409, 'تحويل وحدة الصنف يختلف عن العطاء الفائز.');
            }
            $price = $line['selling_price'];
            $discount = '0';
            if ($financial['mode'] === 'inclusive') {
                $net = $financial['tax']['lines'][$i]['net'];
                $price = bcdiv($net, $line['quantity'], 8);
                if (bccomp(bcmul($price, $line['quantity'], 8), $net, 8) < 0) {
                    $price = bcadd($price, '0.00000001', 8);
                }
                $discount = bcsub(bcmul($price, $line['quantity'], 8), $net, 8);
            }
            $lines[] = ['product_id' => (int) $mapped['product_id'], 'unit_id' => (int) $mapped['unit_id'], 'quantity' => $line['quantity'], 'description' => $line['description'], 'requested_price' => $price, 'discount_mode' => 'fixed', 'discount_value' => $discount, 'override_reason' => $source['number'].' / '.$d['reference']];
        }
        $q = app(QuotationActions::class)->save($actor, ['customer_id' => $source['customer_id'], 'quotation_date' => $d['date'], 'valid_until' => $d['valid_until'], 'project_name' => $source['title'], 'rfq_reference' => $source['number'], 'discount_mode' => 'fixed', 'discount_value' => $financial['mode'] === 'inclusive' ? '0' : $financial['discount'], 'payment_terms' => $financial['terms'], 'notes' => 'Winning bid '.$source['financial_id'].' / '.$source['financial_sha256'].' / source tax mode '.$financial['mode'].'; M04 draft is exclusive and requires its normal commercial review.', 'exception_reason' => $d['reference'], 'lines' => $lines]);
        DB::table('sales_tender_sources')->insert(['quotation_id' => $q->id, 'tender_id' => $tender, 'result_id' => $result, 'submission_id' => $source['submission_id'], 'branch_id' => $source['branch_id'], 'customer_id' => $source['customer_id'], 'source_hash' => $source['financial_sha256'], 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR), 'created_by' => $actor]);

        return $q->id;
    }

    public function authorize(int $actor, int $quotation, ?int $customer = null, ?int $branch = null): void
    {
        $source = DB::table('sales_tender_sources')->where('quotation_id', $quotation)->first();
        if (! $source) {
            return;
        }
        abort_unless(app(OrganizationApi::class)->projectBranchMember($actor, $source->branch_id), 403);
        abort_if($customer !== null && $source->customer_id !== $customer, 409, 'عميل العطاء المصدر ثابت.');
        abort_if($branch !== null && $source->branch_id !== $branch, 409, 'فرع العطاء المصدر ثابت.');
    }
}

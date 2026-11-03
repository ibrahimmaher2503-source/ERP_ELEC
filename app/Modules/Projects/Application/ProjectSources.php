<?php

namespace App\Modules\Projects\Application;

use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Projects\Domain\Project;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\Sales\Application\Contracts\QuotationsApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ProjectSources
{
    public function source(int $actor, string $type, int $id): array
    {
        return match ($type) {
            'quotation' => app(QuotationsApi::class)->getQuotation($actor, $id),'sales_order' => app(SalesOrdersApi::class)->getOrder($actor, $id),'requisition','rfq','supplier_offer','purchase_order','purchase_receipt' => app(PurchasingApi::class)->projectDocument($actor, $type, $id), default => abort(422)
        };
    }

    public function link(int $actor, int $project, string $type, int $id): void
    {
        DB::transaction(function () use ($actor, $project, $type, $id) {
            DB::select('select pg_advisory_xact_lock(10401)');
            DB::select('select pg_advisory_xact_lock(70701)');
            $p = Project::lockForUpdate()->findOrFail($project);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_update');
            if (in_array($p->status, ['closed', 'cancelled'])) {
                $this->fail('المشروع مقفل.');
            }$source = $this->source($actor, $type, $id);
            if (in_array($type, ['quotation', 'sales_order'], true) && (int) ($source['customer']['id'] ?? 0) !== $p->customer_id) {
                $this->fail('عميل المستند لا يطابق عميل المشروع.');
            }if (isset($source['branchId']) && (int) $source['branchId'] !== $p->branch_id) {
                $this->fail('فرع المستند لا يطابق المشروع.');
            }
            if ($type === 'purchase_receipt') {
                // The PO is the canonical procurement family, shared by every receipt/invoice.
                $this->link($actor, $project, 'purchase_order', $source['orderId']);
            }
            $existing = DB::table('project_source_links')->where('source_type', $type)->where('source_id', $id)->first();
            if ($existing) {
                if ($existing->project_id !== $project) {
                    $this->fail('المستند مرتبط بمشروع آخر.');
                }

                return;
            }DB::table('project_source_links')->insert(['project_id' => $project, 'source_type' => $type, 'source_id' => $id, 'created_by' => $actor, 'created_at' => now()]);
            app(ProjectActions::class)->activity($actor, $p, 'source.linked', null, ['source_type' => $type, 'source_id' => $id]);
        });
    }

    public function requirement(int $actor, int $project, array $input): void
    {
        $d = Validator::make($input, ['product_id' => 'required|integer|min:1', 'quantity' => ['required', 'regex:/^[1-9][0-9]{0,15}(\.[0-9]{1,8})?$|^0\.[0-9]{1,8}$/'], 'notes' => 'nullable|string|max:2000'])->validate();
        if (bccomp($d['quantity'], '0', 8) <= 0) {
            $this->fail('الكمية يجب أن تكون موجبة.');
        }
        DB::transaction(function () use ($actor, $project, $d) {
            DB::select('select pg_advisory_xact_lock(10401)');
            DB::select('select pg_advisory_xact_lock(70701)');
            $p = Project::lockForUpdate()->findOrFail($project);
            app(ProjectAccess::class)->authorize($actor, $p, 'project_manage');
            if (! in_array($p->status, ['draft', 'planned', 'active', 'in_progress'])) {
                $this->fail('حالة المشروع لا تسمح بتخطيط المواد.');
            }$product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $d['product_id']))->product;
            if (! $product['active']) {
                $this->fail('الصنف غير نشط.');
            }if (DB::table('project_requirements')->where('project_id', $project)->where('product_id', $d['product_id'])->exists()) {
                $this->fail('الصنف موجود بالفعل ضمن المتطلبات.');
            }$id = DB::table('project_requirements')->insertGetId($d + ['project_id' => $project, 'unit_id' => $product['base_unit_id'], 'sku' => $product['sku'], 'product_name' => $product['name']]);
            app(ProjectActions::class)->activity($actor, $p, 'material.planned', null, ['requirement_id' => $id, 'quantity' => $d['quantity']]);
        });
    }

    private function fail(string $m): never
    {
        throw ValidationException::withMessages(['form' => $m]);
    }
}

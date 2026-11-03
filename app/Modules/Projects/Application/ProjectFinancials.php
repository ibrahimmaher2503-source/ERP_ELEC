<?php

namespace App\Modules\Projects\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Projects\Domain\Project;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Support\Facades\DB;

final class ProjectFinancials
{
    public function summary(int $actor, int $project): array
    {
        $p = Project::findOrFail($project);
        app(ProjectAccess::class)->authorize($actor, $p, 'financial_view');
        $links = DB::table('project_source_links')->where('project_id', $project)->get()->groupBy('source_type')->map(fn ($rows) => $rows->pluck('source_id')->all())->all();
        $purchase = app(PurchasingApi::class)->projectSummary($actor, $p->branch_id, $links);
        $stock = app(InventoryApi::class)->projectSummary($actor, $project, $p->branch_id, $links['sales_order'] ?? []);
        $subledger = app(ReceivablesPayablesApi::class)->projectSummary($actor, $project, $p->branch_id, $purchase['order_ids'], $links['sales_order'] ?? []);
        $gl = app(AccountingApi::class)->projectSummary($actor, $project, $p->branch_id);
        $maintenance = app(FieldMaintenanceApi::class)->projectWorkOrders($actor, $project, $p->branch_id);
        $workshop = app(WorkshopApi::class)->projectJobs($actor, $project, $p->branch_id);
        $budget = DB::table('project_baselines')->where('project_id', $project)->orderByDesc('revision')->value('total');
        $sales = [];
        $salesOpen = false;
        foreach ($links['sales_order'] ?? [] as $order) {
            $source = app(SalesOrdersApi::class)->getOrder($actor, (int) $order);
            abort_unless((int) $source['branchId'] === (int) $p->branch_id && (int) $source['customer']['id'] === (int) $p->customer_id, 422);
            foreach ($source['fulfillment'] as $line) {
                $salesOpen = $salesOpen || $line['outstanding'] === null || bccomp($line['outstanding'], '0', 8) > 0 || bccomp($line['backorder'] ?? '0', '0', 8) > 0;
            }
            $sales[] = ['id' => (int) $order, 'number' => $source['number'], 'status' => $source['lifecycle'], 'delivery' => $source['delivery']];
        }
        $projectMaterialGl = '0.00';
        foreach ($stock['movements'] as $movement) {
            foreach ($gl['rows'] as $line) {
                if ($line['source_key'] === 'inventory-movement:'.$movement['id'] && $line['kind'] === 'expense') {
                    $projectMaterialGl = bcadd($projectMaterialGl, bcsub($line['debit'], $line['credit'], 2), 2);
                }
            }
        }
        $stockDifference = bcsub(bcadd($stock['fifo_cost'], $stock['sales_fifo_cost'], 2), $projectMaterialGl, 2);
        $remaining = $budget === null ? null : bcsub($budget, $gl['recognized_cost'], 2);

        return ['currency' => 'EGP', 'approved_budget' => $budget === null ? null : bcadd($budget, '0', 2),
            'remaining_budget' => $remaining, 'over_budget' => $remaining !== null && bccomp($remaining, '0', 2) < 0,
            'forecast_remaining' => $remaining === null ? null : bcsub($remaining, $purchase['open_commitments'], 2),
            'estimated_profitability' => null, 'estimate_unavailable_reason' => 'يلزم تقدير معتمد للإيرادات وتكلفة الإكمال؛ الهامش المعترف به أدناه من GL فقط.',
            'purchasing' => $purchase, 'inventory' => $stock, 'subledger' => $subledger, 'accounting' => $gl, 'sales' => $sales,
            'fifo_gl_difference' => $stockDifference,
            'recognized_margin' => $subledger['unallocated_invoices'] === 0 && bccomp($stockDifference, '0', 2) === 0 ? $gl['margin'] : null,
            'closure_blockers' => ['إصلاحات ورش مرتبطة غير مسلمة' => $workshop['open'] > 0, 'أوامر صيانة مرتبطة غير مغلقة' => $maintenance['open'] > 0, 'حجوزات المواد' => bccomp($stock['outstanding_quantity'], '0', 8) > 0,
                'طلبات مواد مؤجلة غير محسومة' => bccomp($stock['unreserved_quantity'], '0', 8) > 0,
                'التزامات شراء مفتوحة' => bccomp($purchase['open_commitments'], '0', 2) !== 0,
                'مستندات مشتريات غير محسومة' => $purchase['unapproved_documents'] > 0,
                'استلامات غير مطابقة بفواتير' => bccomp($purchase['received_value'], $subledger['grni_cleared'], 2) !== 0,
                'فواتير أو إشعارات غير مرحلة' => $subledger['pending_invoices'] > 0,
                'قيود مشروع غير مرحلة' => $gl['pending_journals'] > 0,
                'مصادر مالية تاريخية غير مخصصة' => $subledger['unallocated_invoices'] > 0,
                'حجوزات بيع مرتبطة' => $salesOpen,
                'اختلاف FIFO وGL' => bccomp($stockDifference, '0', 2) !== 0]];
    }
}

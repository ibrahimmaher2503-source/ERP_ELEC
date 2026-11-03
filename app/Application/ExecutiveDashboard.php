<?php

namespace App\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\FieldMaintenance\Application\Contracts\FieldMaintenanceApi;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\WorkshopRepairs\Application\Contracts\WorkshopApi;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ExecutiveDashboard
{
    public function read(int $actor, ?int $branch, string $from, string $to): array
    {
        Validator::make(compact('from', 'to'), ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        try {
            $scopes = app(OrganizationApi::class)->projectScopedBranches($actor);
        } catch (ContractException $e) {
            abort($e->getCode());
        }
        abort_unless($branch === null || in_array($branch, $scopes, true), 403);
        $sections = [];
        $unavailable = ['status' => 'unavailable', 'reason' => 'البيانات تتطلب صلاحية الوحدة ونطاق فرع مسموحًا.'];
        foreach (['sales' => [SalesOrdersApi::class, 'sales-orders.index'], 'maintenance' => [FieldMaintenanceApi::class, 'maintenance.dashboard'],
            'workshops' => [WorkshopApi::class, 'workshops.dashboard'], 'accounting' => [AccountingApi::class, 'accounting.income-statement'],
            'receivables' => [ReceivablesPayablesApi::class, null], 'treasury' => [TreasuryApi::class, 'treasury.accounts']] as $key => [$api, $route]) {
            try {
                $sections[$key] = ['status' => 'available', 'route' => $route] + app($api)->executiveSummary($actor, $branch, $from, $to);
            } catch (HttpExceptionInterface|ContractException $e) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : $e->getCode();
                if (! in_array($status, [403, 503])) {
                    throw $e;
                }
                $sections[$key] = $unavailable;
            }
        }
        $projects = app(ProjectsApi::class)->executiveSummary($actor, $branch, $from, $to);
        if ($sections['receivables']['status'] === 'available') {
            $sections['receivables']['route'] = array_key_exists('customer_invoiced', $sections['receivables']['metrics']) ? 'financial.ar' : 'financial.ap';
        }
        foreach (['projects' => 'projects.index', 'tenders' => 'tenders.dashboard'] as $key => $route) {
            $sections[$key] = $projects[$key] === null ? $unavailable : ['status' => 'available', 'route' => $route] + $projects[$key];
        }

        return ['from' => $from, 'to' => $to, 'branch_id' => $branch, 'sections' => $sections];
    }
}

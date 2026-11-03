<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ServiceContractReport
{
    public function show(int $actor, int $contract, ?string $to = null): array
    {
        $to ??= now('Africa/Cairo')->toDateString();
        Validator::make(compact('to'), ['to' => 'required|date_format:Y-m-d'])->validate();
        $c = DB::table('service_contracts')->find($contract);
        abort_unless($c, 404);
        app(ServiceContractAccess::class)->authorize($actor, (int) $c->branch_id, 'reports_view');
        $r = DB::table('service_contract_revisions')->find($c->active_revision_id ?? $c->current_revision_id);
        $terms = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
        $coverage = [];
        foreach ($terms['coverage'] as $equipment) {
            foreach (['preventive', 'corrective'] as $kind) {
                $coverage[] = ['equipment_ref' => $equipment['equipment_ref'], 'site_id' => $equipment['site_id'], 'kind' => $kind]
                    + app(ServiceContractEntitlements::class)->remaining($contract, $terms, $equipment, $kind);
            }
        }
        $financial = ['status' => 'unavailable', 'reason' => 'صلاحية تقرير الذمم مطلوبة.'];
        try {
            $summary = app(ReceivablesPayablesApi::class)->contractFinancialSummary($actor, $contract, (int) $c->branch_id, $to);
            $earned = app(ServiceContractBilling::class)->earned($contract, $to);
            $financial = ['status' => 'available', 'earned' => $earned, 'deferred_remaining' => bcsub($summary['deferred_billed'], $earned, 2), 'to' => $to] + $summary;
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [403, 503])) {
                throw $e;
            }
        }

        return ['contract_id' => $contract, 'title' => $terms['title'], 'status' => $c->status, 'revision' => (int) $r->revision,
            'coverage_basis' => 'current_reservations_and_consumption', 'coverage' => $coverage, 'financial' => $financial];
    }
}

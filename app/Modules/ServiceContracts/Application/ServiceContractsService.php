<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ServiceContracts\Application\Contracts\ServiceContractsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ServiceContractsService implements ServiceContractsApi
{
    public function workshopOptions(int $actor, int $customer, int $branch): array
    {
        app(ServiceContractAccess::class)->authorize($actor, $branch, 'coverage_use');

        return DB::table('service_contracts as c')->join('service_contract_revisions as r', 'r.id', '=', 'c.active_revision_id')
            ->where('c.customer_id', $customer)->where('c.branch_id', $branch)->where('c.status', 'active')->where('r.status', 'active')
            ->orderByDesc('c.id')->get(['c.id', 'r.data'])->map(function ($row) {
                $terms = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);

                return ['id' => (int) $row->id, 'title' => $terms['title'], 'starts_on' => $terms['starts_on'], 'ends_on' => $terms['ends_on']];
            })->all();
    }

    public function billingSource(int $actor, int $source): array
    {
        return app(ServiceContractBilling::class)->source($actor, $source);
    }

    public function validateBillingEvidence(int $actor, int $source, int $branch, int $customer): void
    {
        $authorized = false;
        foreach (['M12.customer_invoice_manage', 'M12.customer_invoice_post'] as $permission) {
            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $permission, $branch));
                $authorized = true;
                break;
            } catch (ContractException) {
            }
        }
        abort_unless($authorized, 403);
        app(ServiceContractActions::class)->lock();
        $s = DB::table('service_contract_billing_sources as s')->join('service_contracts as c', 'c.id', '=', 's.contract_id')->where('s.id', $source)->where('c.branch_id', $branch)->where('c.customer_id', $customer)->first(['s.*']);
        abort_unless($s && in_array($s->status, ['approved', 'invoiced']) && $s->approved_by !== null && $s->tax_snapshot !== null, 409, 'مصدر العقد غير معتمد للفوترة.');
    }

    public function recognizedValue(int $actor, array $branches, string $to): string
    {
        Validator::make(compact('to'), ['to' => 'required|date_format:Y-m-d'])->validate();
        foreach ($branches as $branch) {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M11.report_view', (int) $branch));
        }

        $positive = (string) DB::table('service_contract_recognitions as r')->join('service_contracts as c', 'c.id', '=', 'r.contract_id')->whereIn('c.branch_id', $branches)->where('r.status', 'posted')->where('r.recognition_date', '<=', $to)->sum('r.amount');
        $negative = (string) DB::table('service_contract_recognition_reversals as r')->join('service_contracts as c', 'c.id', '=', 'r.contract_id')->whereIn('c.branch_id', $branches)->where('r.status', 'posted')->where('r.reversal_date', '<=', $to)->sum('r.amount');

        return bcsub($positive, $negative, 2);
    }

    public function creditSource(int $actor, int $correction): array
    {
        app(ServiceContractActions::class)->lock();
        $r = DB::table('service_contract_billing_corrections')->find($correction);
        abort_unless($r, 404);
        $s = app(ServiceContractBilling::class)->source($actor, (int) $r->source_id);
        $allocation = DB::table('service_contract_billing_allocations')->where('source_id', $r->source_id)->first();
        abort_unless($allocation, 409);

        return ['correction_id' => $correction, 'source_id' => (int) $r->source_id, 'contract_id' => $s['contract_id'], 'invoice_id' => (int) $allocation->invoice_id,
            'creation_key' => $r->creation_key, 'kind' => 'customer', 'amount' => $r->amount, 'note_date' => $r->note_date, 'reason' => $r->reason];
    }

    public function validateBillingCredit(int $actor, int $correction, int $source, int $branch, string $net, string $amount, string $date): void
    {
        $allowed = false;
        foreach (['M12.credit_note_manage', 'M12.credit_note_approve'] as $permission) {
            try {
                app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $permission, $branch));
                $allowed = true;
                break;
            } catch (ContractException) {
            }
        }
        abort_unless($allowed, 403);
        app(ServiceContractActions::class)->lock();
        $r = DB::table('service_contract_billing_corrections')->where('source_id', $source)->find($correction);
        $s = DB::table('service_contract_billing_sources')->find($source);
        $c = $s ? DB::table('service_contracts')->find($s->contract_id) : null;
        abort_unless($r && $c && (int) $c->branch_id === $branch && bccomp($r->amount, $amount, 2) === 0 && $r->note_date === $date, 409);
        if ($s->revenue_purpose === 'service_contract_deferred') {
            $summary = app(ReceivablesPayablesApi::class)->contractFinancialSummary($actor, (int) $c->id, $branch, $date);
            $earned = app(ServiceContractBilling::class)->earned((int) $c->id, $date);
            abort_unless(bccomp($net, bcsub($summary['deferred_billed'], $earned, 2), 2) <= 0, 422, 'اعكس الاعتراف المكتسب المرتبط من إجراء العقد قبل تصحيح هذه القيمة.');
        }
    }

    public function createTenderDraft(int $actor, int $tender, int $result, array $mapping): int
    {
        return DB::transaction(function () use ($actor, $tender, $result, $mapping) {
            app(ServiceContractActions::class)->lock();
            $source = app(ProjectsApi::class)->contractTenderAward($actor, $tender, $result, $mapping);
            abort_unless((int) ($mapping['branch_id'] ?? 0) === (int) $source['branch_id'] && (int) ($mapping['customer_id'] ?? 0) === (int) $source['customer_id'], 422, 'هوية العقد يجب أن تطابق الترسية.');
            $id = app(ServiceContractActions::class)->save($actor, null, 0, Uuid::uuid5(Uuid::NAMESPACE_URL, 'tender-contract:'.$tender)->toString(), $mapping);
            $old = DB::table('service_contract_tender_sources')->where('contract_id', $id)->first();
            if (! $old) {
                DB::table('service_contract_tender_sources')->insert(['contract_id' => $id, 'tender_id' => $tender, 'result_id' => $result, 'plan_id' => $source['plan_id'],
                    'snapshot' => json_encode(array_diff_key($source, ['financial' => true]), JSON_THROW_ON_ERROR)]);
            } else {
                abort_unless((int) $old->tender_id === $tender && (int) $old->result_id === $result && (int) $old->plan_id === (int) $source['plan_id'], 409);
            }

            return $id;
        }, 3);
    }

    public function reserveWork(int $actor, int $contract, string $owner, int $source): int
    {
        return app(ServiceContractEntitlements::class)->reserve($actor, $contract, $owner, $source);
    }

    public function consumeWork(int $actor, string $owner, int $source): int
    {
        return app(ServiceContractEntitlements::class)->consume($actor, $owner, $source);
    }

    public function recordWorkUsage(int $actor, string $owner, int $source): void
    {
        app(ServiceContractEntitlements::class)->sync($actor, $owner, $source);
    }

    public function releaseWork(int $actor, string $owner, int $source): void
    {
        app(ServiceContractEntitlements::class)->release($actor, $owner, $source);
    }

    public function coverage(int $actor, int $contract, int $customer, int $branch, string $equipmentRef, ?int $site, string $date, string $kind): array
    {
        app(ServiceContractAccess::class)->authorize($actor, $branch, 'coverage_use');
        Validator::make(compact('date', 'kind', 'equipmentRef'), ['date' => 'required|date_format:Y-m-d', 'kind' => 'required|in:preventive,corrective', 'equipmentRef' => 'required|string|max:160'])->validate();
        $c = DB::table('service_contracts')->where('customer_id', $customer)->where('branch_id', $branch)->find($contract);
        $r = $c?->active_revision_id ? DB::table('service_contract_revisions')->find($c->active_revision_id) : null;
        $d = $r ? json_decode($r->data, true, 512, JSON_THROW_ON_ERROR) : null;
        $equipmentRef = mb_strtoupper(trim($equipmentRef));
        $equipment = $d ? collect($d['coverage'])->first(fn ($item) => $item['equipment_ref'] === $equipmentRef && $item['site_id'] === $site) : null;
        if (! $c || $c->status !== 'active' || ! $r || $r->status !== 'active' || $date < $d['starts_on'] || $date > $d['ends_on'] || ! $equipment || $equipment[$kind.'_visits'] < 1) {
            throw ValidationException::withMessages(['coverage' => 'العقد أو المعدة أو التاريخ أو استحقاق الزيارة خارج التغطية.']);
        }

        $remaining = app(ServiceContractEntitlements::class)->remaining($contract, $d, $equipment, $kind);
        if ($remaining['remaining_visits'] < 1) {
            throw ValidationException::withMessages(['coverage' => 'نفدت زيارات التغطية.']);
        }

        return $remaining + ['contract_id' => $contract, 'revision_id' => (int) $r->id, 'customer_id' => $customer, 'branch_id' => $branch,
            'equipment_ref' => $equipmentRef, 'site_id' => $site, 'kind' => $kind,
            'excluded_parts' => $d['excluded_parts'],
            'sla_response_minutes' => $d['sla_response_minutes'], 'sla_resolution_minutes' => $d['sla_resolution_minutes']];
    }
}

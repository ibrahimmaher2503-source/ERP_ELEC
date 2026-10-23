<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\QuotationDelegation;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationApproval;
use App\Modules\Sales\Domain\QuotationChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class QuotationApprovals
{
    public function snapshot(Quotation $r): array
    {
        return ['quotation' => $r->only(['customer_id', 'customer_snapshot', 'project_name', 'rfq_reference', 'quotation_date', 'valid_until', 'currency', 'revision', 'discount_mode', 'discount_value', 'payment_terms', 'delivery_terms', 'payment_term_id', 'delivery_term_id', 'notes', 'exception_reason']), 'lines' => $r->lines->map(fn ($l) => $l->only(['position', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'suggested_price', 'requested_price', 'price_source', 'discount_mode', 'discount_value', 'override_reason']))->all()];
    }

    public function hash(Quotation $r): string
    {
        return hash('sha256', json_encode($this->snapshot($r), JSON_THROW_ON_ERROR));
    }

    private function access(int $actor, string $action): void
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, $action);
    }

    public function hasCurrentApproval(Quotation $r, array $evaluation): bool
    {
        $a = $r->approvals()->where('status', 'approved')->first();

        return $a !== null && $a->target_version + 1 === $r->version && $a->policy_version === $evaluation['policy_version'] && $a->content_hash === $this->hash($r) && $a->exceptions == $evaluation['exceptions'] && ! $evaluation['blockers'];
    }

    public function submit(int $actor, int $id, int $version): QuotationApproval
    {
        $this->access($actor, 'submit');

        return DB::transaction(function () use ($actor, $id, $version) {
            DB::select('SELECT pg_advisory_xact_lock(40411)');
            $r = Quotation::with('lines')->lockForUpdate()->findOrFail($id);
            app(QuotationActions::class)->version($r, $version);
            if (! $r->editable()) {
                throw ValidationException::withMessages(['approval' => 'أرسل المسودة فقط.']);
            }
            $evaluation = app(QuotationCommercialPolicy::class)->evaluate($r, $actor);
            if ($evaluation['blockers']) {
                throw ValidationException::withMessages(['approval' => implode(' ', $evaluation['blockers'])]);
            }
            if (! $evaluation['exceptions']) {
                throw ValidationException::withMessages(['approval' => 'العرض ملتزم ولا يحتاج موافقة استثناءات. يظل الإصدار مرهونًا بالضرائب ومتطلبات المستند الرسمي.']);
            }
            foreach ($evaluation['exceptions'] as $e) {
                if (trim($e['reason'] ?? '') === '') {
                    throw ValidationException::withMessages(['approval' => 'يجب تسجيل سبب لكل استثناء قبل التقديم.']);
                }
            }
            $before = $r->only(['status', 'version']);
            $r->update(['status' => 'pending_approval', 'version' => $r->version + 1, 'updated_by' => $actor]);
            $a = $r->approvals()->create(['requested_by' => $actor, 'target_version' => $r->version, 'policy_version' => $evaluation['policy_version'], 'content_hash' => $this->hash($r), 'exceptions' => $evaluation['exceptions'], 'quotation_snapshot' => $this->snapshot($r), 'status' => 'pending']);
            event(new QuotationChanged($actor, $id, 'approval_submitted', $before, ['request_id' => $a->id, 'version' => $r->version, 'status' => $r->status, 'exception_count' => count($evaluation['exceptions'])]));

            return $a;
        });
    }

    public function withdraw(int $actor, int $id, int $version): void
    {
        $this->access($actor, 'withdraw');
        DB::transaction(function () use ($actor, $id, $version) {
            $r = Quotation::lockForUpdate()->findOrFail($id);
            app(QuotationActions::class)->version($r, $version);
            $a = $r->approvals()->where('status', 'pending')->lockForUpdate()->first();
            if (! $a || $r->status !== 'pending_approval') {
                throw ValidationException::withMessages(['approval' => 'لا يوجد طلب موافقة معلق.']);
            }$a->update(['status' => 'withdrawn', 'decided_by' => $actor, 'decided_at' => now()]);
            $r->update(['status' => 'draft', 'version' => $r->version + 1, 'updated_by' => $actor]);
            event(new QuotationChanged($actor, $id, 'approval_withdrawn', null, ['request_id' => $a->id, 'version' => $r->version, 'status' => 'draft']));
        });
    }

    public function decide(int $actor, int $id, int $requestId, int $version, bool $approve, string $reason): void
    {
        $this->access($actor, 'approve');
        Validator::make(['reason' => $reason], ['reason' => [$approve ? 'nullable' : 'required', 'string', 'max:2000']])->validate();
        DB::transaction(function () use ($actor, $id, $requestId, $version, $approve, $reason) {
            DB::select('SELECT pg_advisory_xact_lock(40411)');
            $r = Quotation::with('lines')->lockForUpdate()->findOrFail($id);
            app(QuotationActions::class)->version($r, $version);
            $a = $r->approvals()->lockForUpdate()->findOrFail($requestId);
            if ($r->status !== 'pending_approval' || $a->status !== 'pending' || $a->target_version !== $r->version || $a->content_hash !== $this->hash($r)) {
                throw ValidationException::withMessages(['approval' => 'الطلب مسحوب أو تغيرت المسودة. لا يمكن اعتماد طلب قديم.']);
            }
            app(QuotationDelegation::class)->authorizeApproval($actor, $a->requested_by);
            $eval = app(QuotationCommercialPolicy::class)->evaluate($r, $actor);
            if ($eval['policy_version'] !== $a->policy_version || $eval['blockers'] || $eval['exceptions'] != $a->exceptions) {
                throw ValidationException::withMessages(['approval' => 'تغيرت السياسة أو المراجع. اسحب الطلب ثم أعد تقديمه.']);
            }
            $a->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor, 'decision_reason' => $reason ?: null, 'decided_at' => now()]);
            $r->update(['status' => 'draft', 'version' => $r->version + 1, 'updated_by' => $actor]);
            event(new QuotationChanged($actor, $id, $approve ? 'exceptions_approved' : 'exceptions_rejected', null, ['request_id' => $a->id, 'version' => $r->version, 'status' => 'draft', 'self_approved' => $actor === $a->requested_by]));
        });
    }
}

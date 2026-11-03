<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Domain\QuotationTerm;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesOrderCommercialReview
{
    /** Departures from an approved source need an explicit additional decision. */
    public function evaluate(int $actor, SalesOrder $order): array
    {
        $blockers = [];
        $exceptions = [];
        foreach ($order->lines as $line) {
            $source = app(QuotationPricing::class)->suggested($actor, $line->product_id, $line->unit_id, $order->order_date->toDateString());
            if ($source === null || $line->price_source === null || $source != $line->price_source || $line->suggested_price === null) {
                $blockers[] = 'تغير مصدر السعر المعتمد للبند '.$line->position.'؛ اسحب الطلب وأعد حفظ المسودة بالسعر الحالي.';

                continue;
            }
            if ($line->requested_price !== null && bccomp($line->requested_price, $line->suggested_price, 8) !== 0) {
                $exceptions[] = ['type' => 'price', 'line_id' => $line->id, 'approved' => $line->suggested_price, 'requested' => $line->requested_price];
            }
            if (bccomp($line->discount_value, '0', 8) !== 0) {
                $exceptions[] = ['type' => 'line_discount', 'line_id' => $line->id, 'mode' => $line->discount_mode, 'requested' => $line->discount_value];
            }
        }
        if (bccomp($order->discount_value, '0', 8) !== 0) {
            $exceptions[] = ['type' => 'order_discount', 'mode' => $order->discount_mode, 'requested' => $order->discount_value];
        }
        foreach (['payment', 'delivery'] as $kind) {
            $text = $order->{$kind.'_terms'} ?? '';
            $template = QuotationTerm::where('kind', $kind)->where('active', true)->where('body', $text)
                ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first(['id']);
            if (! $template) {
                $exceptions[] = ['type' => $kind.'_terms', 'requested' => $text];
            }
        }

        return ['blockers' => array_values(array_unique($blockers)), 'exceptions' => $exceptions];
    }

    private function canonical(array $value): string
    {
        $normalize = function (array $row) use (&$normalize): array {
            if (! array_is_list($row)) {
                ksort($row);
            }
            foreach ($row as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $row;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function decide(int $actor, int $id, int $version, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => 'required|string|min:3|max:2000|not_regex:/\x00/'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $reason) {
            DB::select('SELECT pg_advisory_xact_lock(40421)');
            $order = SalesOrder::with('lines')->lockForUpdate()->findOrFail($id);
            app(SalesOrderActions::class)->version($order, $version);
            $review = $order->reviews()->where('status', 'pending')->lockForUpdate()->first();
            abort_unless($order->source_type === 'direct' && $order->status === 'pending_approval' && $review && $review->target_version === $version, 409);
            $hash = hash('sha256', json_encode(app(SalesOrderActions::class)->snapshot($order), JSON_THROW_ON_ERROR));
            abort_unless(hash_equals($review->content_hash, $hash), 409);
            app(SalesOrderApprovalPolicy::class)->authorizeApprover($actor, $review->requested_by, $order->branch_id, true);
            $policy = DB::table('sales_order_policies')->where('id', 1)->first();
            $evaluation = $this->evaluate($actor, $order);
            if ($evaluation['blockers'] || ! $evaluation['exceptions']) {
                throw ValidationException::withMessages(['readiness' => 'صحح مصدر السعر أو قدم أمرًا يحتوي استثناءً تجاريًا حاليًا.']);
            }
            $old = DB::table('sales_order_exception_decisions')->where('review_id', $review->id)->first();
            if ($old) {
                abort_unless($old->policy_version === $policy->version && $old->content_hash === $hash
                    && $old->reason === $reason && $this->canonical(json_decode($old->exceptions, true, 512, JSON_THROW_ON_ERROR)) === $this->canonical($evaluation['exceptions']), 409);

                return;
            }
            DB::table('sales_order_exception_decisions')->insert(['review_id' => $review->id, 'policy_version' => $policy->version,
                'content_hash' => $hash, 'exceptions' => json_encode($evaluation['exceptions'], JSON_THROW_ON_ERROR),
                'decided_by' => $actor, 'reason' => $reason, 'decided_at' => now()]);
            event(new SalesOrderChanged($actor, $id, 'commercial_exceptions_approved', null, ['review_id' => $review->id, 'policy_version' => $policy->version, 'reason' => $reason]));
        });
    }

    public function requireReady(int $actor, SalesOrder $order, object $review): void
    {
        $evaluation = $this->evaluate($actor, $order);
        if ($evaluation['blockers']) {
            throw ValidationException::withMessages(['readiness' => implode(' ', $evaluation['blockers'])]);
        }
        if (! $evaluation['exceptions']) {
            return;
        }
        $policy = DB::table('sales_order_policies')->where('id', 1)->first();
        $decision = DB::table('sales_order_exception_decisions')->where('review_id', $review->id)->first();
        if (! $policy || ! $decision || $decision->policy_version !== $policy->version || $decision->content_hash !== $review->content_hash
            || $this->canonical(json_decode($decision->exceptions, true, 512, JSON_THROW_ON_ERROR)) !== $this->canonical($evaluation['exceptions'])) {
            throw ValidationException::withMessages(['readiness' => 'يلزم قرار إضافي من دور مسؤول الاستثناءات المحدد، مرتبط بطلب المراجعة والمحتوى الحالي.']);
        }
        app(SalesOrderApprovalPolicy::class)->authorizeApprover($decision->decided_by, $review->requested_by, $order->branch_id, true);
    }
}

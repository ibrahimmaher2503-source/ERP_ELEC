<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationChanged;
use App\Modules\Sales\Domain\QuotationPolicy;
use App\Modules\Sales\Domain\QuotationTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class QuotationCommercialPolicy
{
    public const DISCOUNTS = ['line_percent', 'line_fixed', 'quotation_percent', 'quotation_fixed'];

    public function save(int $actor, array $settings, ?int $version): void
    {
        app(QuotationAccess::class)->authorize($actor, 'policy_manage');
        $rules = ['validity_default' => ['required', 'integer', 'min:1', 'max:3650'], 'validity_min' => ['required', 'integer', 'min:1', 'lte:validity_default'], 'validity_max' => ['required', 'integer', 'gte:validity_default', 'max:3650']];
        foreach (self::DISCOUNTS as $key) {
            $rules[$key] = ['required', 'string'];
        }
        $data = Validator::make($settings, $rules)->validate();
        foreach (self::DISCOUNTS as $key) {
            DraftAmounts::decimal($data[$key], $key);
            if (str_ends_with($key, 'percent') && bccomp($data[$key], '100', 8) > 0) {
                throw ValidationException::withMessages([$key => 'النسبة لا تتجاوز 100%.']);
            }
        }
        DB::transaction(function () use ($actor, $data, $version) {
            DB::select('SELECT pg_advisory_xact_lock(40411)');
            $r = QuotationPolicy::lockForUpdate()->find(1);
            if (($r?->version) !== $version) {
                throw ValidationException::withMessages(['policy' => 'تغيرت السياسة. أعد تحميل الصفحة.']);
            }
            $before = $r?->only(['settings', 'version']);
            $r ??= new QuotationPolicy;
            $r->id = 1;
            $r->fill(['settings' => $data, 'version' => ($r->version ?? 0) + 1, 'updated_by' => $actor]);
            $r->save();
            event(new QuotationChanged($actor, 0, 'commercial_policy_changed', $before, $r->only(['settings', 'version'])));
        });
    }

    public function term(int $actor, array $input): void
    {
        app(QuotationAccess::class)->authorize($actor, 'policy_manage');
        $data = Validator::make($input, ['code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_.-]*$/'], 'kind' => ['required', Rule::in(['payment', 'delivery'])], 'name' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:4000']])->validate();
        DB::transaction(function () use ($data, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(40411)');
            $old = QuotationTerm::where('code', $data['code'])->orderByDesc('revision')->first();
            if ($old && $old->kind !== $data['kind']) {
                throw ValidationException::withMessages(['code' => 'نوع القالب ثابت.']);
            }$revision = ($old?->revision ?? 0) + 1;
            QuotationTerm::where('code', $data['code'])->update(['active' => false]);
            $t = QuotationTerm::create($data + ['revision' => $revision, 'active' => true]);
            event(new QuotationChanged($actor, 0, 'commercial_term_version_created', null, ['term_id' => $t->id, 'code' => $t->code, 'revision' => $t->revision, 'kind' => $t->kind]));
        });
    }

    public function evaluate(Quotation $quote, ?int $actorId = null): array
    {
        $policy = QuotationPolicy::find(1);
        $blockers = [];
        $exceptions = [];
        if (! $policy) {
            $blockers[] = 'سياسة الصلاحية وحدود الخصومات لم تضبط بعد.';
        }
        $settings = $policy?->settings ?? [];
        $actorId ??= auth()->id() ?? $quote->updated_by ?? $quote->created_by ?? 0;
        foreach ($quote->lines as $i => $line) {
            $current = app(QuotationPricing::class)->suggested($actorId, $line->product_id, $line->unit_id, $quote->quotation_date->toDateString());
            if ($current === null || $line->price_source === null || $current != $line->price_source) {
                $blockers[] = 'تغير مصدر السعر أو لم يعد متاحًا للبند '.($i + 1).'. أعد حفظ المسودة بعد التحقق من السعر المعتمد.';
            }

            if ($line->suggested_price === null || $line->price_source === null) {
                $blockers[] = 'مصدر السعر المقترح المعتمد غير متاح للبند '.($i + 1).'.';
            } elseif ($line->requested_price !== null && bccomp($line->suggested_price, $line->requested_price, 8) !== 0) {
                $exceptions[] = ['type' => 'price', 'line' => $i + 1, 'requested' => $line->requested_price, 'approved' => $line->suggested_price, 'reason' => $line->override_reason];
            }
            $key = 'line_'.$line->discount_mode;
            if (isset($settings[$key]) && bccomp($line->discount_value, $settings[$key], 8) > 0) {
                $exceptions[] = ['type' => 'line_discount', 'line' => $i + 1, 'mode' => $line->discount_mode, 'requested' => $line->discount_value, 'approved' => $settings[$key], 'reason' => $quote->exception_reason];
            }
        }
        $key = 'quotation_'.$quote->discount_mode;
        if (isset($settings[$key]) && bccomp($quote->discount_value, $settings[$key], 8) > 0) {
            $exceptions[] = ['type' => 'quotation_discount', 'mode' => $quote->discount_mode, 'requested' => $quote->discount_value, 'approved' => $settings[$key], 'reason' => $quote->exception_reason];
        }
        if (! $quote->valid_until) {
            $blockers[] = 'تاريخ الصلاحية غير محدد.';
        } elseif ($policy) {
            $days = (int) $quote->quotation_date->diffInDays($quote->valid_until, false);
            if ($days < $settings['validity_min'] || $days > $settings['validity_max']) {
                $exceptions[] = ['type' => 'validity', 'requested' => (string) $days, 'approved' => $settings['validity_min'].'–'.$settings['validity_max'], 'reason' => $quote->exception_reason];
            }
        }
        foreach (['payment', 'delivery'] as $kind) {
            $id = $quote->{$kind.'_term_id'};
            $term = $id ? QuotationTerm::find($id) : null;
            if (! $term || ! $term->active || $term->body !== $quote->{$kind.'_terms'}) {
                $exceptions[] = ['type' => $kind.'_terms', 'requested' => $quote->{$kind.'_terms'} ?? '', 'approved' => 'قالب معتمد حالي', 'reason' => $quote->exception_reason];
            }
        }

        return ['policy_version' => $policy?->version, 'exceptions' => $exceptions, 'blockers' => array_values(array_unique($blockers))];
    }
}

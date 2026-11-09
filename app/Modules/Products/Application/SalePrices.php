<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ApprovedPrices;
use App\Modules\Products\Domain\Product;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalePrices implements ApprovedPrices
{
    public function find(int $actor, int $product, int $unit, string $date): ?array
    {
        if (! app(ProductAccess::class)->allows($actor, 'M02.price_view')) {
            return null;
        }
        app(ProductAccess::class)->authorize($actor, 'M02.product_view');
        $p = Product::when(DB::transactionLevel() > 0, fn ($q) => $q->sharedLock())->findOrFail($product);
        if (! $p->active) {
            return null;
        }
        $price = DB::table('product_sale_prices')->where('product_id', $product)->where('unit_id', $unit)->where('status', 'approved')
            ->where('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->sharedLock())->first();

        return $price ? ['id' => $price->id, 'amount' => $price->amount, 'currency' => 'EGP', 'starts_on' => $price->starts_on, 'ends_on' => $price->ends_on, 'unit_id' => $unit] : null;
    }

    public function save(int $actor, int $product, array $data): int
    {
        app(ProductAccess::class)->authorize($actor, 'M02.price_manage');
        $d = Validator::make($data, ['amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/D'], 'unit_id' => 'required|integer|min:1', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'reference' => 'required|string|min:3|max:500'])->validate();

        return DB::transaction(function () use ($actor, $product, $d) {
            $p = Product::findOrFail($product);
            if (! $p->active || ! DB::table('units_of_measure')->where('id', $d['unit_id'])->where('active', true)->exists()
                || ! in_array((int) $d['unit_id'], array_filter([$p->base_unit_id, $p->sales_unit_id, ...$p->conversions()->pluck('unit_id')->all()]), true)) {
                throw ValidationException::withMessages(['price' => 'اختر صنفاً ووحدة بيع نشطين مرتبطين.']);
            }
            $id = DB::table('product_sale_prices')->insertGetId(['product_id' => $product, 'unit_id' => $d['unit_id'], 'amount' => $d['amount'], 'starts_on' => $d['starts_on'], 'ends_on' => ($d['ends_on'] ?? '') ?: null, 'reference' => $d['reference'], 'created_by' => $actor, 'created_at' => now()]);
            $this->audit($actor, $id, 'price.drafted', ['product_id' => $product, 'amount' => $d['amount'], 'unit_id' => $d['unit_id'], 'starts_on' => $d['starts_on']]);

            return $id;
        });
    }

    public function decide(int $actor, int $id, string $action): void
    {
        abort_unless(in_array($action, ['approve', 'withdraw'], true), 422);
        app(ProductAccess::class)->authorize($actor, $action === 'approve' ? 'M02.price_approve' : 'M02.price_manage');
        DB::transaction(function () use ($actor, $id, $action) {
            DB::select('SELECT pg_advisory_xact_lock(40231)');
            $r = DB::table('product_sale_prices')->lockForUpdate()->find($id);
            abort_unless($r, 404);
            if ($action === 'approve') {
                abort_if($r->created_by === $actor, 403, 'يلزم اعتماد السعر من شخص مستقل.');
                abort_unless($r->status === 'draft', 409);
                if (! Product::where('active', true)->whereKey($r->product_id)->exists()) {
                    throw ValidationException::withMessages(['price' => 'الصنف غير نشط.']);
                }
                $overlap = DB::table('product_sale_prices')->where('product_id', $r->product_id)->where('unit_id', $r->unit_id)->where('status', 'approved')
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $r->starts_on))
                    ->when($r->ends_on, fn ($q) => $q->where('starts_on', '<=', $r->ends_on))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['price' => 'تتداخل فترة السعر مع سعر معتمد لنفس الوحدة.']);
                }
                DB::table('product_sale_prices')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now()]);
            } else {
                abort_unless(in_array($r->status, ['draft', 'approved'], true), 409);
                DB::table('product_sale_prices')->where('id', $id)->update(['status' => 'withdrawn']);
            }
            $this->audit($actor, $id, 'price.'.$action, ['product_id' => $r->product_id, 'amount' => $r->amount]);
        });
    }

    private function audit(int $actor, int $id, string $action, array $after): void
    {
        app(RecordAudit::class)->execute($actor, 'sale_price', $id, $action, null, $after, sourceModule: 'Products');
    }
}

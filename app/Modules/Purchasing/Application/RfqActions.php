<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SupplierIdentity;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRfq;
use App\Modules\Purchasing\Domain\RfqChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RfqActions
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['rfq' => $message]);
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(10401)');
        DB::select('SELECT pg_advisory_xact_lock(50501)');
    }

    private function supplier(int $actor, int $id): SupplierIdentity
    {
        try {
            return app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, $id));
        } catch (SupplierContractException $e) {
            if ($e->getCode() === 403) {
                abort(403, 'صلاحية عرض دليل الموردين مطلوبة.');
            }
            $this->fail('المورد المحدد غير متاح أو غير نشط.');
        }
    }

    public function save(int $actor, array $data, ?int $id, int $version): PurchaseRfq
    {
        return DB::transaction(function () use ($actor, $data, $id, $version) {
            $this->lock();
            $data['source_type'] ??= 'REQUISITION';
            $standalone = $data['source_type'] === 'STANDALONE';
            $data = Validator::make($data, [
                'source_type' => 'required|in:REQUISITION,STANDALONE', 'branch_id' => 'nullable|integer|min:1',
                'justification' => ($standalone ? 'required' : 'nullable').'|string|max:3000|not_regex:/\x00/',
                'creation_key' => 'required|uuid', 'requisition_id' => ($standalone ? 'prohibited' : 'required|integer|min:1'), 'subject' => 'required|string|max:200|not_regex:/\x00/',
                'request_date' => 'required|date_format:Y-m-d', 'deadline' => 'nullable|date_format:Y-m-d|after_or_equal:request_date', 'requirements' => 'nullable|string|max:5000|not_regex:/\x00/',
                'lines' => 'required|array|min:1|max:100', 'lines.*.requisition_line_id' => $standalone ? 'prohibited' : 'required|integer|distinct',
                'lines.*.product_id' => ($standalone ? 'required' : 'nullable').'|integer|min:1|distinct',
                'lines.*.base_unit_id' => ($standalone ? 'required' : 'nullable').'|integer|min:1',
                'lines.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'supplier_ids' => 'required|array|min:1|max:50', 'supplier_ids.*' => 'required|integer|min:1|distinct',
            ])->validate();
            $source = $standalone ? null : PurchaseRequisition::lockForUpdate()->findOrFail($data['requisition_id']);
            $branch = $standalone ? (int) ($data['branch_id'] ?? 0) : $source->branch_id;
            $access = app(PurchasingAccess::class);
            $access->authorize($actor, $id ? 'rfq_update' : 'rfq_create', $branch);
            if ($standalone) {
                $access->authorize($actor, 'rfq_standalone', $branch);
            } else {
                $access->authorize($actor, 'requisition_view', $branch);
                if ($source->status !== 'approved') {
                    $this->fail('An approved requisition is required.');
                }
            }
            $doc = $id ? PurchaseRfq::lockForUpdate()->findOrFail($id) : PurchaseRfq::where('creation_key', $data['creation_key'])->lockForUpdate()->first();
            $lines = [];
            foreach ($data['lines'] as $l) {
                if (bccomp($l['quantity'], '0', 8) <= 0) {
                    $this->fail('Quantity must be positive.');
                }
                if ($standalone) {
                    $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $l['product_id']))->product;
                    if (! $p['active'] || $p['base_unit_id'] !== (int) $l['base_unit_id']) {
                        $this->fail('Active product and its actual base unit are required.');
                    }
                    $lines[] = ['requisition_line_id' => null, 'product_id' => $p['id'], 'base_unit_id' => $p['base_unit_id'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'unit_name' => (string) $p['base_unit_id'], 'quantity' => bcadd($l['quantity'], '0', 8), 'source_quantity' => bcadd($l['quantity'], '0', 8)];
                } else {
                    $original = $source->lines()->find($l['requisition_line_id']);
                    if (! $original) {
                        $this->fail('Invalid requisition line.');
                    }
                    $allocated = DB::table('purchasing_rfq_lines as l')->join('purchasing_rfqs as r', 'r.id', '=', 'l.rfq_id')->where('l.requisition_line_id', $original->id)->when($doc, fn ($q) => $q->where('r.id', '<>', $doc->id))->sum('l.quantity');
                    if (bccomp(bcadd((string) $allocated, $l['quantity'], 8), $original->quantity, 8) > 0) {
                        $this->fail('Quantity exceeds unallocated requisition demand.');
                    }
                    $lines[] = ['requisition_line_id' => $original->id, 'product_id' => $original->product_id, 'base_unit_id' => $original->unit_id, 'sku' => $original->sku, 'product_name' => $original->product_name, 'unit_name' => '#'.$original->unit_id, 'quantity' => bcadd($l['quantity'], '0', 8), 'source_quantity' => bcadd($l['quantity'], '0', 8)];
                }
            }
            $suppliers = [];
            foreach ($data['supplier_ids'] as $sid) {
                $s = $this->supplier($actor, (int) $sid);
                $suppliers[] = ['supplier_id' => $s->id, 'supplier_name' => $s->name];
            }
            $header = array_intersect_key($data, array_flip(['subject', 'request_date', 'deadline', 'requirements', 'justification']));
            if ($doc) {
                if ($doc->created_by !== $actor || $doc->requisition_id !== $source?->id || $doc->source_type !== $data['source_type'] || $doc->branch_id !== $branch || $doc->creation_key !== $data['creation_key']) {
                    $this->fail('هوية طلب العرض غير قابلة للتغيير.');
                }
                if (! $id) {
                    $same = collect($header)->every(fn ($v, $k) => ($doc->$k ?: null) === ($v ?: null));
                    $stored = $doc->lines()->orderBy('id')->get()->map(fn ($l) => $l->only(array_keys($lines[0])))->all();
                    if (! $same || $stored !== $lines || $doc->suppliers()->orderBy('id')->pluck('supplier_id')->all() !== array_map('intval', $data['supplier_ids'])) {
                        $this->fail('مفتاح حفظ مكرر ببيانات مختلفة.');
                    }

                    return $doc;
                }
                if ($doc->status !== 'draft' || $doc->version !== $version) {
                    $this->fail('طلب العرض ليس مسودة أو نسخته قديمة.');
                }
            }
            $before = $doc?->toArray();
            $doc ??= new PurchaseRfq(['creation_key' => $data['creation_key'], 'branch_id' => $branch, 'source_type' => $data['source_type'], 'requisition_id' => $source?->id, 'created_by' => $actor, 'status' => 'draft', 'version' => 0]);
            $doc->fill($header);
            $doc->version++;
            $doc->save();
            $doc->lines()->delete();
            $doc->suppliers()->delete();
            $doc->lines()->createMany($lines);
            $doc->suppliers()->createMany($suppliers);
            event(new RfqChanged($actor, $doc->id, 'rfq.saved', $before, $doc->fresh()->load(['lines', 'suppliers'])->toArray()));

            return $doc->fresh();
        });
    }

    public function issue(int $actor, int $id, int $version): void
    {
        DB::transaction(function () use ($actor, $id, $version) {
            $this->lock();
            $doc = PurchaseRfq::lockForUpdate()->findOrFail($id);
            app(PurchasingAccess::class)->authorize($actor, 'rfq_issue', $doc->branch_id);
            if ($doc->source_type === 'STANDALONE') {
                app(PurchasingAccess::class)->authorize($actor, 'rfq_standalone', $doc->branch_id);
            }
            if ($doc->status === 'issued') {
                return;
            }
            if ($doc->version !== $version || ! $doc->lines()->exists() || ! $doc->suppliers()->exists()) {
                $this->fail('راجع النسخة وبنود الطلب والموردين.');
            }
            foreach (DB::table('purchasing_rfq_product_lines')->where('rfq_id', $id)->get() as $line) {
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $line->product_id))->product;
                if (! $p['active'] || $p['base_unit_id'] !== $line->base_unit_id) {
                    $this->fail('Product eligibility changed.');
                }
            }
            foreach ($doc->suppliers as $s) {
                $this->supplier($actor, $s->supplier_id);
            }
            $before = $doc->toArray();
            $doc->status = 'issued';
            $doc->issued_at = now();
            $doc->version++;
            $doc->save();
            event(new RfqChanged($actor, $id, 'rfq.issued', $before, $doc->toArray()));
        });
    }

    public function dispatch(int $actor, int $id, int $supplier, string $reference): void
    {
        DB::transaction(function () use ($actor, $id, $supplier, $reference) {
            $this->lock();
            $doc = PurchaseRfq::lockForUpdate()->findOrFail($id);
            app(PurchasingAccess::class)->authorize($actor, 'rfq_dispatch', $doc->branch_id);
            if ($doc->source_type === 'STANDALONE') {
                app(PurchasingAccess::class)->authorize($actor, 'rfq_standalone', $doc->branch_id);
            }
            if ($doc->status !== 'issued') {
                $this->fail('أصدر الطلب قبل تسجيل الإرسال الفعلي.');
            }
            Validator::make(['reference' => $reference], ['reference' => 'required|string|max:300|not_regex:/\x00/'])->validate();
            $row = $doc->suppliers()->where('supplier_id', $supplier)->firstOrFail();
            if ($row->dispatched_at) {
                if ($row->dispatch_reference !== $reference) {
                    $this->fail('تم تسجيل الإرسال مسبقًا بمرجع مختلف.');
                }

                return;
            }
            $before = $row->toArray();
            $row->update(['dispatched_at' => now(), 'dispatched_by' => $actor, 'dispatch_reference' => $reference]);
            event(new RfqChanged($actor, $id, 'rfq.dispatch_recorded', $before, $row->fresh()->toArray()));
        });
    }
}

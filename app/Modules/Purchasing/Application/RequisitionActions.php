<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchasingChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RequisitionActions
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['requisition' => $message]);
    }

    private function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(10401)');
        DB::select('SELECT pg_advisory_xact_lock(50501)');
    }

    public function save(int $actor, array $data, ?int $id, int $version): PurchaseRequisition
    {
        return DB::transaction(function () use ($actor, $data, $id, $version) {
            $this->lock();
            $data = Validator::make($data, [
                'creation_key' => 'required|uuid', 'branch_id' => 'required|integer|min:1',
                'request_date' => 'required|date_format:Y-m-d', 'required_date' => 'nullable|date_format:Y-m-d|after_or_equal:request_date',
                'department' => 'nullable|string|max:160|not_regex:/\x00/', 'purpose' => 'required|string|max:3000|not_regex:/\x00/',
                'notes' => 'nullable|string|max:5000|not_regex:/\x00/', 'lines' => 'required|array|min:1|max:100',
                'lines.*.product_id' => 'required|integer|min:1|distinct',
                'lines.*.quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'],
                'lines.*.required_date' => 'nullable|date_format:Y-m-d|after_or_equal:request_date',
                'lines.*.notes' => 'nullable|string|max:1000|not_regex:/\x00/',
            ])->validate();
            $access = app(PurchasingAccess::class);
            $access->authorize($actor, $id ? 'requisition_update' : 'requisition_create', (int) $data['branch_id']);
            $doc = $id ? PurchaseRequisition::lockForUpdate()->findOrFail($id) : PurchaseRequisition::where('creation_key', $data['creation_key'])->lockForUpdate()->first();
            $lines = [];
            foreach ($data['lines'] as $line) {
                if (bccomp($line['quantity'], '0', 8) <= 0) {
                    $this->fail('الكمية يجب أن تكون أكبر من صفر.');
                }
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $line['product_id']))->product;
                if (! $p['active']) {
                    $this->fail('الصنف غير نشط.');
                }
                $lines[] = ['product_id' => $p['id'], 'unit_id' => $p['base_unit_id'], 'sku' => $p['sku'], 'product_name' => $p['name'], 'quantity' => bcadd($line['quantity'], '0', 8), 'required_date' => ($line['required_date'] ?? null) ?: null, 'notes' => ($line['notes'] ?? null) ?: null];
            }
            $header = array_intersect_key($data, array_flip(['request_date', 'required_date', 'department', 'purpose', 'notes']));
            if ($doc) {
                if ($doc->created_by !== $actor || $doc->branch_id !== (int) $data['branch_id'] || $doc->creation_key !== $data['creation_key']) {
                    $this->fail('هوية المستند لا يمكن تغييرها.');
                }
                if (! $id) {
                    $same = collect($header)->every(fn ($value, $key) => ($doc->$key ?: null) === ($value ?: null));
                    $existing = $doc->lines()->orderBy('id')->get()->map(fn ($l) => $l->only(array_keys($lines[0])))->all();
                    if (! $same || $existing !== $lines) {
                        $this->fail('تم استخدام مفتاح الحفظ بالفعل ببيانات مختلفة.');
                    }

                    return $doc;
                }
                if ($doc->status !== 'draft' || $doc->version !== $version) {
                    $this->fail('المستند ليس مسودة أو تم تعديله. أعد فتح الصفحة.');
                }
            }
            $before = $doc?->toArray();
            $doc ??= new PurchaseRequisition(['creation_key' => $data['creation_key'], 'branch_id' => $data['branch_id'], 'created_by' => $actor, 'status' => 'draft', 'version' => 0]);
            $doc->fill($header);
            $doc->version++;
            $doc->save();
            $doc->lines()->delete();
            $doc->lines()->createMany($lines);
            event(new PurchasingChanged($actor, $doc->id, 'requisition.saved', $before, $doc->fresh()->load('lines')->toArray()));

            return $doc->fresh();
        });
    }

    public function transition(int $actor, int $id, int $version, string $action, string $reason = ''): PurchaseRequisition
    {
        return DB::transaction(function () use ($actor, $id, $version, $action, $reason) {
            $this->lock();
            $doc = PurchaseRequisition::lockForUpdate()->findOrFail($id);
            $permission = match ($action) {
                'submit' => 'requisition_submit', 'approve','reject' => 'requisition_approve', 'reopen' => 'requisition_update', default => abort(422)
            };
            app(PurchasingAccess::class)->authorize($actor, $permission, $doc->branch_id);
            if ($doc->version !== $version) {
                $this->fail('نسخة قديمة؛ أعد فتح المستند.');
            }
            $target = match ($action) {
                'submit' => 'submitted', 'approve' => 'approved', 'reject' => 'rejected', 'reopen' => 'draft'
            };
            $source = match ($action) {
                'submit' => 'draft', 'reopen' => 'rejected', default => 'submitted'
            };
            if ($doc->status !== $source) {
                $this->fail('حالة المستند لا تسمح بهذا الإجراء.');
            }
            if (in_array($action, ['approve', 'reject']) && $doc->created_by === $actor) {
                $this->fail('لا يمكنك اعتماد أو رفض مستند أنشأته بنفسك.');
            }
            if (in_array($action, ['submit', 'reopen']) && $doc->created_by !== $actor) {
                $this->fail('هذا الإجراء لمنشئ المستند.');
            }
            Validator::make(['reason' => $reason], ['reason' => ($action === 'reject' ? 'required' : 'nullable').'|string|max:2000|not_regex:/\x00/'])->validate();
            if (! $doc->lines()->exists()) {
                $this->fail('أضف بنود المستند أولًا.');
            }
            if (in_array($action, ['submit', 'approve'])) {
                foreach ($doc->lines as $line) {
                    $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $line->product_id))->product;
                    if (! $p['active'] || $p['base_unit_id'] !== $line->unit_id) {
                        $this->fail('أحد الأصناف غير متاح أو تغيرت وحدته.');
                    }
                }
            }
            $before = $doc->toArray();
            DB::table('purchase_requisition_reviews')->insert(['requisition_id' => $id, 'actor_id' => $actor, 'action' => $action, 'document_version' => $version, 'reason' => $reason ?: null, 'created_at' => now()]);
            $doc->status = $target;
            $doc->version++;
            $doc->save();
            event(new PurchasingChanged($actor, $id, 'requisition.'.$action, $before, $doc->toArray()));

            return $doc;
        });
    }
}

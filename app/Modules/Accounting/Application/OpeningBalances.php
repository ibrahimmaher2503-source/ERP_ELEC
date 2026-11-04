<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class OpeningBalances
{
    public function normalize(int $actor, array $lines, bool $review = false): array
    {
        foreach ($lines as &$line) {
            if (isset($line['serials']) && is_string($line['serials'])) {
                $line['serials'] = json_decode($line['serials'], true, 512, JSON_THROW_ON_ERROR);
            }
        }
        unset($line);
        Validator::make(['lines' => $lines], [
            'lines' => 'present|array|max:500', 'lines.*.kind' => 'required|in:gl,ap,ar,cash,bank,inventory',
            'lines.*.reference' => 'required|string|max:160|not_regex:/\x00/', 'lines.*.branch_id' => 'required|integer|min:1',
            'lines.*.account_id' => 'nullable|integer|min:1', 'lines.*.supplier_id' => 'nullable|integer|min:1', 'lines.*.customer_id' => 'nullable|integer|min:1',
            'lines.*.warehouse_id' => 'nullable|integer|min:1', 'lines.*.product_id' => 'nullable|integer|min:1',
            'lines.*.serials' => 'sometimes|array|list|max:1000', 'lines.*.serials.*' => 'required|string|max:200',
        ])->validate();
        $out = [];
        $seen = [];
        foreach ($lines as $line) {
            $branch = (int) $line['branch_id'];
            app(AccountingAccess::class)->authorize($actor, $review ? 'opening_review' : 'opening_entry', $branch);
            if (! app(OrganizationApi::class)->projectBranchMember($actor, $branch)) {
                FinancialActivation::fail('يلزم نطاق فرع نشط لكل رصيد افتتاحي.');
            }
            $key = $line['kind'].':'.$line['reference'];
            if (isset($seen[$key])) {
                FinancialActivation::fail('مرجع افتتاحي مكرر لنفس النوع.');
            }
            $seen[$key] = true;
            $row = ['kind' => $line['kind'], 'reference' => $line['reference'], 'branch_id' => $branch,
                'account_id' => null, 'supplier_id' => null, 'customer_id' => null, 'warehouse_id' => null, 'product_id' => null,
                'debit' => '0.00', 'credit' => '0.00', 'quantity' => null, 'unit_cost' => null, 'amount' => '0.00', 'serials' => '[]'];
            if ($line['kind'] !== 'inventory' && ($line['serials'] ?? []) !== []) {
                FinancialActivation::fail('الأرقام التسلسلية تخص تفاصيل افتتاح المخزون فقط.');
            }
            if ($line['kind'] === 'gl') {
                $account = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find($line['account_id'] ?? null);
                if (! $account) {
                    FinancialActivation::fail('الحساب الافتتاحي غير صالح للترحيل.');
                }
                $dr = Decimal::value($line['debit'] ?? '0', 2);
                $cr = Decimal::value($line['credit'] ?? '0', 2);
                if (! ((bccomp($dr, '0', 2) > 0 && bccomp($cr, '0', 2) === 0) || (bccomp($cr, '0', 2) > 0 && bccomp($dr, '0', 2) === 0))) {
                    FinancialActivation::fail('كل بند GL افتتاحي مدين أو دائن بمبلغ موجب.');
                }
                $row['account_id'] = $account->id;
                $row['debit'] = bcadd($dr, '0', 2);
                $row['credit'] = bcadd($cr, '0', 2);
            } else {
                $row['amount'] = bcadd(Decimal::value($line['amount'] ?? null, 2), '0', 2);
                if ($line['kind'] === 'ap') {
                    $supplier = app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, (int) ($line['supplier_id'] ?? 0)));
                    $row['supplier_id'] = $supplier->id;
                }
                if ($line['kind'] === 'ar') {
                    $row['customer_id'] = app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, (int) ($line['customer_id'] ?? 0)))->id;
                }
                if ($line['kind'] === 'inventory') {
                    $wid = (int) ($line['warehouse_id'] ?? 0);
                    $warehouse = app(InventoryApi::class)->warehouse($wid);
                    if (! $warehouse || ! $warehouse['active'] || $warehouse['branch_id'] !== $branch) {
                        FinancialActivation::fail('مستودع افتتاحي غير نشط أو لا يتبع الفرع.');
                    }
                    // The owner checks effective warehouse scope, even during preview.
                    app(InventoryApi::class)->getAvailability($actor, $wid, (int) ($line['product_id'] ?? 0));
                    $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $line['product_id']))->product;
                    if (! $product['active'] || $product['kind'] === 'service' || ! in_array($product['tracking'], ['quantity', 'serial'], true)) {
                        FinancialActivation::fail('يلزم صنف مخزني نشط بتتبع كمي أو مسلسل.');
                    }
                    $qty = Decimal::value($line['quantity'] ?? null, 8);
                    $cost = Decimal::value($line['unit_cost'] ?? null, 16);
                    if (bccomp($qty, '0', 8) <= 0 || Decimal::round(bcmul($qty, $cost, Decimal::SCALE)) !== $row['amount']) {
                        FinancialActivation::fail('قيمة افتتاح المخزون يجب أن تساوي الكمية × تكلفة FIFO مقربة إلى منزلتين.');
                    }
                    $row['warehouse_id'] = $wid;
                    $row['product_id'] = $product['id'];
                    $row['quantity'] = bcadd($qty, '0', 8);
                    $row['unit_cost'] = bcadd($cost, '0', 16);
                    $serials = $line['serials'] ?? [];
                    if ($product['tracking'] === 'serial') {
                        $serials = app(InventoryApi::class)->normalizeSerialSelections($serials, $row['quantity']);
                    } elseif ($serials !== []) {
                        FinancialActivation::fail('الصنف الكمي لا يقبل أرقامًا تسلسلية.');
                    }
                    $row['serials'] = json_encode($serials, JSON_THROW_ON_ERROR);
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    public function reconciliation(array $lines, bool $noBalances = false): array
    {
        $errors = [];
        $groups = [];
        $maps = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose')->all();
        foreach ($lines as $line) {
            $branch = $line['branch_id'];
            $groups[$branch] ??= ['debit' => '0.00', 'credit' => '0.00', 'gl' => [], 'details' => []];
            $group = &$groups[$branch];
            if ($line['kind'] === 'gl') {
                $group['debit'] = bcadd($group['debit'], $line['debit'], 2);
                $group['credit'] = bcadd($group['credit'], $line['credit'], 2);
                $id = $line['account_id'];
                $group['gl'][$id] = bcadd($group['gl'][$id] ?? '0', bcsub($line['debit'], $line['credit'], 2), 2);
            } else {
                $kind = $line['kind'];
                $group['details'][$kind] = bcadd($group['details'][$kind] ?? '0', $line['amount'], 2);
            }
            unset($group);
        }
        if (($noBalances && $lines !== []) || (! $noBalances && $lines === [])) {
            $errors[] = 'أدخل الأرصدة الفعلية أو صرح صراحة بعدم وجود أرصدة مع مستند مؤيد واعتماد مستقل.';
        }
        foreach ($groups as $branch => $group) {
            if (bccomp($group['debit'], $group['credit'], 2) !== 0) {
                $errors[] = 'قيد افتتاح غير متوازن للفرع #'.$branch;
            }
            foreach (['ap', 'ar', 'cash', 'bank', 'inventory'] as $purpose) {
                $gl = $group['gl'][$maps[$purpose === 'ar' ? 'receivable' : $purpose] ?? 0] ?? '0.00';
                $detail = $group['details'][$purpose] ?? '0.00';
                if ($purpose === 'ap') {
                    $gl = bcsub('0', $gl, 2);
                }
                if (bccomp($gl, $detail, 2) !== 0) {
                    $errors[] = 'اختلاف '.$purpose.' بين GL والتفاصيل للفرع #'.$branch;
                }
            }
            // Unmatched GRNI has no detailed producer; settle it as supplier payables before go-live.
            foreach (['grni'] as $purpose) {
                if (bccomp($group['gl'][$maps[$purpose] ?? 0] ?? '0', '0', 2) !== 0) {
                    $errors[] = 'الرصيد الافتتاحي '.$purpose.' يتطلب تفاصيل من منتجه، غير مدعوم في مسار الافتتاح الحالي.';
                }
            }
        }

        return ['balanced' => $errors === [], 'errors' => $errors, 'branches' => $groups];
    }

    public function save(int $actor, int $configurationVersion, string $key, array $lines, bool $noBalances, string $evidence): int
    {
        Validator::make(compact('key', 'evidence'), ['key' => 'required|uuid', 'evidence' => 'required|string|min:5|max:2000|not_regex:/\x00/'])->validate();

        return DB::transaction(function () use ($actor, $configurationVersion, $key, $lines, $noBalances, $evidence) {
            $setup = app(FinancialActivation::class)->lock();
            app(AccountingAccess::class)->authorize($actor, 'opening_manage');
            if ($setup->activated_at || $setup->version !== $configurationVersion) {
                FinancialActivation::fail('الإعداد مفعل أو تغير منذ المعاينة.');
            }
            if (DB::table('accounting_opening_batches')->where('creation_key', $key)->exists()) {
                FinancialActivation::fail('تم حفظ هذا الطلب بالفعل. أعد تحميل حزمة الافتتاح.');
            }
            $rows = $this->normalize($actor, $lines);
            if (($noBalances && $rows !== []) || (! $noBalances && $rows === [])) {
                FinancialActivation::fail('حزمة فارغة دون تصريح صريح أو تصريح لا يطابق البنود.');
            }
            $id = DB::table('accounting_opening_batches')->insertGetId(['creation_key' => $key, 'configuration_version' => $configurationVersion,
                'no_balances' => $noBalances, 'evidence_reference' => $evidence, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($rows as $row) {
                DB::table('accounting_opening_lines')->insert($row + ['batch_id' => $id]);
            }
            app(FinancialActivation::class)->audit($actor, $id, 'opening.draft_saved', null, ['count' => count($rows), 'no_balances' => $noBalances, 'configuration_version' => $configurationVersion, 'evidence' => $evidence]);

            return $id;
        });
    }

    public function advance(int $actor, int $id, int $version, string $action): void
    {
        abort_unless(in_array($action, ['submit', 'approve'], true), 404);
        DB::transaction(function () use ($actor, $id, $version, $action) {
            $setup = app(FinancialActivation::class)->lock();
            app(AccountingAccess::class)->authorize($actor, $action === 'approve' ? 'opening_approve' : 'opening_manage');
            $batch = DB::table('accounting_opening_batches')->lockForUpdate()->find($id);
            abort_unless($batch, 404);
            if ($setup->activated_at || $batch->configuration_version !== $setup->version || $batch->version !== $version
                || $batch->status !== ($action === 'submit' ? 'draft' : 'submitted')
                || ($action === 'submit' && $batch->created_by !== $actor) || ($action === 'approve' && ($batch->created_by === $actor || $setup->configured_by === $actor))) {
                FinancialActivation::fail('نسخة أو حالة غير صالحة، أو اعتماد ذاتي. يلزم مراجع مستقل عن مُعد الأرصدة والإعداد.');
            }
            if ((int) DB::table('accounting_opening_batches')->max('id') !== $id) {
                FinancialActivation::fail('توجد حزمة أحدث يجب مراجعتها.');
            }
            $rows = $this->normalize($actor, $this->lines($id), $action === 'approve');
            $errors = array_merge(app(FinancialActivation::class)->blockers(), $this->reconciliation($rows, $batch->no_balances)['errors']);
            if ($errors) {
                FinancialActivation::fail(implode(' ', $errors));
            }
            $after = ['status' => $action === 'submit' ? 'submitted' : 'approved', 'version' => $version + 1, 'updated_at' => now()];
            $after += $action === 'approve' ? ['approved_by' => $actor, 'approved_at' => now()] : ['submitted_at' => now()];
            DB::table('accounting_opening_batches')->where('id', $id)->update($after);
            app(FinancialActivation::class)->audit($actor, $id, 'opening.'.$action, (array) $batch, $after);
        });
    }

    public function lines(int $batch): array
    {
        return DB::table('accounting_opening_lines')->where('batch_id', $batch)->orderBy('id')->get()->map(function ($row) {
            $row->serials = json_decode($row->serials, true, 512, JSON_THROW_ON_ERROR);

            return (array) $row;
        })->all();
    }
}

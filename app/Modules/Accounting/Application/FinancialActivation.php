<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Contracts\FinancialSetup;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FinancialActivation implements FinancialSetup
{
    public const MAPPINGS = [
        'inventory_transit' => ['مخزون بالطريق بين المستودعات', 'asset', true],
        'inventory' => ['المخزون', 'asset', true], 'grni' => ['استلام غير مفوتر استلامات بانتظار الفوترة', 'liability', true],
        'ppv' => ['فروق سعر الشراء فروق سعر الشراء', 'expense'], 'ap' => ['مراقبة الموردين', 'liability', true],
        'cash' => ['مراقبة النقدية', 'asset', true], 'bank' => ['مراقبة البنوك', 'asset', true],
        'tax_input' => ['ضريبة مدخلات', 'asset', true], 'tax_output' => ['ضريبة مخرجات', 'liability', true],
        'receivable' => ['مراقبة العملاء', 'asset', true], 'sales' => ['المبيعات', 'income'],
        'cogs' => ['تكلفة البضاعة المباعة', 'expense'], 'stock_adjustment' => ['تسويات المخزون', 'expense'],
        'inventory_issue' => ['مصروف الصرف الداخلي', 'expense'],
        'rounding' => ['فروق التقريب المسموح بها', 'expense'],
    ];

    public const OPTIONAL_MAPPINGS = [
        'service_contract_deferred' => ['إيراد عقود خدمة غير مكتسب', 'liability', true],
        'maintenance_labor' => ['تكلفة عمل الصيانة المعتمدة', 'expense'],
        'maintenance_labor_accrual' => ['تكلفة عمل صيانة مستحقة', 'liability', true],
        'customer_advance' => ['دفعات مقدمة من العملاء', 'liability', true],
    ];

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['form' => $message]);
    }

    public function lock(): object
    {
        app(AccountingActions::class)->lock();

        return DB::table('accounting_activation')->where('id', 1)->lockForUpdate()->first();
    }

    public function configuration(int $actor): array
    {
        app(AccountingAccess::class)->authorize($actor, 'setup_view');

        return (array) DB::table('accounting_activation')->find(1);
    }

    public function save(int $actor, int $version, array $data): void
    {
        $v = Validator::make($data, ['go_live_date' => 'nullable|date_format:Y-m-d', 'mappings' => 'required|array', 'mappings.*' => 'nullable|integer|min:1'])->validate();
        $v['go_live_date'] = ($v['go_live_date'] ?? '') ?: null;
        DB::transaction(function () use ($actor, $version, $v) {
            $old = $this->lock();
            app(AccountingAccess::class)->authorize($actor, 'setup_manage');
            $this->editable($old, $version);
            $mappings = [];
            foreach (self::MAPPINGS as $purpose => [$label, $kind]) {
                $id = $v['mappings'][$purpose] ?? null;
                if ($id === null || $id === '') {
                    continue;
                }
                $account = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find($id);
                if (! $account || $account->kind !== $kind) {
                    self::fail('اختر حساب ترحيل نشطًا من التصنيف الصحيح: '.$label);
                }
                $mappings[$purpose] = (int) $account->id;
                if (self::MAPPINGS[$purpose][2] ?? false) {
                    app(FinancialControlAccounts::class)->assertMapping((int) $account->id);
                }
            }
            if (count(array_unique($mappings)) !== count($mappings)) {
                self::fail('يلزم حساب مستقل لكل غرض رقابي حتى يمكن إجراء المصالحة.');
            }
            DB::table('accounting_account_mappings')->delete();
            foreach ($mappings as $purpose => $id) {
                DB::table('accounting_account_mappings')->insert(['purpose' => $purpose, 'account_id' => $id, 'updated_at' => now()]);
            }
            $after = ['go_live_date' => $v['go_live_date'], 'configured_by' => $actor, 'version' => $version + 1, 'updated_at' => now()];
            DB::table('accounting_activation')->where('id', 1)->update($after);
            $this->audit($actor, 1, 'activation.configuration_saved', (array) $old, $after + ['mappings' => $mappings]);
        });
    }

    public function addTaxRule(int $actor, int $version, array $data): void
    {
        $v = Validator::make($data, [
            'code' => 'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/D', 'name' => 'required|string|max:200|not_regex:/\x00/',
            'transaction_type' => 'required|in:sale,purchase', 'product_id' => 'nullable|integer|min:1',
            'rate' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,5})?$/D'],
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'treatment' => 'required|in:percentage,exempt', 'tax_scale' => 'required|in:2,5',
            'legal_reference' => 'required|string|min:5|max:2000|not_regex:/\x00/',
            'purchase_recoverable' => 'nullable|required_if:transaction_type,purchase|boolean',
        ])->validate();
        DB::transaction(function () use ($actor, $version, $v) {
            $old = $this->lock();
            app(AccountingAccess::class)->authorize($actor, 'tax_manage');
            // Tax schedules may be extended after activation, but existing rules never change.
            if ($old->version !== $version) {
                self::fail('تغير الإعداد، أعد تحميل الصفحة.');
            }
            if ($v['treatment'] === 'exempt' && bccomp($v['rate'], '0', 5) !== 0) {
                self::fail('الإعفاء يتطلب نسبة صفر ومرجع المعاملة الصحيح.');
            }
            if (bccomp($v['rate'], '999', 5) > 0) {
                self::fail('النسبة تتجاوز النطاق العددي المدعوم، راجع المعاملة الفعلية.');
            }
            if (! empty($v['product_id'])) {
                app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $v['product_id']));
            }
            $overlap = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
                ->whereRaw('coalesce(e.withdrawn,false)=false')->where('r.transaction_type', $v['transaction_type'])
                ->where('r.product_id', $v['product_id'])->where('r.starts_on', '<=', $v['ends_on'] ?? '9999-12-31')
                ->whereRaw('(coalesce(e.ends_on,r.ends_on) IS NULL OR coalesce(e.ends_on,r.ends_on)>=?::date)', [$v['starts_on']])->exists();
            if ($overlap) {
                self::fail('توجد قاعدة ضريبية متداخلة لنفس الصنف ونوع المعاملة.');
            }
            if ($old->activated_at !== null && $v['starts_on'] <= now('Africa/Cairo')->toDateString()) {
                self::fail('بعد التفعيل تُضاف القواعد للفترات المستقبلية فقط مع مرجع التحقق الفعلي.');
            }
            $id = DB::table('accounting_tax_rules')->insertGetId($v + ['created_by' => $actor, 'created_at' => now()]);
            if ($old->activated_at === null) {
                DB::table('accounting_activation')->where('id', 1)->update(['version' => $version + 1, 'tax_validated' => false, 'tax_validation_reference' => null, 'configured_by' => $actor, 'updated_at' => now()]);
            }
            $this->audit($actor, $id, 'tax.rule_added', null, $v);
        });
    }

    public function saveOptionalMapping(int $actor, string $purpose, int $accountId): void
    {
        abort_unless(isset(self::OPTIONAL_MAPPINGS[$purpose]), 422);
        DB::transaction(function () use ($actor, $purpose, $accountId) {
            app(AccountingActions::class)->lock();
            app(AccountingAccess::class)->authorize($actor, 'setup_manage');
            [$label, $kind] = self::OPTIONAL_MAPPINGS[$purpose];
            $account = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->lockForUpdate()->find($accountId);
            if (! $account || $account->kind !== $kind) {
                self::fail('اختر حساب ترحيل نشطاً من التصنيف الصحيح: '.$label);
            }
            $existing = DB::table('accounting_account_mappings')->where('purpose', $purpose)->first();
            if ($existing && (int) $existing->account_id !== $accountId) {
                self::fail('لا يجوز تغيير ربط تشغيلي اختياري بعد حفظه؛ استخدم حساب تصحيح موثق عند الحاجة.');
            }
            if (DB::table('accounting_account_mappings')->where('account_id', $accountId)->where('purpose', '<>', $purpose)->exists()) {
                self::fail('يلزم حساب مستقل لكل غرض رقابي.');
            }
            if (! $existing) {
                if (self::OPTIONAL_MAPPINGS[$purpose][2] ?? false) {
                    app(FinancialControlAccounts::class)->assertMapping($accountId);
                }
                DB::table('accounting_account_mappings')->insert(['purpose' => $purpose, 'account_id' => $accountId, 'updated_at' => now()]);
                $this->audit($actor, 1, 'activation.optional_mapping_added', null, ['purpose' => $purpose, 'account_id' => $accountId]);
            }
        });
    }

    public function validateTaxTreatment(int $actor, int $version, string $reference): void
    {
        Validator::make(['reference' => $reference], ['reference' => 'required|string|min:5|max:2000|not_regex:/\x00/'])->validate();
        DB::transaction(function () use ($actor, $version, $reference) {
            $old = $this->lock();
            app(AccountingAccess::class)->authorize($actor, 'tax_manage');
            $this->editable($old, $version);
            if (! DB::table('accounting_tax_rules')->exists()) {
                self::fail('أدخل القواعد الضريبية الفعلية قبل تسجيل التحقق.');
            }
            DB::table('accounting_activation')->where('id', 1)->update(['tax_validated' => true, 'tax_validation_reference' => $reference, 'version' => $version + 1, 'configured_by' => $actor, 'updated_at' => now()]);
            $this->audit($actor, 1, 'tax.treatment_validated', (array) $old, ['reference' => $reference, 'version' => $version + 1]);
        });
    }

    public function endTaxRule(int $actor, int $version, int $rule, ?string $endsOn, string $reason, bool $withdraw = false): void
    {
        Validator::make(['date' => $endsOn, 'reason' => $reason], ['date' => 'nullable|date_format:Y-m-d', 'reason' => 'required|string|min:5|max:2000|not_regex:/\x00/'])->validate();
        DB::transaction(function () use ($actor, $version, $rule, $endsOn, $reason, $withdraw) {
            $setup = $this->lock();
            app(AccountingAccess::class)->authorize($actor, 'tax_manage');
            $tax = DB::table('accounting_tax_rules')->find($rule);
            if (! $tax || $setup->version !== $version || DB::table('accounting_tax_rule_endings')->where('rule_id', $rule)->exists()) {
                self::fail('قاعدة أو نسخة غير صالحة، أو سبق إنهاء القاعدة.');
            }
            if (($withdraw && $setup->activated_at) || (! $withdraw && (! $endsOn || $endsOn < $tax->starts_on || ($tax->ends_on && $endsOn > $tax->ends_on)))
                || ($setup->activated_at && $endsOn < now('Africa/Cairo')->toDateString())) {
                self::fail('بعد التفعيل لا تُسحب قواعد تاريخية. إنهاء القاعدة يكون بتاريخ حالي أو مستقبلي دون تمديد مدتها.');
            }
            DB::table('accounting_tax_rule_endings')->insert(['rule_id' => $rule, 'ends_on' => $withdraw ? null : $endsOn, 'withdrawn' => $withdraw, 'reason' => $reason, 'actor_id' => $actor, 'created_at' => now()]);
            if (! $setup->activated_at) {
                DB::table('accounting_activation')->where('id', 1)->update(['version' => $version + 1, 'tax_validated' => false, 'tax_validation_reference' => null, 'configured_by' => $actor, 'updated_at' => now()]);
            }
            $this->audit($actor, $rule, $withdraw ? 'tax.rule_withdrawn' : 'tax.rule_ended', (array) $tax, ['ends_on' => $endsOn, 'reason' => $reason]);
        });
    }

    public function blockers(): array
    {
        $setup = DB::table('accounting_activation')->find(1);
        $errors = [];
        if (! $setup->go_live_date) {
            $errors[] = 'لم يُحدد تاريخ التشغيل الفعلي للشركة.';
        }
        if (! $setup->tax_validated) {
            $errors[] = 'المعاملة الضريبية للشركة لم يُسجل التحقق منها.';
        }
        $maps = DB::table('accounting_account_mappings as m')->join('accounting_accounts as a', 'a.id', '=', 'm.account_id')->get(['m.purpose', 'a.*'])->keyBy('purpose');
        foreach (self::MAPPINGS as $purpose => [$label, $kind]) {
            $account = $maps->get($purpose);
            if (! $account || ! $account->active || ! $account->postable || $account->kind !== $kind) {
                $errors[] = 'ربط الحساب غير مكتمل: '.$label;
            }
        }
        if ($setup->go_live_date && ! DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $setup->go_live_date)->where('ends_on', '>=', $setup->go_live_date)->exists()) {
            $errors[] = 'لا توجد فترة محاسبية مفتوحة تشمل تاريخ التشغيل.';
        }
        if ($setup->go_live_date) {
            foreach (['sale' => 'البيع', 'purchase' => 'الشراء'] as $type => $label) {
                $exists = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
                    ->where('r.transaction_type', $type)->whereRaw('coalesce(e.withdrawn,false)=false')->where('r.starts_on', '<=', $setup->go_live_date)
                    ->whereRaw('(coalesce(e.ends_on,r.ends_on) IS NULL OR coalesce(e.ends_on,r.ends_on)>=?::date)', [$setup->go_live_date])->exists();
                if (! $exists) {
                    $errors[] = 'لا توجد قاعدة '.$label.' سارية عند تاريخ التشغيل.';
                }
            }
        }

        return $errors;
    }

    public function requirePostingDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $setup = DB::table('accounting_activation')->find(1);
        if (! $setup || ! $setup->activated_at || ! $setup->go_live_date || $date < $setup->go_live_date || now('Africa/Cairo')->toDateString() < $setup->go_live_date) {
            self::fail('الترحيل المالي مغلق أو تاريخ المعاملة يسبق تاريخ التشغيل العام.');
        }
    }

    public function prepareActivation(int $actor, int $batch, int $version): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $setup = $this->lock();
        app(AccountingAccess::class)->authorize($actor, 'financial_activate');
        $opening = DB::table('accounting_opening_batches')->lockForUpdate()->find($batch);
        if (! $opening || $opening->version !== $version || $opening->status !== 'approved' || $setup->activated_at
            || $opening->configuration_version !== $setup->version || (int) DB::table('accounting_opening_batches')->max('id') !== $batch) {
            self::fail('يلزم اعتماد حزمة الافتتاح الحالية وإعداداتها قبل التفعيل.');
        }
        $lines = app(OpeningBalances::class)->normalize($actor, app(OpeningBalances::class)->lines($batch), true);
        $errors = array_merge($this->blockers(), app(OpeningBalances::class)->reconciliation($lines, $opening->no_balances)['errors']);
        if ($errors) {
            self::fail(implode(' ', $errors));
        }

        return ['batch' => (array) $opening, 'setup' => (array) $setup, 'lines' => $lines];
    }

    public function completeActivation(int $actor, array $source): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $batch = $source['batch'];
        foreach (collect($source['lines'])->where('kind', 'gl')->groupBy('branch_id') as $branch => $lines) {
            app(JournalPosting::class)->opening($actor, $batch, (int) $branch, $source['setup']['go_live_date'], $lines->all());
        }
        DB::table('accounting_opening_batches')->where('id', $batch['id'])->update(['status' => 'posted', 'posted_at' => now(), 'version' => $batch['version'] + 1, 'updated_at' => now()]);
        DB::table('accounting_activation')->where('id', 1)->update(['activated_by' => $actor, 'activated_at' => now(), 'updated_at' => now()]);
        $this->audit($actor, $batch['id'], 'activation.completed', null, ['go_live_date' => $source['setup']['go_live_date'], 'opening_batch' => $batch['id'], 'approved_by' => $batch['approved_by'], 'configuration_version' => $source['setup']['version']]);
    }

    private function editable(object $setup, int $version): void
    {
        if ($setup->activated_at || $setup->version !== $version) {
            self::fail('الإعداد مفعل وثابت أو النسخة قديمة.');
        }
    }

    public function audit(int $actor, int $id, string $action, ?array $before, array $after): void
    {
        $type = match (true) {
            str_starts_with($action, 'opening.') => 'accounting_opening_batch',
            str_starts_with($action, 'tax.rule_') => 'accounting_tax_rule',
            str_starts_with($action, 'journal.') => 'accounting_journal',
            default => 'financial_activation',
        };
        app(RecordAudit::class)->execute($actor, $type, $id, $action, $before, $after, 'succeeded', 'Accounting');
    }
}

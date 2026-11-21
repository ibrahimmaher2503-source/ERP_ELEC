<?php

namespace App\Modules\Accounting\Presentation;

use App\Application\ActivateFinancialPosting;
use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Application\OpeningWorkbook;
use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class FinancialSetupPage extends Component
{
    use WithFileUploads;

    private const STARTER_ACCOUNTS = [
        'cash' => ['1101', 'النقدية بالخزينة'],
        'bank' => ['1102', 'الحسابات البنكية'],
        'receivable' => ['1103', 'ذمم العملاء'],
        'inventory' => ['1104', 'المخزون السلعي'],
        'inventory_transit' => ['1105', 'مخزون بالطريق بين المستودعات'],
        'tax_input' => ['1106', 'ضريبة القيمة المضافة - مدخلات'],
        'ap' => ['2101', 'ذمم الموردين'],
        'grni' => ['2102', 'استلامات بانتظار الفوترة'],
        'tax_output' => ['2103', 'ضريبة القيمة المضافة - مخرجات'],
        'sales' => ['4101', 'إيرادات المبيعات'],
        'cogs' => ['5101', 'تكلفة البضاعة المباعة'],
        'ppv' => ['5102', 'فروق أسعار الشراء'],
        'stock_adjustment' => ['5103', 'تسويات وجرد المخزون'],
        'inventory_issue' => ['5104', 'مصروف الصرف الداخلي'],
        'rounding' => ['5105', 'فروق التقريب المسموح بها'],
    ];

    private const EXTRA_STARTER_ACCOUNTS = [
        ['key' => 'customer_advance', 'code' => '2104', 'name' => 'دفعات مقدمة من العملاء (عربون)', 'kind' => 'liability', 'postable' => true, 'parent_code' => null, 'optional_purpose' => 'customer_advance'],
        ['key' => 'capital', 'code' => '3101', 'name' => 'رأس مال الشركاء', 'kind' => 'equity', 'postable' => true, 'parent_code' => null, 'optional_purpose' => null],
        ['key' => 'partners_group', 'code' => '3200', 'name' => 'جاري الشركاء', 'kind' => 'equity', 'postable' => false, 'parent_code' => null, 'optional_purpose' => null],
        ['key' => 'partner_1', 'code' => '3201', 'name' => 'جاري الشريك الأول', 'kind' => 'equity', 'postable' => true, 'parent_code' => '3200', 'optional_purpose' => null],
        ['key' => 'partner_2', 'code' => '3202', 'name' => 'جاري الشريك الثاني', 'kind' => 'equity', 'postable' => true, 'parent_code' => '3200', 'optional_purpose' => null],
        ['key' => 'partner_3', 'code' => '3203', 'name' => 'جاري الشريك الثالث', 'kind' => 'equity', 'postable' => true, 'parent_code' => '3200', 'optional_purpose' => null],
        ['key' => 'repair_revenue', 'code' => '4102', 'name' => 'إيرادات الإصلاح والصيانة', 'kind' => 'income', 'postable' => true, 'parent_code' => null, 'optional_purpose' => null],
        ['key' => 'operating_expense', 'code' => '5201', 'name' => 'مصروفات تشغيلية وإدارية', 'kind' => 'expense', 'postable' => true, 'parent_code' => null, 'optional_purpose' => null],
    ];

    #[Locked]
    public int $configurationVersion = 0;

    #[Locked]
    public string $creationKey = '';

    #[Locked]
    public array $preview = [];

    #[Locked]
    public ?string $previewFingerprint = null;

    public int $activeStep = 1;

    public string $openingMode = 'zero';

    public string $taxScope = 'all';

    public array $configuration = ['go_live_date' => null, 'mappings' => []];

    public array $taxRule = ['code' => '', 'name' => '', 'transaction_type' => 'sale', 'product_id' => '', 'rate' => '', 'starts_on' => '', 'ends_on' => '', 'treatment' => 'percentage', 'tax_scale' => '5', 'legal_reference' => '', 'purchase_recoverable' => ''];

    public array $taxEnd = ['rule_id' => '', 'ends_on' => '', 'reason' => '', 'withdraw' => false];

    public string $taxReference = '';

    public string $customerAdvanceAccount = '';

    public array $maintenanceMappings = ['maintenance_labor' => '', 'maintenance_labor_accrual' => ''];

    public array $optionalMappings = [
        'customer_advance' => '',
        'maintenance_labor' => '',
        'maintenance_labor_accrual' => '',
        'service_contract_deferred' => '',
    ];

    public string $taxProductSearch = '';

    public string $supplierSearch = '';

    public string $customerSearch = '';

    public string $inventoryProductSearch = '';

    public bool $showAccountModal = false;

    public string $accountModalTarget = '';

    public array $newAccount = ['code' => '', 'name' => '', 'kind' => 'asset', 'parent_id' => ''];

    public bool $showBulkAccountsModal = false;

    public array $suggestedAccounts = [];

    public array $suggestedExtraAccounts = [];

    public bool $showPeriodModal = false;

    public array $newPeriod = ['name' => '', 'starts_on' => '', 'ends_on' => ''];

    public bool $showEndTaxModal = false;

    public array $lines = [];

    public bool $noBalances = true;

    public string $evidence = '';

    public $workbook;

    public function mount(): void
    {
        $this->reloadConfiguration();
        $this->creationKey = (string) Str::uuid();

        $batch = DB::table('accounting_opening_batches')->orderByDesc('id')->first();
        if ($batch && ! $batch->no_balances) {
            $this->openingMode = 'manual';
            $this->noBalances = false;
        } else {
            $this->openingMode = 'zero';
            $this->noBalances = true;
        }

        $setup = app(FinancialActivation::class)->configuration(auth()->id());
        $stepStates = $this->computeStepStates($setup, $batch);
        $firstIncomplete = collect($stepStates)->search(fn (array $step) => ! $step['done']);
        $this->activeStep = $firstIncomplete === false ? 4 : (int) $firstIncomplete;
    }

    private function authorizeSetup(): void
    {
        app(AccountingAccess::class)->authorize(auth()->id(), 'setup_view');
    }

    private function reloadConfiguration(): void
    {
        $this->authorizeSetup();
        $setup = app(FinancialActivation::class)->configuration(auth()->id());
        $this->configurationVersion = $setup['version'];
        $this->configuration = [
            'go_live_date' => $setup['go_live_date'],
            'mappings' => DB::table('accounting_account_mappings')->pluck('account_id', 'purpose')->all(),
        ];
        $this->customerAdvanceAccount = (string) ($this->configuration['mappings']['customer_advance'] ?? '');
        foreach (array_keys($this->maintenanceMappings) as $purpose) {
            $this->maintenanceMappings[$purpose] = (string) ($this->configuration['mappings'][$purpose] ?? '');
        }
        foreach (array_keys($this->optionalMappings) as $purpose) {
            $this->optionalMappings[$purpose] = (string) ($this->configuration['mappings'][$purpose] ?? '');
        }
        $this->taxReference = $setup['tax_validation_reference'] ?? '';
        $this->preview = [];
        $this->previewFingerprint = null;
    }

    public function goToStep(int $step): void
    {
        $this->authorizeSetup();
        $this->resetValidation();
        $this->activeStep = max(1, min(4, $step));
    }

    public function setOpeningMode(string $mode): void
    {
        $this->authorizeSetup();
        abort_unless(in_array($mode, ['zero', 'excel', 'manual'], true), 422);
        $this->resetValidation();
        $this->openingMode = $mode;
        $this->noBalances = ($mode === 'zero');
        if ($mode === 'zero') {
            $this->lines = [];
        } elseif ($mode === 'manual' && $this->lines === [] && app(AccountingAccess::class)->allows(auth()->id(), 'opening_manage')) {
            $this->addLine('gl');
        }
        $this->preview = [];
        $this->previewFingerprint = null;
    }

    public function updatedTaxScope(string $value): void
    {
        if ($value !== 'product') {
            $this->taxRule['product_id'] = '';
        }
    }

    public function updatedTaxRuleTreatment(string $value): void
    {
        if ($value === 'exempt') {
            $this->taxRule['rate'] = '0';
        }
    }

    public function updatedNoBalances(): void
    {
        $this->previewFingerprint = null;
    }

    public function updatedEvidence(): void
    {
        $this->previewFingerprint = null;
    }

    public function updatedLines(mixed $value, string $key): void
    {
        $this->previewFingerprint = null;
        if (! preg_match('/^(\d+)\.([a-z_]+)$/', $key, $matches)) {
            return;
        }
        $index = (int) $matches[1];
        $field = $matches[2];
        if (! isset($this->lines[$index])) {
            return;
        }

        $kind = $this->lines[$index]['kind'] ?? 'gl';

        if ($field === 'kind') {
            $branchId = $this->lines[$index]['branch_id'] ?? '';
            $reference = $this->lines[$index]['reference'] ?? '';
            $this->lines[$index] = [
                'kind' => in_array($value, ['gl', 'ap', 'ar', 'cash', 'bank', 'inventory'], true) ? $value : 'gl',
                'reference' => $reference,
                'branch_id' => $branchId,
                'account_id' => '',
                'supplier_id' => '',
                'customer_id' => '',
                'warehouse_id' => '',
                'product_id' => '',
                'debit' => '0.00',
                'credit' => '0.00',
                'quantity' => '',
                'unit_cost' => '',
                'amount' => '',
                'serial_text' => '',
            ];

            return;
        }

        if ($field === 'branch_id' && $kind === 'inventory' && ! empty($this->lines[$index]['warehouse_id'])) {
            try {
                $warehouse = app(InventoryApi::class)->warehouse((int) $this->lines[$index]['warehouse_id']);
                if (! $warehouse || (int) $warehouse['branch_id'] !== (int) $value) {
                    $this->lines[$index]['warehouse_id'] = '';
                }
            } catch (\Throwable) {
                $this->lines[$index]['warehouse_id'] = '';
            }
        }

        if ($kind === 'inventory' && in_array($field, ['quantity', 'unit_cost'], true)) {
            $qty = trim((string) ($this->lines[$index]['quantity'] ?? ''));
            $cost = trim((string) ($this->lines[$index]['unit_cost'] ?? ''));
            if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D', $qty)
                && preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,16})?$/D', $cost)
                && bccomp($qty, '0', 8) > 0) {
                $this->lines[$index]['amount'] = FinancialDecimal::round(bcmul($qty, $cost, FinancialDecimal::SCALE), 2);
            }
        }
    }

    public function saveMaintenanceMapping(string $purpose): void
    {
        abort_unless(array_key_exists($purpose, $this->maintenanceMappings), 422);
        app(FinancialActivation::class)->saveOptionalMapping(auth()->id(), $purpose, (int) $this->maintenanceMappings[$purpose]);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم ربط حساب تكلفة الصيانة المعتمد.');
    }

    public function saveOptionalMapping(string $purpose): void
    {
        $this->authorizeSetup();
        abort_unless(array_key_exists($purpose, FinancialActivation::OPTIONAL_MAPPINGS), 422);
        $accountId = (int) ($this->optionalMappings[$purpose] ?? 0);
        if ($accountId < 1) {
            FinancialActivation::fail('اختر حساباً صالحاً قبل حفظ الربط الاختياري.');
        }
        app(FinancialActivation::class)->saveOptionalMapping(auth()->id(), $purpose, $accountId);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم حفظ الربط الاختياري المعتمد بنجاح.');
    }

    public function saveConfiguration(): void
    {
        $this->authorizeSetup();
        app(FinancialActivation::class)->save(auth()->id(), $this->configurationVersion, $this->configuration);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم حفظ تاريخ التشغيل وربط الحسابات. أي حزمة افتتاح سابقة تحتاج إعداداً واعتماداً جديدين إذا تغيرت النسخة.');
    }

    public function openAccountModal(string $target, string $kind, string $suggestedName = ''): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'account_manage');
        abort_unless(in_array($kind, ['asset', 'liability', 'equity', 'income', 'expense'], true), 422);
        $purpose = $this->resolveModalPurpose($target);
        abort_unless($purpose !== null, 422);

        $expectedKind = $purpose['kind'];
        abort_unless($expectedKind === $kind, 422);

        $this->accountModalTarget = $target;
        $this->newAccount = [
            'code' => $this->suggestAvailableAccountCode($purpose['code']),
            'name' => $suggestedName ?: $purpose['label'],
            'kind' => $expectedKind,
            'parent_id' => '',
        ];
        $this->resetValidation();
        $this->showAccountModal = true;
    }

    public function createQuickAccount(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'account_manage');
        $purpose = $this->resolveModalPurpose($this->accountModalTarget);
        abort_unless($purpose !== null, 422);

        $id = app(AccountingActions::class)->account(auth()->id(), null, 0, [
            'code' => trim((string) ($this->newAccount['code'] ?? '')),
            'name' => trim((string) ($this->newAccount['name'] ?? '')),
            'kind' => $purpose['kind'],
            'parent_id' => ($this->newAccount['parent_id'] ?? '') === '' ? null : (int) $this->newAccount['parent_id'],
            'postable' => true,
            'active' => true,
        ]);

        if ($purpose['group'] === 'mapping') {
            $this->configuration['mappings'][$purpose['key']] = (string) $id;
        } else {
            $this->optionalMappings[$purpose['key']] = (string) $id;
            if ($purpose['key'] === 'customer_advance') {
                $this->customerAdvanceAccount = (string) $id;
            } elseif (array_key_exists($purpose['key'], $this->maintenanceMappings)) {
                $this->maintenanceMappings[$purpose['key']] = (string) $id;
            }
        }

        $this->resetValidation();
        $this->showAccountModal = false;
        session()->flash('financial_message', 'تم إنشاء الحساب في الدليل وتحديده في القائمة. اضغط حفظ لتثبيت الربط.');
    }

    public function openBulkAccountsModal(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'account_manage');
        $setup = app(FinancialActivation::class)->configuration(auth()->id());
        if ($setup['activated_at']) {
            FinancialActivation::fail('الإعداد مفعل وثابت ولا يمكن تعديل الحسابات المطلوبة.');
        }

        $usedCodes = DB::table('accounting_accounts')->pluck('code')->all();
        $this->suggestedAccounts = [];
        foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
            if (! empty($this->configuration['mappings'][$purpose])) {
                continue;
            }
            [$defaultCode, $defaultName] = self::STARTER_ACCOUNTS[$purpose] ?? [strtoupper($purpose), $label];
            $code = $defaultCode;
            $suffix = 1;
            while (in_array($code, $usedCodes, true)) {
                $code = $defaultCode.'-'.$suffix++;
            }
            $usedCodes[] = $code;

            $this->suggestedAccounts[] = [
                'purpose' => $purpose,
                'label' => $label,
                'kind' => $kind,
                'code' => $code,
                'name' => $defaultName,
                'selected' => true,
            ];
        }

        $this->suggestedExtraAccounts = [];
        foreach (self::EXTRA_STARTER_ACCOUNTS as $extra) {
            if (in_array($extra['code'], $usedCodes, true)) {
                continue;
            }
            $usedCodes[] = $extra['code'];
            $this->suggestedExtraAccounts[] = $extra + ['selected' => true];
        }

        $this->resetValidation();
        $this->showBulkAccountsModal = true;
    }

    public function createSuggestedAccounts(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'account_manage');
        $setup = app(FinancialActivation::class)->configuration(auth()->id());
        if ($setup['activated_at']) {
            FinancialActivation::fail('الإعداد مفعل وثابت.');
        }

        $selectedRows = array_values(array_filter($this->suggestedAccounts, fn (array $row) => ! empty($row['selected'])));
        $selectedExtras = array_values(array_filter($this->suggestedExtraAccounts, fn (array $row) => ! empty($row['selected'])));
        if ($selectedRows === [] && $selectedExtras === []) {
            FinancialActivation::fail('اختر حساباً واحداً على الأقل لإنشائه.');
        }

        $createdIds = [];
        $createdOptionalIds = [];
        $extraCount = 0;
        DB::transaction(function () use ($selectedRows, $selectedExtras, &$createdIds, &$createdOptionalIds, &$extraCount): void {
            $actions = app(AccountingActions::class);
            $actor = auth()->id();
            foreach ($selectedRows as $row) {
                $purpose = (string) ($row['purpose'] ?? '');
                abort_unless(isset(FinancialActivation::MAPPINGS[$purpose]), 422);
                $expectedKind = FinancialActivation::MAPPINGS[$purpose][1];
                $createdIds[$purpose] = $actions->account($actor, null, 0, [
                    'code' => trim((string) ($row['code'] ?? '')),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'kind' => $expectedKind,
                    'parent_id' => null,
                    'postable' => true,
                    'active' => true,
                ]);
            }

            $createdByCode = [];
            $groups = array_filter($selectedExtras, fn (array $row) => ! ($row['postable'] ?? true));
            $leaves = array_filter($selectedExtras, fn (array $row) => (bool) ($row['postable'] ?? true));

            foreach (array_merge($groups, $leaves) as $row) {
                $code = trim((string) ($row['code'] ?? ''));
                $kind = (string) ($row['kind'] ?? '');
                $parentCode = $row['parent_code'] ?? null;
                $parentId = null;
                if ($parentCode !== null && $parentCode !== '') {
                    $parentId = $createdByCode[$parentCode]
                        ?? DB::table('accounting_accounts')->where('code', $parentCode)->where('kind', $kind)->where('postable', false)->where('active', true)->value('id');
                }
                $newId = $actions->account($actor, null, 0, [
                    'code' => $code,
                    'name' => trim((string) ($row['name'] ?? '')),
                    'kind' => $kind,
                    'parent_id' => $parentId ? (int) $parentId : null,
                    'postable' => (bool) ($row['postable'] ?? true),
                    'active' => true,
                ]);
                $createdByCode[$code] = $newId;
                $extraCount++;
                if (! empty($row['optional_purpose']) && array_key_exists($row['optional_purpose'], FinancialActivation::OPTIONAL_MAPPINGS)) {
                    $createdOptionalIds[$row['optional_purpose']] = $newId;
                }
            }
        });

        foreach ($createdIds as $purpose => $accountId) {
            $this->configuration['mappings'][$purpose] = (string) $accountId;
        }
        foreach ($createdOptionalIds as $optPurpose => $accountId) {
            $this->optionalMappings[$optPurpose] = (string) $accountId;
            if ($optPurpose === 'customer_advance') {
                $this->customerAdvanceAccount = (string) $accountId;
            }
        }

        $totalCreated = count($createdIds) + $extraCount;
        $this->resetValidation();
        $this->showBulkAccountsModal = false;
        session()->flash('financial_message', 'تم إنشاء '.$totalCreated.' حساباً في دليل الحسابات وتجهيز الربط في النموذج للمراجعة. راجعها ثم اضغط «حفظ إعداد التشغيل والربط».');
    }

    public function autofillMappings(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'setup_manage');
        $setup = app(FinancialActivation::class)->configuration(auth()->id());
        if ($setup['activated_at']) {
            FinancialActivation::fail('الإعداد مفعل وثابت.');
        }

        $accounts = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->orderBy('code')->get();
        $usedIds = [];
        foreach (array_keys(FinancialActivation::MAPPINGS) as $purpose) {
            $current = (int) ($this->configuration['mappings'][$purpose] ?? 0);
            if ($current > 0) {
                $usedIds[$current] = true;
            }
        }

        $filled = 0;
        foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
            if (! empty($this->configuration['mappings'][$purpose])) {
                continue;
            }
            [$starterCode, $starterName] = self::STARTER_ACCOUNTS[$purpose] ?? ['', $label];
            $candidates = $accounts->filter(fn ($acc) => $acc->kind === $kind && ! isset($usedIds[(int) $acc->id]));

            $match = $candidates->first(fn ($acc) => $starterCode !== '' && $acc->code === $starterCode)
                ?? $candidates->first(fn ($acc) => mb_stripos($acc->name, $starterName) !== false || mb_stripos($acc->name, $label) !== false);

            if ($match) {
                $this->configuration['mappings'][$purpose] = (string) $match->id;
                $usedIds[(int) $match->id] = true;
                $filled++;
            }
        }

        if (($this->optionalMappings['customer_advance'] ?? '') === '') {
            $advMatch = $accounts->first(fn ($acc) => $acc->kind === 'liability' && ! isset($usedIds[(int) $acc->id]) && ($acc->code === '2104' || mb_stripos($acc->name, 'دفعات مقدمة') !== false));
            if ($advMatch) {
                $this->optionalMappings['customer_advance'] = (string) $advMatch->id;
                $this->customerAdvanceAccount = (string) $advMatch->id;
            }
        }

        $this->resetValidation();
        session()->flash(
            'financial_message',
            $filled > 0
                ? 'تمت مطابقة وتعبئة '.$filled.' غرضاً في نموذج الربط الـ 15. راجع الحسابات المختارة ثم اضغط «حفظ إعداد التشغيل والربط».'
                : 'كل الأغراض المتاحة معبأة بالفعل أو لا توجد حسابات مطابقة بعد؛ أنشئ الدليل المختصر أولاً.'
        );
    }

    public function openPeriodModal(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'period_open');

        $goLive = ($this->configuration['go_live_date'] ?? '') ?: null;
        if (is_string($goLive) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $goLive)) {
            $year = substr($goLive, 0, 4);
            $this->newPeriod = [
                'name' => 'السنة المالية '.$year,
                'starts_on' => substr($goLive, 0, 7).'-01',
                'ends_on' => $year.'-12-31',
            ];
        } else {
            $this->newPeriod = ['name' => '', 'starts_on' => '', 'ends_on' => ''];
        }

        $this->resetValidation();
        $this->showPeriodModal = true;
    }

    public function createPeriod(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'period_open');
        app(AccountingActions::class)->period(auth()->id(), $this->newPeriod);
        $this->resetValidation();
        $this->showPeriodModal = false;
        session()->flash('financial_message', 'تم فتح الفترة المالية بنجاح.');
    }

    public function prepareEgyptVat(?string $type = null): void
    {
        app(AccountingAccess::class)->authorize(auth()->id(), 'tax_manage');
        if ($type !== null && in_array($type, ['sale', 'purchase'], true)) {
            $this->taxRule['transaction_type'] = $type;
        }
        $purchase = ($this->taxRule['transaction_type'] ?? 'sale') === 'purchase';
        $this->taxScope = 'all';
        $this->taxRule = array_replace($this->taxRule, [
            'transaction_type' => $purchase ? 'purchase' : 'sale',
            'product_id' => '',
            'code' => $purchase ? 'EG-VAT14-PURCHASE' : 'EG-VAT14-SALE',
            'name' => $purchase ? 'ضريبة القيمة المضافة للمشتريات — السعر العام 14%' : 'ضريبة القيمة المضافة للمبيعات — السعر العام 14%',
            'rate' => '14.00000',
            'treatment' => 'percentage',
            'tax_scale' => '5',
            'purchase_recoverable' => $purchase ? ($this->taxRule['purchase_recoverable'] ?? '') : '',
            'legal_reference' => 'المادة 3 من قانون 67 لسنة 2016، المعدلة بالقانون 149 لسنة 2026. السعر العام فقط، مع مراجعة خضوع الصنف والاستثناءات. https://portal.eta.gov.eg/sites/default/files/2026-08/law.no_.149.of_.2026.pdf',
        ]);
        $this->resetValidation();
    }

    public function addTaxRule(): void
    {
        $this->authorizeSetup();
        $data = $this->taxRule;
        if ($this->taxScope !== 'product') {
            $data['product_id'] = null;
        }
        if (($data['treatment'] ?? '') === 'exempt' && (($data['rate'] ?? '') === '')) {
            $data['rate'] = '0';
        }
        foreach (['product_id', 'ends_on', 'purchase_recoverable'] as $key) {
            $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }
        app(FinancialActivation::class)->addTaxRule(auth()->id(), $this->configurationVersion, $data);
        $this->reloadConfiguration();
        $this->reset('taxRule');
        $this->taxScope = 'all';
        $this->taxProductSearch = '';
        session()->flash('financial_message', 'تم حفظ القاعدة الضريبية. يلزم تسجيل توثيق المراجعة الضريبية قبل التفعيل.');
    }

    public function saveCustomerAdvanceMapping(): void
    {
        app(FinancialActivation::class)->saveOptionalMapping(auth()->id(), 'customer_advance', (int) $this->customerAdvanceAccount);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم حفظ حساب دفعات العملاء المقدمة دون تغيير إعدادات التفعيل التاريخية.');
    }

    public function validateTax(): void
    {
        $this->authorizeSetup();
        app(FinancialActivation::class)->validateTaxTreatment(auth()->id(), $this->configurationVersion, $this->taxReference);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم تسجيل مرجع التحقق الضريبي.');
    }

    public function openEndTaxModal(int $ruleId): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'tax_manage');
        $this->taxEnd = ['rule_id' => (string) $ruleId, 'ends_on' => '', 'reason' => '', 'withdraw' => false];
        $this->resetValidation();
        $this->showEndTaxModal = true;
    }

    public function endTaxRule(): void
    {
        $this->authorizeSetup();
        app(FinancialActivation::class)->endTaxRule(auth()->id(), $this->configurationVersion, (int) $this->taxEnd['rule_id'], $this->taxEnd['ends_on'] ?: null, $this->taxEnd['reason'], (bool) $this->taxEnd['withdraw']);
        $this->reloadConfiguration();
        $this->reset('taxEnd');
        $this->showEndTaxModal = false;
        session()->flash('financial_message', 'تم تسجيل انتهاء أو سحب القاعدة مع حفظ تاريخها ولقطات المستندات الصادرة.');
    }

    public function addLine(string $kind = 'gl'): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'opening_manage');
        abort_unless(in_array($kind, ['gl', 'ap', 'ar', 'cash', 'bank', 'inventory'], true), 422);
        abort_if(count($this->lines) >= 500, 422);

        $branches = app(AccountingAccess::class)->branches(auth()->id(), 'opening_entry');
        $defaultBranch = count($branches) === 1 ? (string) $branches[0]['id'] : '';

        $this->lines[] = [
            'kind' => $kind,
            'reference' => '',
            'branch_id' => $defaultBranch,
            'account_id' => '',
            'supplier_id' => '',
            'customer_id' => '',
            'warehouse_id' => '',
            'product_id' => '',
            'debit' => '0.00',
            'credit' => '0.00',
            'quantity' => '',
            'unit_cost' => '',
            'amount' => '',
            'serial_text' => '',
        ];
        $this->previewFingerprint = null;
    }

    public function removeLine(int $index): void
    {
        $this->authorizeSetup();
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->previewFingerprint = null;
    }

    public function template()
    {
        $this->authorizeSetup();
        $path = app(OpeningWorkbook::class)->template(auth()->id());

        return response()->download($path, 'financial-opening-template.xlsx')->setPrivate()->deleteFileAfterSend(true);
    }

    public function importPreview(): void
    {
        $this->authorizeSetup();
        $this->validate(['workbook' => 'required|file|mimes:xlsx|max:5120']);
        try {
            $this->lines = app(OpeningWorkbook::class)->read(auth()->id(), $this->workbook->getRealPath());
            foreach ($this->lines as &$line) {
                $line['serial_text'] = implode("\n", is_array($line['serials'] ?? null) ? $line['serials'] : json_decode($line['serials'] ?? '[]', true, 512, JSON_THROW_ON_ERROR));
            }
            unset($line);
        } catch (\UnexpectedValueException|\LengthException|\InvalidArgumentException $e) {
            FinancialActivation::fail('الملف لا يطابق القالب أو يحتوي بيانات غير صالحة. استخدم القالب الحالي دون صيغ.');
        } finally {
            $this->workbook->delete();
            $this->workbook = null;
        }
        $this->openingMode = 'excel';
        $this->noBalances = false;
        $this->previewOpening();
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->lines, $this->noBalances, $this->evidence, $this->configurationVersion], JSON_THROW_ON_ERROR));
    }

    public function previewOpening(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'opening_manage');
        if ($this->openingMode === 'zero') {
            $this->noBalances = true;
            $this->lines = [];
        }
        $lines = $this->cleanLines();
        $this->preview = app(OpeningBalances::class)->normalize(auth()->id(), $lines);
        $this->previewFingerprint = $this->fingerprint();
    }

    private function cleanLines(): array
    {
        return array_map(function ($line) {
            if (array_key_exists('serial_text', $line)) {
                $line['serials'] = preg_split('/\R/u', $line['serial_text'], -1, PREG_SPLIT_NO_EMPTY);
            }
            foreach (['account_id', 'supplier_id', 'customer_id', 'warehouse_id', 'product_id'] as $key) {
                $line[$key] = ($line[$key] ?? '') === '' ? null : $line[$key];
            }

            return $line;
        }, $this->lines);
    }

    public function saveOpening(): void
    {
        $this->authorizeSetup();
        if ($this->previewFingerprint === null || ! hash_equals($this->previewFingerprint, $this->fingerprint())) {
            FinancialActivation::fail('اعرض معاينة جديدة بعد أي تعديل وقبل حفظ حزمة الافتتاح.');
        }
        app(OpeningBalances::class)->save(auth()->id(), $this->configurationVersion, $this->creationKey, $this->cleanLines(), $this->noBalances, $this->evidence);
        $this->creationKey = (string) Str::uuid();
        $this->previewFingerprint = null;
        session()->flash('financial_message', 'حُفظت حزمة افتتاح جديدة دون ترحيل. راجع المصالحة ثم أرسلها للاعتماد المستقل.');
    }

    public function advance(int $batch, int $version, string $action): void
    {
        $this->authorizeSetup();
        app(OpeningBalances::class)->advance(auth()->id(), $batch, $version, $action);
        session()->flash('financial_message', 'تم تحديث حالة حزمة الافتتاح. لم يُفعّل الترحيل المالي بعد.');
    }

    public function activate(int $batch, int $version): void
    {
        $this->authorizeSetup();
        app(ActivateFinancialPosting::class)->execute(auth()->id(), $batch, $version);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم ترحيل الأرصدة المعتمدة وتفعيل الترحيل المالي.');
    }

    public function render()
    {
        $this->authorizeSetup();
        $actor = auth()->id();
        $setup = app(FinancialActivation::class)->configuration($actor);
        $a = app(AccountingAccess::class);
        $nav = $a->navigation($actor);

        $accounts = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->orderBy('code')->get();
        $allAccountsAreDemo = $accounts->isNotEmpty() && $accounts->every(fn ($acc) => str_starts_with((string) $acc->code, 'DEMO-'));
        $allAccounts = DB::table('accounting_accounts')->whereIn('id', DB::table('accounting_opening_lines')->whereNotNull('account_id')->select('account_id'))
            ->orWhere(fn ($q) => $q->where('active', true)->where('postable', true))
            ->get(['id', 'code', 'name', 'kind', 'active', 'postable']);
        $accountLabels = [];
        foreach ($allAccounts as $acc) {
            $accountLabels[(int) $acc->id] = $acc->code.' · '.$acc->name;
        }
        $parentAccounts = DB::table('accounting_accounts')->where('active', true)->where('postable', false)->orderBy('code')->get(['id', 'code', 'name', 'kind']);

        $taxRules = DB::table('accounting_tax_rules as r')
            ->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
            ->orderBy('r.transaction_type')
            ->orderBy('r.starts_on')
            ->get(['r.*', 'e.rule_id as ending_id', 'e.withdrawn', DB::raw('coalesce(e.ends_on,r.ends_on) as effective_end')]);

        $batch = DB::table('accounting_opening_batches')->orderByDesc('id')->first();
        $allLines = $batch ? app(OpeningBalances::class)->lines($batch->id) : [];

        $viewBranches = $a->branches($actor, 'opening_view');
        $branches = $a->branches($actor, 'opening_entry');
        $branchLabels = [];
        foreach (array_merge($viewBranches, $branches) as $branchRow) {
            $branchLabels[(int) $branchRow['id']] = $branchRow['name'];
        }
        $visibleBranches = array_column($viewBranches, 'id');

        $warehouseScopes = app(OrganizationApi::class)->inventoryScopes($actor);
        $visibleWarehouses = array_column(array_filter($warehouseScopes, fn ($scope) => in_array('M06.balance_view', $scope['permissions'], true)), 'warehouse_id');

        $savedLines = array_values(array_filter($allLines, fn ($line) => in_array($line['branch_id'], $visibleBranches, true)
            && ($line['kind'] !== 'inventory' || in_array($line['warehouse_id'], $visibleWarehouses, true))));

        $reconciliation = app(OpeningBalances::class)->reconciliation($savedLines, $batch?->no_balances ?? false);
        if (count($savedLines) !== count($allLines)) {
            $reconciliation['balanced'] = false;
            $reconciliation['errors'][] = 'بعض تفاصيل الافتتاح خارج صلاحية ونطاق عرضك. لا يمكن عرض المصالحة الكاملة.';
        }
        $previewReconciliation = $this->previewFingerprint ? app(OpeningBalances::class)->reconciliation($this->preview, $this->noBalances) : null;
        $blockers = app(FinancialActivation::class)->blockers();

        $savedMappings = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose')->all();
        $accountsById = $accounts->keyBy('id');

        $requiredMappedCount = 0;
        foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
            $mappedId = $savedMappings[$purpose] ?? null;
            $acc = $mappedId ? $accountsById->get($mappedId) : null;
            if ($acc && $acc->kind === $kind) {
                $requiredMappedCount++;
            }
        }

        $optionalMappedCount = 0;
        foreach (array_keys(FinancialActivation::OPTIONAL_MAPPINGS) as $purpose) {
            if (! empty($savedMappings[$purpose])) {
                $optionalMappedCount++;
            }
        }

        $requiredCounts = ['asset' => 0, 'liability' => 0, 'income' => 0, 'expense' => 0];
        foreach (FinancialActivation::MAPPINGS as [, $kind]) {
            if (isset($requiredCounts[$kind])) {
                $requiredCounts[$kind]++;
            }
        }
        $availableCounts = ['asset' => 0, 'liability' => 0, 'income' => 0, 'expense' => 0];
        foreach ($accounts as $acc) {
            if (isset($availableCounts[$acc->kind])) {
                $availableCounts[$acc->kind]++;
            }
        }
        $accountsByKind = [];
        $hasAccountShortage = false;
        foreach ($requiredCounts as $kind => $requiredCount) {
            $have = $availableCounts[$kind];
            $accountsByKind[$kind] = ['required' => $requiredCount, 'available' => $have, 'missing' => max(0, $requiredCount - $have)];
            if ($have < $requiredCount) {
                $hasAccountShortage = true;
            }
        }

        $selectedAccountCounts = [];
        foreach (array_keys(FinancialActivation::MAPPINGS) as $purpose) {
            $val = (string) ($this->configuration['mappings'][$purpose] ?? '');
            if ($val !== '') {
                $selectedAccountCounts[$val] = ($selectedAccountCounts[$val] ?? 0) + 1;
            }
        }
        $duplicateMappingPurposes = [];
        foreach (array_keys(FinancialActivation::MAPPINGS) as $purpose) {
            $val = (string) ($this->configuration['mappings'][$purpose] ?? '');
            if ($val !== '' && ($selectedAccountCounts[$val] ?? 0) > 1) {
                $duplicateMappingPurposes[] = $purpose;
            }
        }

        $draftGoLive = ($this->configuration['go_live_date'] ?? '') ?: null;
        $savedGoLive = $setup['go_live_date'] ?: null;
        $coveringPeriod = $draftGoLive ? DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $draftGoLive)->where('ends_on', '>=', $draftGoLive)->first() : null;
        $savedCoveringPeriod = $savedGoLive ? DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $savedGoLive)->where('ends_on', '>=', $savedGoLive)->first() : null;

        $hasSaleRuleAtGoLive = $this->hasEffectiveRule('sale', $savedGoLive);
        $hasPurchaseRuleAtGoLive = $this->hasEffectiveRule('purchase', $savedGoLive);

        $stepStates = $this->computeStepStates(
            $setup,
            $batch,
            $requiredMappedCount,
            $savedCoveringPeriod !== null,
            $hasSaleRuleAtGoLive,
            $hasPurchaseRuleAtGoLive,
            $reconciliation['balanced']
        );
        $completedStepsCount = count(array_filter($stepStates, fn (array $step) => $step['done']));
        $firstIncompleteStep = collect($stepStates)->search(fn (array $step) => ! $step['done']);
        $firstIncompleteStep = $firstIncompleteStep === false ? null : (int) $firstIncompleteStep;

        $draftTotals = ['glCount' => 0, 'detailCount' => 0, 'debit' => '0.00', 'credit' => '0.00', 'diff' => '0.00'];
        foreach ($this->lines as $line) {
            if (($line['kind'] ?? 'gl') === 'gl') {
                $draftTotals['glCount']++;
                $dr = trim((string) ($line['debit'] ?? '0'));
                $cr = trim((string) ($line['credit'] ?? '0'));
                if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D', $dr)) {
                    $draftTotals['debit'] = bcadd($draftTotals['debit'], $dr, 2);
                }
                if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/D', $cr)) {
                    $draftTotals['credit'] = bcadd($draftTotals['credit'], $cr, 2);
                }
            } else {
                $draftTotals['detailCount']++;
            }
        }
        $draftTotals['diff'] = bcsub($draftTotals['debit'], $draftTotals['credit'], 2);

        [$taxProducts, $canLookupProducts, $productLabels, $productTrackings] = $this->loadProductLookups($actor, $taxRules, $savedLines);
        [$inventoryProducts, $canLookupInventoryProducts] = $this->loadInventoryProductLookups($actor, $productLabels, $productTrackings);
        [$suppliers, $canLookupSuppliers, $supplierLabels] = $this->loadSupplierLookups($actor, $savedLines);
        [$customers, $canLookupCustomers, $customerLabels] = $this->loadCustomerLookups($actor, $savedLines);
        [$warehousesByBranch, $warehouseLabels] = $this->loadWarehouseLookups($visibleWarehouses, $savedLines);

        $canViewUsers = false;
        try {
            app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, 'M01.user_view'));
            $canViewUsers = true;
        } catch (\Throwable) {
            $canViewUsers = false;
        }

        $canSelfApproveAccounting = app(OrganizationApi::class)->allowsSelfApproval($actor, 'accounting');

        $existingCodes = DB::table('accounting_accounts')->pluck('code')->all();
        $missingExtraStarterCount = 0;
        foreach (self::EXTRA_STARTER_ACCOUNTS as $extra) {
            if (! in_array($extra['code'], $existingCodes, true)) {
                $missingExtraStarterCount++;
            }
        }

        $holdersMap = [];
        try {
            $holdersMap = app(OrganizationApi::class)->permissionHolders($actor, [
                'M11.setup_manage',
                'M11.period_open',
                'M11.account_manage',
                'M11.tax_manage',
                'M11.opening_manage',
                'M11.opening_approve',
                'M11.financial_activate',
            ]);
        } catch (\Throwable) {
            $holdersMap = [];
        }

        $namesFor = static fn (string $code, array $excludeIds = []): array => array_values(array_map(
            fn (array $u) => $u['name'],
            array_filter($holdersMap[$code]['users'] ?? [], fn (array $u) => ! in_array($u['id'], $excludeIds, true))
        ));

        $openingBatchDone = (bool) $setup['activated_at']
            || ($batch && (int) $batch->configuration_version === (int) $setup['version'] && $batch->status === 'approved' && $reconciliation['balanced']);
        $needsSecondApprover = ! $canSelfApproveAccounting
            && $batch
            && (int) $batch->configuration_version === (int) $setup['version']
            && $batch->status === 'submitted';
        $excludedPreparers = $needsSecondApprover
            ? array_values(array_filter([(int) $batch->created_by, (int) ($setup['configured_by'] ?? 0)]))
            : [];

        $readinessBoard = [
            [
                'label' => 'تاريخ التشغيل الفعلي للشركة',
                'detail' => $setup['go_live_date'] ? 'التاريخ المعتمد: '.$setup['go_live_date'] : 'لم يُحدد تاريخ التشغيل بعد.',
                'done' => (bool) $setup['go_live_date'],
                'responsible_title' => $holdersMap['M11.setup_manage']['label'] ?? 'إعداد التفعيل والربط المحاسبي',
                'permission_code' => 'M11.setup_manage',
                'holders' => $namesFor('M11.setup_manage'),
                'note' => null,
                'step' => 1,
            ],
            [
                'label' => 'فتح فترة محاسبية تغطي تاريخ التشغيل',
                'detail' => $savedCoveringPeriod ? $savedCoveringPeriod->name.' ('.$savedCoveringPeriod->starts_on.' — '.$savedCoveringPeriod->ends_on.')' : 'لا توجد فترة مفتوحة تشمل تاريخ التشغيل.',
                'done' => $savedCoveringPeriod !== null,
                'responsible_title' => $holdersMap['M11.period_open']['label'] ?? 'فتح الفترات المالية',
                'permission_code' => 'M11.period_open',
                'holders' => $namesFor('M11.period_open'),
                'note' => null,
                'step' => 1,
            ],
            [
                'label' => 'ربط الحسابات الإلزامية الـ 15 في الدليل',
                'detail' => 'تم ربط '.$requiredMappedCount.' من أصل 15 حساباً مستقلاً.',
                'done' => $requiredMappedCount === count(FinancialActivation::MAPPINGS),
                'responsible_title' => ($holdersMap['M11.setup_manage']['label'] ?? 'إعداد التفعيل والربط').' / '.($holdersMap['M11.account_manage']['label'] ?? 'إدارة الدليل'),
                'permission_code' => 'M11.setup_manage',
                'holders' => array_values(array_unique(array_merge($namesFor('M11.setup_manage'), $namesFor('M11.account_manage')))),
                'note' => null,
                'step' => 1,
            ],
            [
                'label' => 'توفر قواعد الضريبة وتوثيق المراجعة الضريبية',
                'detail' => ($hasSaleRuleAtGoLive && $hasPurchaseRuleAtGoLive && $setup['tax_validated'])
                    ? ($setup['tax_validation_reference'] ?: 'موثق ومستوفٍ لقاعدة البيع والشراء')
                    : (! $hasSaleRuleAtGoLive || ! $hasPurchaseRuleAtGoLive ? 'تنقص قاعدة ضريبة سارية للمبيعات أو المشتريات عند تاريخ التشغيل.' : 'يلزم توثيق مرجع التحقق الضريبي للشركة.'),
                'done' => $hasSaleRuleAtGoLive && $hasPurchaseRuleAtGoLive && (bool) $setup['tax_validated'],
                'responsible_title' => $holdersMap['M11.tax_manage']['label'] ?? 'إعداد القواعد الضريبية والتحقق منها',
                'permission_code' => 'M11.tax_manage',
                'holders' => $namesFor('M11.tax_manage'),
                'note' => null,
                'step' => 2,
            ],
            [
                'label' => 'إعداد واعتماد حزمة الأرصدة الافتتاحية',
                'detail' => match (true) {
                    (bool) $setup['activated_at'] => 'مُرحّلة ومُفعّلة.',
                    $batch && (int) $batch->configuration_version === (int) $setup['version'] && $batch->status === 'approved' && $reconciliation['balanced'] => 'حزمة #'.$batch->id.' معتمدة ومتزنة.',
                    $batch && (int) $batch->configuration_version === (int) $setup['version'] && $batch->status === 'submitted' => 'حزمة #'.$batch->id.' مرسلة وتنتظر الاعتماد'.($canSelfApproveAccounting ? '.' : ' من مراجع مستقل.'),
                    $batch && (int) $batch->configuration_version !== (int) $setup['version'] => 'توجد حزمة قديمة لا تطابق نسخة الإعداد الحالية.',
                    default => 'لم تُعدّ أو تُعتمد حزمة أرصدة بالنسخة الحالية بعد.',
                },
                'done' => $openingBatchDone,
                'responsible_title' => $needsSecondApprover
                    ? ($holdersMap['M11.opening_approve']['label'] ?? 'اعتماد الأرصدة الافتتاحية المستقل')
                    : ($holdersMap['M11.opening_manage']['label'] ?? 'إعداد الأرصدة الافتتاحية').' / '.($holdersMap['M11.opening_approve']['label'] ?? 'اعتماد الأرصدة'),
                'permission_code' => $needsSecondApprover ? 'M11.opening_approve' : 'M11.opening_manage',
                'holders' => $needsSecondApprover
                    ? $namesFor('M11.opening_approve', $excludedPreparers)
                    : array_values(array_unique(array_merge($namesFor('M11.opening_manage'), $namesFor('M11.opening_approve')))),
                'note' => $needsSecondApprover ? 'يلزم مراجع مستقل غير مُعدّ الإعداد أو الحزمة (أو تفعيل سياسة الاعتماد الذاتي لدوره).' : null,
                'step' => 3,
            ],
            [
                'label' => 'تفعيل الترحيل المالي النهائي',
                'detail' => $setup['activated_at'] ? 'مُفعّل ويعمل.' : 'بانتظار اكتمال البنود الخمسة أعلاه وتأكيد التفعيل.',
                'done' => (bool) $setup['activated_at'],
                'responsible_title' => $holdersMap['M11.financial_activate']['label'] ?? 'ترحيل الافتتاح وتفعيل الترحيل المالي',
                'permission_code' => 'M11.financial_activate',
                'holders' => $namesFor('M11.financial_activate'),
                'note' => null,
                'step' => 4,
            ],
        ];

        return view('accounting.setup', compact(
            'setup',
            'a',
            'nav',
            'accounts',
            'parentAccounts',
            'taxRules',
            'batch',
            'savedLines',
            'reconciliation',
            'previewReconciliation',
            'blockers',
            'branches',
            'branchLabels',
            'accountLabels',
            'savedMappings',
            'requiredMappedCount',
            'optionalMappedCount',
            'accountsByKind',
            'hasAccountShortage',
            'duplicateMappingPurposes',
            'coveringPeriod',
            'savedCoveringPeriod',
            'hasSaleRuleAtGoLive',
            'hasPurchaseRuleAtGoLive',
            'stepStates',
            'completedStepsCount',
            'firstIncompleteStep',
            'draftTotals',
            'taxProducts',
            'canLookupProducts',
            'inventoryProducts',
            'canLookupInventoryProducts',
            'suppliers',
            'canLookupSuppliers',
            'supplierLabels',
            'customers',
            'canLookupCustomers',
            'customerLabels',
            'warehousesByBranch',
            'warehouseLabels',
            'productLabels',
            'productTrackings',
            'canViewUsers',
            'canSelfApproveAccounting',
            'readinessBoard',
            'missingExtraStarterCount',
            'allAccountsAreDemo'
        ))->layout('layouts::app', ['title' => 'إعداد التفعيل المالي']);
    }

    private function resolveModalPurpose(string $target): ?array
    {
        if (str_starts_with($target, 'mapping:')) {
            $key = substr($target, strlen('mapping:'));
            if (! isset(FinancialActivation::MAPPINGS[$key])) {
                return null;
            }
            [$label, $kind] = FinancialActivation::MAPPINGS[$key];

            return [
                'group' => 'mapping',
                'key' => $key,
                'label' => $label,
                'kind' => $kind,
                'code' => self::STARTER_ACCOUNTS[$key][0] ?? strtoupper($key),
            ];
        }

        if (str_starts_with($target, 'optional:')) {
            $key = substr($target, strlen('optional:'));
            if (! isset(FinancialActivation::OPTIONAL_MAPPINGS[$key])) {
                return null;
            }
            [$label, $kind] = FinancialActivation::OPTIONAL_MAPPINGS[$key];
            $defaultCodes = [
                'customer_advance' => '2104',
                'maintenance_labor' => '5106',
                'maintenance_labor_accrual' => '2105',
                'service_contract_deferred' => '2106',
            ];

            return [
                'group' => 'optional',
                'key' => $key,
                'label' => $label,
                'kind' => $kind,
                'code' => $defaultCodes[$key] ?? strtoupper($key),
            ];
        }

        return null;
    }

    private function suggestAvailableAccountCode(string $baseCode): string
    {
        $used = DB::table('accounting_accounts')->pluck('code')->all();
        if (! in_array($baseCode, $used, true)) {
            return $baseCode;
        }
        $i = 1;
        while (in_array($baseCode.'-'.$i, $used, true)) {
            $i++;
        }

        return $baseCode.'-'.$i;
    }

    private function hasEffectiveRule(string $type, ?string $date): bool
    {
        $query = DB::table('accounting_tax_rules as r')
            ->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
            ->where('r.transaction_type', $type)
            ->whereRaw('coalesce(e.withdrawn,false)=false');

        if ($date !== null) {
            $query->where('r.starts_on', '<=', $date)
                ->whereRaw('(coalesce(e.ends_on,r.ends_on) IS NULL OR coalesce(e.ends_on,r.ends_on)>=?::date)', [$date]);
        }

        return $query->exists();
    }

    private function computeStepStates(
        array $setup,
        ?object $batch,
        ?int $requiredMappedCount = null,
        ?bool $hasCoveringPeriod = null,
        ?bool $hasSaleRule = null,
        ?bool $hasPurchaseRule = null,
        ?bool $batchBalanced = null
    ): array {
        $goLive = $setup['go_live_date'] ?: null;
        if ($requiredMappedCount === null) {
            $savedMappings = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose')->all();
            $accounts = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->get(['id', 'kind'])->keyBy('id');
            $requiredMappedCount = 0;
            foreach (FinancialActivation::MAPPINGS as $purpose => [, $kind]) {
                $acc = isset($savedMappings[$purpose]) ? $accounts->get($savedMappings[$purpose]) : null;
                if ($acc && $acc->kind === $kind) {
                    $requiredMappedCount++;
                }
            }
        }
        if ($hasCoveringPeriod === null) {
            $hasCoveringPeriod = $goLive !== null && DB::table('accounting_periods')
                ->where('status', 'open')->where('starts_on', '<=', $goLive)->where('ends_on', '>=', $goLive)->exists();
        }
        if ($hasSaleRule === null) {
            $hasSaleRule = $this->hasEffectiveRule('sale', $goLive);
        }
        if ($hasPurchaseRule === null) {
            $hasPurchaseRule = $this->hasEffectiveRule('purchase', $goLive);
        }

        $totalRequired = count(FinancialActivation::MAPPINGS);
        $step1Done = $goLive !== null && $hasCoveringPeriod && $requiredMappedCount === $totalRequired;
        $step2Done = (bool) $setup['tax_validated'] && $hasSaleRule && $hasPurchaseRule;

        $batchMatchesVersion = $batch !== null && (int) $batch->configuration_version === (int) $setup['version'];
        $step3Done = (bool) $setup['activated_at']
            || ($batchMatchesVersion && $batch->status === 'posted')
            || ($batchMatchesVersion && $batch->status === 'approved' && ($batchBalanced ?? true));
        $step4Done = (bool) $setup['activated_at'];

        $step1Badge = $step1Done ? 'مكتمل' : ($goLive === null ? 'ينقص التاريخ والحسابات' : ($requiredMappedCount < $totalRequired ? 'متبقي '.($totalRequired - $requiredMappedCount).' حساباً' : 'تنقص فترة مفتوحة'));
        $step2Badge = $step2Done ? 'مكتمل' : (! $hasSaleRule && ! $hasPurchaseRule ? 'أضف قواعد الضريبة' : (! $setup['tax_validated'] ? 'بانتظار التوثيق' : 'أكمل قواعد السريان'));
        $step3Badge = match (true) {
            $step4Done => 'مُرحّل',
            $batch === null => 'لم يُعدّ بعد',
            ! $batchMatchesVersion => 'يحتاج تحديثاً',
            $batch->status === 'approved' => 'معتمد وجاهز',
            $batch->status === 'submitted' => 'بانتظار مراجع آخر',
            default => 'مسودة غير مرسلة',
        };
        $step4Badge = $step4Done ? 'مُفعّل' : (($step1Done && $step2Done && $step3Done) ? 'جاهز للتفعيل' : 'ينتظر الخطوات السابقة');

        return [
            1 => ['title' => 'التشغيل والحسابات', 'desc' => 'تاريخ البدء والفترة المالية وربط الدليل', 'done' => $step1Done, 'badge' => $step1Badge, 'tone' => $step1Done ? 'success' : 'neutral'],
            2 => ['title' => 'الضرائب', 'desc' => 'قواعد البيع والشراء وتوثيق المراجعة', 'done' => $step2Done, 'badge' => $step2Badge, 'tone' => $step2Done ? 'success' : 'neutral'],
            3 => ['title' => 'الأرصدة الافتتاحية', 'desc' => 'الإدخال أو الإقرار ثم الاعتماد المستقل', 'done' => $step3Done, 'badge' => $step3Badge, 'tone' => $step3Done ? 'success' : ($batch?->status === 'submitted' ? 'warning' : 'neutral')],
            4 => ['title' => 'المراجعة والتفعيل', 'desc' => 'التحقق النهائي وفتح الترحيل المالي', 'done' => $step4Done, 'badge' => $step4Badge, 'tone' => $step4Done ? 'success' : (($step1Done && $step2Done && $step3Done) ? 'info' : 'neutral')],
        ];
    }

    private function loadProductLookups(int $actor, object $taxRules, array $savedLines): array
    {
        $products = [];
        $canLookup = false;
        $labels = [];
        $trackings = [];
        $api = app(ProductsApi::class);

        try {
            $products = $api->listMasters(new ListMastersRequest($actor, 'products', trim($this->taxProductSearch), true, 1, 30))->items;
            $canLookup = true;
            foreach ($products as $product) {
                $labels[(int) $product['id']] = $product['sku'].' · '.$product['name'];
                $trackings[(int) $product['id']] = $product['tracking'];
            }
        } catch (\Throwable) {
            $canLookup = false;
        }

        $hydrateIds = [];
        if (! empty($this->taxRule['product_id'])) {
            $hydrateIds[] = (int) $this->taxRule['product_id'];
        }
        foreach ($taxRules as $rule) {
            if (! empty($rule->product_id)) {
                $hydrateIds[] = (int) $rule->product_id;
            }
        }
        foreach ($this->lines as $line) {
            if (! empty($line['product_id'])) {
                $hydrateIds[] = (int) $line['product_id'];
            }
        }
        foreach ($savedLines as $line) {
            if (! empty($line['product_id'])) {
                $hydrateIds[] = (int) $line['product_id'];
            }
        }

        foreach (array_unique($hydrateIds) as $productId) {
            if (isset($labels[$productId]) || $productId < 1) {
                continue;
            }
            try {
                $product = $api->getProduct(new GetProductRequest($actor, $productId))->product;
                $labels[$productId] = $product['sku'].' · '.$product['name'];
                $trackings[$productId] = $product['tracking'];
            } catch (\Throwable) {
                // Keep graceful fallback display when product lookup permission is absent or product deleted.
            }
        }

        return [$products, $canLookup, $labels, $trackings];
    }

    private function loadInventoryProductLookups(int $actor, array &$productLabels, array &$productTrackings): array
    {
        $items = [];
        $canLookup = false;
        try {
            $items = app(ProductsApi::class)->inventoryProducts($actor, trim($this->inventoryProductSearch));
            $canLookup = true;
            foreach ($items as $item) {
                $productLabels[(int) $item['id']] = $item['sku'].' · '.$item['name'].($item['unit_name'] !== '' ? ' ('.$item['unit_name'].')' : '');
                $productTrackings[(int) $item['id']] = $item['tracking'];
            }
        } catch (\Throwable) {
            $canLookup = false;
        }

        return [$items, $canLookup];
    }

    private function loadSupplierLookups(int $actor, array $savedLines): array
    {
        $suppliers = [];
        $canLookup = false;
        $labels = [];
        $api = app(SuppliersApi::class);

        try {
            $suppliers = $api->searchSuppliers(new SearchSuppliersRequest($actor, trim($this->supplierSearch), 30));
            $canLookup = true;
            foreach ($suppliers as $supplier) {
                $labels[$supplier->id] = $supplier->code.' · '.$supplier->name;
            }
        } catch (\Throwable) {
            $canLookup = false;
        }

        $hydrateIds = [];
        foreach ($this->lines as $line) {
            if (! empty($line['supplier_id'])) {
                $hydrateIds[] = (int) $line['supplier_id'];
            }
        }
        foreach ($savedLines as $line) {
            if (! empty($line['supplier_id'])) {
                $hydrateIds[] = (int) $line['supplier_id'];
            }
        }
        foreach (array_unique($hydrateIds) as $supplierId) {
            if (isset($labels[$supplierId]) || $supplierId < 1) {
                continue;
            }
            try {
                $supplier = $api->getSupplier(new GetSupplierRequest($actor, $supplierId, false));
                $labels[$supplierId] = $supplier->code.' · '.$supplier->name;
            } catch (\Throwable) {
                // Keep ID fallback when supplier view permission is absent.
            }
        }

        return [$suppliers, $canLookup, $labels];
    }

    private function loadCustomerLookups(int $actor, array $savedLines): array
    {
        $customers = [];
        $canLookup = false;
        $labels = [];
        $api = app(CustomersApi::class);

        try {
            $customers = $api->searchCustomers(new SearchCustomersRequest($actor, trim($this->customerSearch), 30));
            $canLookup = true;
            foreach ($customers as $customer) {
                $labels[$customer->id] = $customer->code.' · '.$customer->name;
            }
        } catch (\Throwable) {
            $canLookup = false;
        }

        $hydrateIds = [];
        foreach ($this->lines as $line) {
            if (! empty($line['customer_id'])) {
                $hydrateIds[] = (int) $line['customer_id'];
            }
        }
        foreach ($savedLines as $line) {
            if (! empty($line['customer_id'])) {
                $hydrateIds[] = (int) $line['customer_id'];
            }
        }
        foreach (array_unique($hydrateIds) as $customerId) {
            if (isset($labels[$customerId]) || $customerId < 1) {
                continue;
            }
            try {
                $customer = $api->getCustomer(new GetCustomerRequest($actor, $customerId, false));
                $labels[$customerId] = $customer->code.' · '.$customer->name;
            } catch (\Throwable) {
                // Keep ID fallback when customer view permission is absent.
            }
        }

        return [$customers, $canLookup, $labels];
    }

    private function loadWarehouseLookups(array $visibleWarehouses, array $savedLines): array
    {
        $byBranch = [];
        $labels = [];
        $api = app(InventoryApi::class);

        $allIds = $visibleWarehouses;
        foreach ($this->lines as $line) {
            if (! empty($line['warehouse_id'])) {
                $allIds[] = (int) $line['warehouse_id'];
            }
        }
        foreach ($savedLines as $line) {
            if (! empty($line['warehouse_id'])) {
                $allIds[] = (int) $line['warehouse_id'];
            }
        }

        foreach (array_unique(array_filter($allIds, fn ($id) => (int) $id > 0)) as $wid) {
            $wid = (int) $wid;
            try {
                $wh = $api->warehouse($wid);
                if (! $wh) {
                    continue;
                }
                $labels[$wid] = ($wh['code'] ? $wh['code'].' · ' : '').$wh['name'];
                if (in_array($wid, $visibleWarehouses, true) && $wh['active']) {
                    $byBranch[(int) $wh['branch_id']][] = $wh;
                }
            } catch (\Throwable) {
                // Ignore inaccessible warehouse.
            }
        }

        return [$byBranch, $labels];
    }
}

<?php

namespace App\Modules\Accounting\Presentation;

use App\Application\ActivateFinancialPosting;
use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Application\OpeningWorkbook;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

final class FinancialSetupPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $configurationVersion = 0;

    #[Locked]
    public string $creationKey = '';

    #[Locked]
    public array $preview = [];

    #[Locked]
    public ?string $previewFingerprint = null;

    public array $configuration = ['go_live_date' => null, 'mappings' => []];

    public array $taxRule = ['code' => '', 'name' => '', 'transaction_type' => 'sale', 'product_id' => '', 'rate' => '', 'starts_on' => '', 'ends_on' => '', 'treatment' => 'percentage', 'tax_scale' => '5', 'legal_reference' => '', 'purchase_recoverable' => ''];

    public array $taxEnd = ['rule_id' => '', 'ends_on' => '', 'reason' => '', 'withdraw' => false];

    public string $taxReference = '';

    public string $customerAdvanceAccount = '';

    public array $maintenanceMappings = ['maintenance_labor' => '', 'maintenance_labor_accrual' => ''];

    public function saveMaintenanceMapping(string $purpose): void
    {
        abort_unless(array_key_exists($purpose, $this->maintenanceMappings), 422);
        app(FinancialActivation::class)->saveOptionalMapping(auth()->id(), $purpose, (int) $this->maintenanceMappings[$purpose]);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم ربط حساب تكلفة الصيانة المعتمد.');
    }

    public array $lines = [];

    public bool $noBalances = false;

    public string $evidence = '';

    public $workbook;

    public function mount(): void
    {
        $this->reloadConfiguration();
        $this->creationKey = (string) Str::uuid();
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
        $this->configuration = ['go_live_date' => $setup['go_live_date'], 'mappings' => DB::table('accounting_account_mappings')->pluck('account_id', 'purpose')->all()];
        $this->customerAdvanceAccount = (string) ($this->configuration['mappings']['customer_advance'] ?? '');
        foreach (array_keys($this->maintenanceMappings) as $purpose) {
            $this->maintenanceMappings[$purpose] = (string) ($this->configuration['mappings'][$purpose] ?? '');
        }
        $this->taxReference = $setup['tax_validation_reference'] ?? '';
        $this->preview = [];
        $this->previewFingerprint = null;
    }

    public function saveConfiguration(): void
    {
        $this->authorizeSetup();
        app(FinancialActivation::class)->save(auth()->id(), $this->configurationVersion, $this->configuration);
        $this->reloadConfiguration();
        session()->flash('financial_message', 'تم حفظ الإعداد. أي حزمة افتتاح سابقة تحتاج إعدادًا واعتمادًا جديدين إذا تغيرت النسخة.');
    }

    public function prepareEgyptVat(): void
    {
        app(AccountingAccess::class)->authorize(auth()->id(), 'tax_manage');
        $purchase = ($this->taxRule['transaction_type'] ?? 'sale') === 'purchase';
        $this->taxRule = array_replace($this->taxRule, [
            'transaction_type' => $purchase ? 'purchase' : 'sale',
            'code' => $purchase ? 'EG-VAT14-PURCHASE' : 'EG-VAT14-SALE',
            'name' => 'ضريبة القيمة المضافة، السعر العام المصري',
            'rate' => '14.00000',
            'treatment' => 'percentage',
            'tax_scale' => '5',
            'legal_reference' => 'المادة 3 من قانون 67 لسنة 2016، المعدلة بالقانون 149 لسنة 2026. السعر العام فقط، مع مراجعة خضوع الصنف والاستثناءات. https://portal.eta.gov.eg/sites/default/files/2026-08/law.no_.149.of_.2026.pdf',
        ]);
        $this->resetValidation();
    }

    public function addTaxRule(): void
    {
        $this->authorizeSetup();
        $data = $this->taxRule;
        foreach (['product_id', 'ends_on', 'purchase_recoverable'] as $key) {
            $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }
        app(FinancialActivation::class)->addTaxRule(auth()->id(), $this->configurationVersion, $data);
        $this->reloadConfiguration();
        $this->reset('taxRule');
        session()->flash('financial_message', 'تم حفظ القاعدة. يلزم التحقق من المعاملة الضريبية الفعلية قبل الإصدار أو التفعيل.');
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

    public function endTaxRule(): void
    {
        $this->authorizeSetup();
        app(FinancialActivation::class)->endTaxRule(auth()->id(), $this->configurationVersion, (int) $this->taxEnd['rule_id'], $this->taxEnd['ends_on'] ?: null, $this->taxEnd['reason'], (bool) $this->taxEnd['withdraw']);
        $this->reloadConfiguration();
        $this->reset('taxEnd');
        session()->flash('financial_message', 'تم تسجيل انتهاء أو سحب القاعدة مع حفظ تاريخها ولقطات المستندات الصادرة.');
    }

    public function addLine(): void
    {
        $this->authorizeSetup();
        app(AccountingAccess::class)->authorize(auth()->id(), 'opening_manage');
        abort_if(count($this->lines) >= 500, 422);
        $this->lines[] = ['kind' => 'gl', 'reference' => '', 'branch_id' => '', 'account_id' => '', 'supplier_id' => '', 'customer_id' => '', 'warehouse_id' => '', 'product_id' => '', 'debit' => '0.00', 'credit' => '0.00', 'quantity' => '', 'unit_cost' => '', 'amount' => '', 'serial_text' => ''];
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
        $setup = app(FinancialActivation::class)->configuration(auth()->id());
        $a = app(AccountingAccess::class);
        $nav = $a->navigation(auth()->id());
        $accounts = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->orderBy('code')->get();
        $taxRules = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')->orderBy('r.transaction_type')->orderBy('r.starts_on')->get(['r.*', 'e.withdrawn', DB::raw('coalesce(e.ends_on,r.ends_on) as effective_end')]);
        $batch = DB::table('accounting_opening_batches')->orderByDesc('id')->first();
        $allLines = $batch ? app(OpeningBalances::class)->lines($batch->id) : [];
        $visibleBranches = array_column($a->branches(auth()->id(), 'opening_view'), 'id');
        $warehouseScopes = app(OrganizationApi::class)->inventoryScopes(auth()->id());
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
        $branches = $a->branches(auth()->id(), 'opening_entry');

        return view('accounting.setup', compact('setup', 'a', 'nav', 'accounts', 'taxRules', 'batch', 'savedLines', 'reconciliation', 'previewReconciliation', 'blockers', 'branches'))
            ->layout('layouts::app', ['title' => 'إعداد التفعيل المالي']);
    }
}

<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\FinancialControlAccounts;
use App\Modules\Accounting\Application\PartnerAccounts;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PartnerAccountsPage extends Component
{
    public string $filterBranch = '';

    public string $filterPartner = '';

    public string $from = '';

    public string $to = '';

    public ?int $editPartnerId = null;

    #[Locked]
    public int $editPartnerVersion = 0;

    public array $partnerForm = [
        'code' => '',
        'name' => '',
        'ownership_percent' => '',
        'current_account_id' => '',
        'active' => true,
        'notes' => '',
    ];

    public array $movementForm = [
        'partner_id' => '',
        'branch_id' => '',
        'kind' => 'contribution',
        'movement_date' => '',
        'amount' => '',
        'reference' => '',
        'notes' => '',
        'treasury_account_id' => '',
        'expense_account_id' => '',
    ];

    #[Locked]
    public string $creationKey = '';

    public bool $showReverseModal = false;

    public ?int $reverseMovementId = null;

    public string $reverseDate = '';

    public string $reverseReason = '';

    #[Locked]
    public string $reverseKey = '';

    public function mount(): void
    {
        app(AccountingAccess::class)->authorize((int) auth()->id(), 'partner_view');
        $today = now('Africa/Cairo')->toDateString();
        $this->movementForm['movement_date'] = $today;
        $this->reverseDate = $today;
        $this->creationKey = (string) Str::uuid();
        $this->reverseKey = (string) Str::uuid();
        $branches = app(AccountingAccess::class)->branches((int) auth()->id(), 'partner_manage');
        if (count($branches) === 1) {
            $this->movementForm['branch_id'] = (string) $branches[0]['id'];
        }
    }

    public function selectPartner(?int $id = null): void
    {
        $this->resetValidation();
        if (! $id) {
            $this->editPartnerId = null;
            $this->editPartnerVersion = 0;
            $this->partnerForm = ['code' => '', 'name' => '', 'ownership_percent' => '', 'current_account_id' => '', 'active' => true, 'notes' => ''];

            return;
        }
        $p = DB::table('accounting_partners')->find($id);
        abort_unless($p, 404);
        $this->editPartnerId = (int) $p->id;
        $this->editPartnerVersion = (int) $p->version;
        $this->partnerForm = [
            'code' => $p->code,
            'name' => $p->name,
            'ownership_percent' => $p->ownership_percent ?? '',
            'current_account_id' => (string) $p->current_account_id,
            'active' => (bool) $p->active,
            'notes' => $p->notes ?? '',
        ];
    }

    public function autoCreatePartnerAccount(): void
    {
        $accountId = app(PartnerAccounts::class)->createPartnerEquityAccount((int) auth()->id(), (string) ($this->partnerForm['name'] ?? ''));
        $this->partnerForm['current_account_id'] = (string) $accountId;
        session()->flash('partner_message', 'تم إنشاء حساب جاري للشريك في دليل الحسابات وتحديده تلقائياً.');
    }

    public function savePartner(): void
    {
        app(PartnerAccounts::class)->savePartner((int) auth()->id(), $this->editPartnerId, $this->editPartnerVersion, $this->partnerForm);
        $this->selectPartner(null);
        session()->flash('partner_message', 'تم حفظ بيانات الشريك وحسابه الجاري بنجاح.');
    }

    public function recordMovement(): void
    {
        app(PartnerAccounts::class)->recordMovement((int) auth()->id(), $this->movementForm + ['creation_key' => $this->creationKey]);
        $this->creationKey = (string) Str::uuid();
        $keepBranch = $this->movementForm['branch_id'];
        $keepPartner = $this->movementForm['partner_id'];
        $this->movementForm = [
            'partner_id' => $keepPartner,
            'branch_id' => $keepBranch,
            'kind' => 'contribution',
            'movement_date' => now('Africa/Cairo')->toDateString(),
            'amount' => '',
            'reference' => '',
            'notes' => '',
            'treasury_account_id' => '',
            'expense_account_id' => '',
        ];
        session()->flash('partner_message', 'تم تسجيل حركة جاري الشريك وترحيل قيدها المحاسبي.');
    }

    public function openReverseModal(int $movementId): void
    {
        $this->resetValidation();
        $this->reverseMovementId = $movementId;
        $this->reverseDate = now('Africa/Cairo')->toDateString();
        $this->reverseReason = '';
        $this->reverseKey = (string) Str::uuid();
        $this->showReverseModal = true;
    }

    public function reverseMovement(): void
    {
        abort_unless($this->reverseMovementId, 422);
        app(PartnerAccounts::class)->reverseMovement((int) auth()->id(), $this->reverseMovementId, $this->reverseDate, $this->reverseKey, $this->reverseReason);
        $this->showReverseModal = false;
        $this->reverseMovementId = null;
        $this->reverseKey = (string) Str::uuid();
        session()->flash('partner_message', 'تم عكس حركة الشريك بسجل عكسي مرتبط دون حذف الأصل.');
    }

    public function updated(string $property): void
    {
        if (str_contains($property, 'creationKey')) {
            throw ValidationException::withMessages(['partner' => 'مفتاح العملية محمي.']);
        }
    }

    public function render()
    {
        $actor = (int) auth()->id();
        $access = app(AccountingAccess::class);
        $access->authorize($actor, 'partner_view');

        $activated = $access->activated();
        $manageBranches = $access->branches($actor, 'partner_manage');
        $canManagePartners = $manageBranches !== [] || $access->allows($actor, 'account_manage');
        $canRecordMovements = $activated && $manageBranches !== [];
        $canManageAccounts = $access->allows($actor, 'account_manage');
        $canReverseSelf = app(OrganizationApi::class)->allowsSelfApproval($actor, 'accounting');

        $branchId = $this->filterBranch !== '' ? (int) $this->filterBranch : null;
        $partnerId = $this->filterPartner !== '' ? (int) $this->filterPartner : null;
        $summary = app(PartnerAccounts::class)->summary($actor, $branchId, $partnerId, $this->from ?: null, $this->to ?: null);

        $controlIds = app(FinancialControlAccounts::class)->ids();
        $equityAccounts = DB::table('accounting_accounts')
            ->where('active', true)
            ->where('postable', true)
            ->where('kind', 'equity')
            ->when($controlIds !== [], fn ($q) => $q->whereNotIn('id', $controlIds))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $expenseAccounts = DB::table('accounting_accounts')
            ->where('active', true)
            ->where('postable', true)
            ->where('kind', 'expense')
            ->when($controlIds !== [], fn ($q) => $q->whereNotIn('id', $controlIds))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $treasuryAccounts = [];
        $selectedBranch = (int) ($this->movementForm['branch_id'] ?: ($manageBranches[0]['id'] ?? 0));
        if ($selectedBranch > 0) {
            try {
                $treasuryAccounts = app(TreasuryApi::class)->partnerAccounts($actor, $selectedBranch);
            } catch (\Throwable) {
                $treasuryAccounts = [];
            }
        }

        $branches = DB::table('branches')->where('company_id', 1)->orderBy('name')->get(['id', 'name']);
        $branchNames = $branches->pluck('name', 'id')->all();

        return view('accounting.partners', compact(
            'access',
            'activated',
            'manageBranches',
            'canManagePartners',
            'canRecordMovements',
            'canManageAccounts',
            'canReverseSelf',
            'summary',
            'equityAccounts',
            'expenseAccounts',
            'treasuryAccounts',
            'branches',
            'branchNames'
        ))->layout('layouts::app', ['title' => 'حسابات جاري الشركاء']);
    }
}

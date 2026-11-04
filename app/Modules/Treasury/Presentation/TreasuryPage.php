<?php

namespace App\Modules\Treasury\Presentation;

use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Application\TreasuryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class TreasuryPage extends Component
{
    #[Locked]
    public string $screen;

    public array $form = [];

    public function mount(string $screen): void
    {
        $this->screen = $screen;
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->form = ['creation_key' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'branch_id' => '', 'supplier_id' => '',
            'customer_id' => '', 'account_id' => '', 'from_account_id' => '', 'to_account_id' => '', 'open_item_id' => '',
            'date' => now('Africa/Cairo')->toDateString(), 'amount' => '', 'allocation_amount' => '', 'allow_advance' => false,
            'reference' => '', 'code' => '', 'name' => '', 'kind' => 'bank', 'gl_account_id' => ''];
    }

    public function save(): void
    {
        $api = app(TreasuryApi::class);
        if ($this->screen === 'accounts') {
            $api->saveOperationalAccount(auth()->id(), $this->form);
        } elseif ($this->screen === 'payments') {
            $api->prepareSupplierPayment(auth()->id(), ['creation_key' => $this->form['creation_key'], 'branch_id' => $this->form['branch_id'],
                'supplier_id' => $this->form['supplier_id'], 'account_id' => $this->form['account_id'], 'request_date' => $this->form['date'],
                'amount' => $this->form['amount'], 'reference' => $this->form['reference']]);
        } elseif ($this->screen === 'collections') {
            $allocations = ($this->form['open_item_id'] ?? '') === '' || bccomp((string) ($this->form['allocation_amount'] ?? '0'), '0', 2) === 0
                ? [] : [['open_item_id' => $this->form['open_item_id'], 'amount' => $this->form['allocation_amount']]];
            $api->executeCustomerCollection(auth()->id(), ['idempotency_key' => $this->form['idempotency_key'], 'branch_id' => $this->form['branch_id'],
                'customer_id' => $this->form['customer_id'], 'account_id' => $this->form['account_id'], 'date' => $this->form['date'],
                'amount' => $this->form['amount'], 'reference' => $this->form['reference'], 'allow_advance' => (bool) $this->form['allow_advance']], $allocations);
        } else {
            $api->transfer(auth()->id(), ['idempotency_key' => $this->form['idempotency_key'], 'branch_id' => $this->form['branch_id'],
                'from_account_id' => $this->form['from_account_id'], 'to_account_id' => $this->form['to_account_id'], 'date' => $this->form['date'],
                'amount' => $this->form['amount'], 'reference' => $this->form['reference']]);
        }
        $this->resetForm();
        session()->flash('treasury_message', 'تم تنفيذ الإجراء وحفظ أثره المالي.');
    }

    public function approve(int $id, int $version): void
    {
        app(TreasuryApi::class)->approveSupplierPayment(auth()->id(), $id, $version);
    }

    public function execute(int $id, int $version): void
    {
        app(TreasuryApi::class)->executeSupplierPayment(auth()->id(), $id, $version, $this->form['idempotency_key'],
            [['open_item_id' => $this->form['open_item_id'], 'amount' => $this->form['amount']]]);
        $this->resetForm();
    }

    public function render()
    {
        $access = app(TreasuryAccess::class);
        $nav = $access->navigation(auth()->id());
        abort_unless(isset($nav[$this->screen]), 403);
        $accounts = DB::table('treasury_accounts')->where('active', true)->orderBy('kind')->get();
        $permission = $nav[$this->screen][1];
        $branches = $this->screen === 'accounts' ? collect() : collect($access->branches(auth()->id(), $permission));
        $branchIds = $branches->pluck('id')->map(fn ($id) => (int) $id)->all();
        $openItems = collect(app(ReceivablesPayablesApi::class)->openItems(auth()->id(), $branchIds));
        $rows = match ($this->screen) {
            'accounts' => $accounts,
            'payments' => DB::table('treasury_payment_requests')->whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(100)->get(),
            default => DB::table('treasury_movements')->whereIn('branch_id', $branchIds)->when($this->screen === 'collections', fn ($q) => $q->where('kind', 'customer_collection'))
                ->when($this->screen === 'transfers', fn ($q) => $q->whereIn('kind', ['transfer_out', 'transfer_in']))->orderByDesc('id')->limit(100)->get(),
        };
        $glAccounts = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->where('kind', 'asset')->orderBy('code')->get();
        $partyNames = in_array($this->screen, ['payments', 'collections'], true) ? [
            'supplier' => $this->partyNames('supplier', $openItems->where('party_type', 'supplier')->pluck('party_id')->all()),
            'customer' => $this->partyNames('customer', $openItems->where('party_type', 'customer')->pluck('party_id')->all()),
        ] : ['supplier' => [], 'customer' => []];

        return view('treasury.index', compact('access', 'nav', 'accounts', 'branches', 'openItems', 'rows', 'glAccounts', 'partyNames'))->layout('layouts::app', ['title' => $nav[$this->screen][0]]);
    }

    /** @return array<int, string> Display names keyed by party id; ids that cannot be resolved are omitted. */
    private function partyNames(string $type, array $partyIds): array
    {
        $actor = (int) auth()->id();
        $names = [];
        foreach (array_unique(array_map('intval', $partyIds)) as $partyId) {
            try {
                $names[$partyId] = $type === 'supplier'
                    ? app(SuppliersApi::class)->getSupplier(new GetSupplierRequest($actor, $partyId))->name
                    : app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $partyId))->name;
            } catch (\Throwable) {
                // The party is not visible or unavailable: the view keeps the numeric fallback.
            }
        }

        return $names;
    }
}

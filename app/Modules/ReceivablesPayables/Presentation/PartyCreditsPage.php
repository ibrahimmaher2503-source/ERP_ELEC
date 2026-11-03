<?php

namespace App\Modules\ReceivablesPayables\Presentation;

use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Application\TreasuryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PartyCreditsPage extends Component
{
    public string $kind = 'customer';

    public string $branch = '';

    public string $creditId = '';

    public string $accountId = '';

    public string $date = '';

    public string $amount = '';

    public string $reference = '';

    #[Locked]
    public string $creationKey;

    public function mount(): void
    {
        $access = app(ReceivablesPayablesAccess::class);
        if (! $access->branches(auth()->id(), 'ar_view') && $access->branches(auth()->id(), 'ap_view')) {
            $this->kind = 'supplier';
        }
        $this->creationKey = (string) Str::uuid();
        $this->date = now('Africa/Cairo')->toDateString();
    }

    public function updatedKind(): void
    {
        $this->reset('branch', 'creditId', 'accountId', 'amount', 'reference');
        $this->creationKey = (string) Str::uuid();
    }

    private function permission(): string
    {
        abort_unless(in_array($this->kind, ['customer', 'supplier'], true), 422);

        return $this->kind === 'customer' ? 'ar_view' : 'ap_view';
    }

    public function refund(): void
    {
        app(ReceivablesPayablesAccess::class)->authorize(auth()->id(), $this->permission(), (int) $this->branch);
        $credit = DB::table('rp_party_credits')->where('branch_id', (int) $this->branch)->where('party_type', $this->kind)->find((int) $this->creditId);
        abort_unless($credit, 404);
        app(TreasuryApi::class)->refundPartyCredit(auth()->id(), (int) $credit->id, (int) $this->accountId, $this->amount, $this->date, $this->creationKey, $this->reference);
        $this->creationKey = (string) Str::uuid();
        session()->flash('status', 'تم ترحيل رد الرصيد الدائن وحفظ سند الخزينة المرتبط.');
    }

    public function render()
    {
        $access = app(ReceivablesPayablesAccess::class);
        $branches = $access->branches(auth()->id(), $this->permission());
        abort_unless($branches, 403);
        $ids = array_column($branches, 'id');
        abort_if($this->branch !== '' && ! in_array((int) $this->branch, $ids, true), 403);
        $credits = DB::table('rp_party_credits')->where('party_type', $this->kind)->whereIn('branch_id', $ids)
            ->when($this->branch !== '', fn ($q) => $q->where('branch_id', (int) $this->branch))->orderByDesc('id')->limit(100)->get();
        $selected = $this->branch !== '' ? $credits->firstWhere('id', (int) $this->creditId) : null;
        $canRefund = $selected && app(TreasuryAccess::class)->allows(auth()->id(), 'credit_refund', (int) $selected->branch_id);
        $accounts = $canRefund ? app(TreasuryApi::class)->creditRefundAccounts(auth()->id(), (int) $selected->branch_id) : [];
        $refunds = $selected ? DB::table('rp_party_credit_refunds')->where('credit_id', $selected->id)->orderByDesc('id')->get() : collect();

        return view('financial.party-credits', compact('branches', 'credits', 'selected', 'accounts', 'refunds', 'canRefund'))->layout('layouts::app', ['title' => 'رد الأرصدة الدائنة']);
    }
}

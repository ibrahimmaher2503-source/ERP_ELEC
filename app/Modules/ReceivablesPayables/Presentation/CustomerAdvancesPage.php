<?php

namespace App\Modules\ReceivablesPayables\Presentation;

use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\ReceivablesPayables\Application\CustomerAdvances;
use App\Modules\ReceivablesPayables\Application\ReceivablesPayablesAccess;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Application\TreasuryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class CustomerAdvancesPage extends Component
{
    public string $branch = '';

    public string $advanceId = '';

    public string $openItemId = '';

    public string $accountId = '';

    public string $amount = '';

    public string $date = '';

    public string $reference = '';

    #[Locked]
    public string $creationKey = '';

    public function mount(): void
    {
        $this->creationKey = (string) Str::uuid();
        $this->date = now('Africa/Cairo')->toDateString();
    }

    public function execute(string $action): void
    {
        abort_unless(in_array($action, ['apply', 'refund']), 422);
        $row = DB::table('rp_customer_advances')->find((int) $this->advanceId);
        abort_unless($row && (int) $row->branch_id === (int) $this->branch, 404);
        if ($action === 'apply') {
            app(ReceivablesPayablesApi::class)->applyCustomerAdvance(auth()->id(), (int) $row->id, $this->creationKey, $this->date, [['open_item_id' => (int) $this->openItemId, 'amount' => $this->amount]]);
        } else {
            app(TreasuryApi::class)->refundCustomerAdvance(auth()->id(), (int) $row->id, (int) $this->accountId, $this->amount, $this->date, $this->creationKey, $this->reference);
        }
        $this->creationKey = (string) Str::uuid();
        session()->flash('status', 'تم حفظ الحركة المرتبطة بالمقدم وترحيلها.');
    }

    public function render()
    {
        $access = app(ReceivablesPayablesAccess::class);
        $branches = $access->branches(auth()->id(), 'ar_view');
        abort_unless($branches, 403);
        $ids = array_column($branches, 'id');
        $valid = in_array((int) $this->branch, $ids, true);
        $advances = DB::table('rp_customer_advances')->whereIn('branch_id', $ids)->when($valid, fn ($q) => $q->where('branch_id', (int) $this->branch))->orderByDesc('id')->limit(100)->get();
        foreach ($advances as $advance) {
            $advance->remaining = app(CustomerAdvances::class)->remaining((int) $advance->id);
        }
        $selected = $valid ? $advances->firstWhere('id', (int) $this->advanceId) : null;
        $items = $selected ? DB::table('rp_open_items')->where('party_type', 'customer')->where('party_id', $selected->customer_id)->where('branch_id', $selected->branch_id)->where('remaining_amount', '>', '0')->orderBy('due_date')->limit(100)->get() : collect();
        $canApply = $valid && $access->allows(auth()->id(), 'advance_apply', (int) $this->branch);
        $canRefund = $valid && app(TreasuryAccess::class)->allows(auth()->id(), 'advance_refund', (int) $this->branch);
        $accounts = $canRefund ? app(TreasuryApi::class)->advanceRefundAccounts(auth()->id(), (int) $this->branch) : [];
        $applications = $selected ? DB::table('rp_customer_advance_applications')->where('advance_id', $selected->id)->orderByDesc('id')->get() : collect();
        $refunds = $selected ? DB::table('rp_customer_advance_refunds')->where('advance_id', $selected->id)->orderByDesc('id')->get() : collect();

        return view('financial.advances', compact('branches', 'advances', 'selected', 'items', 'accounts', 'canApply', 'canRefund', 'applications', 'refunds'));
    }
}

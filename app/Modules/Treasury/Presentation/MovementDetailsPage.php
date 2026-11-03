<?php

namespace App\Modules\Treasury\Presentation;

use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use App\Modules\Treasury\Application\TreasuryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class MovementDetailsPage extends Component
{
    #[Locked]
    public int $movementId;

    public string $correctionDate = '';

    public string $reason = '';

    #[Locked]
    public string $creationKey;

    public function mount(int $movement): void
    {
        $this->movementId = $movement;
        $this->correctionDate = now('Africa/Cairo')->toDateString();
        $this->creationKey = (string) Str::uuid();
    }

    public function correct(): void
    {
        $result = app(TreasuryApi::class)->correctMovement(auth()->id(), $this->movementId, $this->correctionDate, $this->creationKey, $this->reason);
        $this->redirectRoute('treasury.movements.show', ['movement' => $result['movement_id']], navigate: true);
    }

    public function render()
    {
        $row = DB::table('treasury_movements')->find($this->movementId);
        abort_unless($row, 404);
        $permission = match ($row->kind) {
            'customer_collection' => 'collection_execute', 'supplier_payment' => 'payment_prepare',
            'customer_advance_refund' => 'advance_refund', 'customer_credit_refund', 'supplier_credit_refund' => 'credit_refund',
            'cash_correction_in', 'cash_correction_out' => 'cash_correct', default => 'transfer_execute',
        };
        $access = app(TreasuryAccess::class);
        $canCorrect = $access->allows(auth()->id(), 'cash_correct', (int) $row->branch_id);
        if (! $canCorrect) {
            $access->authorize(auth()->id(), $permission, (int) $row->branch_id);
        }
        $correction = DB::table('treasury_movements')->where('original_movement_id', $row->id)->first();
        $canCorrect = $canCorrect && $row->original_movement_id === null && $correction === null && (int) $row->executed_by !== auth()->id();

        return view('treasury.movement-details', ['movement' => $row, 'canCorrect' => $canCorrect, 'correction' => $correction])->layout('layouts::app', ['title' => 'تفاصيل حركة الخزينة']);
    }
}

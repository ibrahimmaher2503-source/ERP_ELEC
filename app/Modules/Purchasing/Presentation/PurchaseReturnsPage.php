<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchaseReturns;
use App\Modules\Purchasing\Application\PurchasingAccess;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PurchaseReturnsPage extends Component
{
    private const STATUSES = ['draft' => 'مسودة', 'posting' => 'قيد الترحيل', 'posted' => 'مرحّل', 'rejected' => 'مرفوض'];

    public string $status = '';

    public bool $formOpen = false;

    public string $creationKey = '';

    /** @var array<int, array{id:int,label:string}> */
    public array $sources = [];

    public string $receiptId = '';

    public string $reasonCode = '';

    public string $reason = '';

    /** @var array<int, array{quantity:string,serials:array<int,string>}> keyed by position in the receipt lines */
    public array $lines = [];

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function openForm(): void
    {
        abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'return_create') !== [], 403);
        $this->resetErrorBag();
        $this->resetForm();
        $this->sources = array_map(
            fn (array $receipt) => ['id' => (int) $receipt['id'], 'label' => $receipt['number'].' · '.$receipt['supplier'].' · '.$receipt['date']],
            app(PurchaseReturns::class)->eligibleReceipts(auth()->id()),
        );
        $this->creationKey = (string) Str::uuid();
        $this->formOpen = true;
    }

    public function closeForm(): void
    {
        $this->resetErrorBag();
        $this->resetForm();
        $this->formOpen = false;
        $this->creationKey = '';
        $this->sources = [];
    }

    public function updatedReceiptId(): void
    {
        $this->lines = [];
        foreach ($this->receiptLines() as $index => $line) {
            $this->lines[$index] = ['quantity' => '', 'serials' => []];
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();

        try {
            app(PurchaseReturns::class)->create(auth()->id(), [
                'creation_key' => $this->creationKey,
                'receipt_id' => (int) $this->receiptId,
                'reason_code' => $this->reasonCode,
                'reason' => $this->reason,
                'lines' => $this->payloadLines(),
            ]);
        } catch (HttpException $e) {
            $this->addError('form', $e->getMessage() ?: 'تعذر حفظ المرتجع. حدّث الصفحة وأعد المحاولة.');

            return;
        }

        session()->flash('status', 'تم حفظ المرتجع كمسودة. يحتاج اعتماده من مستخدم آخر لترحيل المخزون والتسوية المالية للمورد.');
        $this->closeForm();
    }

    public function approve(int $id): void
    {
        $this->resetErrorBag();

        try {
            $result = app(PurchaseReturns::class)->approve(auth()->id(), $id);
        } catch (HttpException $e) {
            $this->addError('return', $e->getMessage() ?: 'تعذر اعتماد المرتجع. حدّث الصفحة وتحقق من حالته.');

            return;
        }

        session()->flash('status', 'تم اعتماد المرتجع وترحيله. رقم الوثيقة: '.($result['number'] ?? '—').'.');
    }

    public function startReject(int $id): void
    {
        $this->resetErrorBag();
        $this->rejectingId = $id;
        $this->rejectReason = '';
    }

    public function cancelReject(): void
    {
        $this->resetErrorBag();
        $this->rejectingId = null;
        $this->rejectReason = '';
    }

    public function reject(int $id): void
    {
        $this->resetErrorBag();
        $this->validate(
            ['rejectReason' => ['required', 'string', 'min:5', 'max:2000']],
            ['rejectReason.required' => 'اكتب سبب الرفض.', 'rejectReason.min' => 'سبب الرفض يجب ألا يقل عن 5 أحرف.'],
        );

        try {
            app(PurchaseReturns::class)->reject(auth()->id(), $id, $this->rejectReason);
        } catch (HttpException $e) {
            $this->addError('return', $e->getMessage() ?: 'تعذر رفض المرتجع. حدّث الصفحة وتحقق من حالته.');

            return;
        }

        $this->rejectingId = null;
        $this->rejectReason = '';
        session()->flash('status', 'تم رفض المرتجع. لم يحدث أي أثر على المخزون أو الحسابات.');
    }

    public function render()
    {
        $actor = auth()->id();
        $access = app(PurchasingAccess::class);
        abort_unless($access->branches($actor, 'return_view'), 403);

        return view('purchasing.returns', [
            'rows' => app(PurchaseReturns::class)->list($actor, $this->status !== '' ? ['status' => $this->status] : []),
            'canCreate' => $access->branches($actor, 'return_create') !== [],
            'canApprove' => $access->branches($actor, 'return_approve') !== [],
            'receiptLines' => $this->formOpen ? $this->receiptLines() : [],
            'reasons' => PurchaseReturns::REASONS,
            'statuses' => self::STATUSES,
        ])->layout('layouts::app', ['title' => 'مرتجعات المشتريات']);
    }

    /** Lines of the selected receipt; only receipts from the eligible list are loaded. */
    private function receiptLines(): array
    {
        if ($this->receiptId === '' || ! collect($this->sources)->contains('id', (int) $this->receiptId)) {
            return [];
        }

        return app(PurchaseReturns::class)->receiptLines(auth()->id(), (int) $this->receiptId);
    }

    /** Positive lines only, keyed by display position so backend line errors map to the right row. */
    private function payloadLines(): array
    {
        $payload = [];
        foreach ($this->receiptLines() as $index => $line) {
            $row = $this->lines[$index] ?? [];
            $serials = array_values(array_map('strval', $row['serials'] ?? []));
            $quantity = $line['serials'] !== [] ? (string) count($serials) : trim((string) ($row['quantity'] ?? ''));

            if ($quantity === '' || ($this->isDecimal($quantity) && bccomp($quantity, '0', 8) <= 0)) {
                continue;
            }

            $payload[$index] = [
                'receipt_line_id' => (int) $line['receipt_line_id'],
                'quantity' => $quantity,
                'serials' => $serials,
            ];
        }

        return $payload;
    }

    private function isDecimal(string $value): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,8})?$/D', $value) === 1;
    }

    private function resetForm(): void
    {
        $this->receiptId = '';
        $this->reasonCode = '';
        $this->reason = '';
        $this->lines = [];
    }
}

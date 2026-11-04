<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesReturns;
use Illuminate\Support\Str;
use Livewire\Component;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalesReturnsPage extends Component
{
    private const STATUSES = ['draft' => 'مسودة', 'posting' => 'قيد الترحيل', 'posted' => 'مرحّل', 'rejected' => 'مرفوض'];

    public string $status = '';

    public bool $formOpen = false;

    public string $creationKey = '';

    /** @var array<int, array{id:int,label:string}> */
    #[\Livewire\Attributes\Locked]
    public array $sources = [];

    public string $deliveryId = '';

    public string $reasonCode = '';

    public string $reason = '';

    /** @var array<int, array{quantity:string,condition:string,serials:array<int,string>}> keyed by position in the source lines */
    public array $lines = [];

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    public function boot(): void
    {
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'return_view');
    }

    public function openForm(): void
    {
        abort_unless(app(SalesOrderAccess::class)->allows(auth()->id(), 'return_create'), 403);
        $this->resetErrorBag();
        $this->resetForm();
        $this->sources = array_map(
            fn (array $delivery) => ['id' => (int) $delivery['id'], 'label' => $delivery['reference'].' · '.$delivery['order_number'].' · '.$delivery['customer']],
            app(SalesReturns::class)->eligibleDeliveries(auth()->id()),
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

    public function updatedDeliveryId(): void
    {
        $this->lines = [];
        foreach ($this->sourceLines() as $index => $line) {
            $this->lines[$index] = ['quantity' => '', 'condition' => 'sellable', 'serials' => []];
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();

        try {
            app(SalesReturns::class)->create(auth()->id(), [
                'creation_key' => $this->creationKey,
                'delivery_id' => (int) $this->deliveryId,
                'reason_code' => $this->reasonCode,
                'reason' => $this->reason,
                'lines' => $this->payloadLines(),
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        } catch (HttpException $e) {
            $this->addError('form', $e->getMessage() ?: 'تعذر حفظ المرتجع. حدّث الصفحة وأعد المحاولة.');

            return;
        }

        session()->flash('status', 'تم حفظ المرتجع كمسودة. يحتاج اعتماده من مستخدم آخر لترحيل المخزون وإشعار التصحيح.');
        $this->closeForm();
    }

    public function approve(int $id): void
    {
        $this->resetErrorBag();

        try {
            $result = app(SalesReturns::class)->approve(auth()->id(), $id);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        } catch (HttpException $e) {
            $this->addError('return', $e->getMessage() ?: 'تعذر اعتماد المرتجع. حدّث الصفحة وتحقق من حالته.');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('return', 'تعذر اعتماد المرتجع. لم يحدث أي أثر. حدّث الصفحة وتحقق من حالته.');

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
            app(SalesReturns::class)->reject(auth()->id(), $id, $this->rejectReason);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
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
        $access = app(SalesOrderAccess::class);

        return view('sales.returns.index', [
            'rows' => app(SalesReturns::class)->list($actor, $this->status !== '' ? ['status' => $this->status] : []),
            'canCreate' => $access->allows($actor, 'return_create'),
            'canApprove' => $access->allows($actor, 'return_approve'),
            'approvableBranches' => array_map('intval', $access->branchIds($actor, 'return_approve')),
            'sourceLines' => $this->formOpen ? $this->sourceLines() : [],
            'reasons' => SalesReturns::REASONS,
            'conditions' => SalesReturns::CONDITIONS,
            'statuses' => self::STATUSES,
        ])->layout('layouts::app', ['title' => 'مرتجعات البيع']);
    }

    /** Lines of the selected delivery; only deliveries from the eligible list are loaded. */
    private function sourceLines(): array
    {
        if ($this->deliveryId === '' || ! collect($this->sources)->contains('id', (int) $this->deliveryId)) {
            return [];
        }

        return app(SalesReturns::class)->deliveryLines(auth()->id(), (int) $this->deliveryId);
    }

    /** Positive lines only, keyed by display position so backend line errors map to the right row. */
    private function payloadLines(): array
    {
        $payload = [];
        foreach ($this->sourceLines() as $index => $line) {
            $row = $this->lines[$index] ?? [];
            $serials = array_values(array_map('strval', $row['serials'] ?? []));
            $quantity = $line['serials'] !== [] ? (string) count($serials) : trim((string) ($row['quantity'] ?? ''));

            if ($quantity === '' || ($this->isDecimal($quantity) && bccomp($quantity, '0', 8) <= 0)) {
                continue;
            }

            $payload[$index] = [
                'delivery_line_id' => (int) $line['delivery_line_id'],
                'quantity' => $quantity,
                'condition' => $row['condition'] ?? 'sellable',
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
        $this->deliveryId = '';
        $this->reasonCode = '';
        $this->reason = '';
        $this->lines = [];
    }
}

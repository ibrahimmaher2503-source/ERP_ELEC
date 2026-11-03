<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class PartyFinancialSection extends Component
{
    #[Locked]
    public string $entity;

    #[Locked]
    public int $partyId;

    public string $from = '';

    public string $to = '';

    #[Locked]
    public ?string $periodFrom = null;

    #[Locked]
    public ?string $periodTo = null;

    public string $branch = '';

    public int $invoicesPage = 1;

    public int $movementsPage = 1;

    public int $statementPage = 1;

    public function mount(string $entity, int $partyId): void
    {
        abort_unless(in_array($entity, ['customers', 'suppliers'], true), 404);
        $this->entity = $entity;
        $this->partyId = $partyId;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'branch'], true)) {
            $this->invoicesPage = $this->movementsPage = $this->statementPage = 1;
        }
    }

    public function goToPage(string $table, int $page): void
    {
        abort_unless(in_array($table, ['invoices', 'movements', 'statement'], true) && $page >= 1 && $page <= 100000, 422);
        $this->{$table.'Page'} = $page;
    }

    public function applyPeriod(): void
    {
        $this->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => array_filter(['nullable', 'date_format:Y-m-d', $this->from !== '' ? 'after_or_equal:from' : null])]);
        $this->periodFrom = $this->from ?: null;
        $this->periodTo = $this->to ?: null;
        $this->statementPage = 1;
    }

    private function data(bool $export = false): array
    {
        (new PartyPresentation($this->entity))->authorize(auth()->id(), 'view');

        $api = app(ReceivablesPayablesApi::class);
        $method = $export ? 'partyStatementExport' : 'partyFinancialProfile';

        return $api->{$method}(auth()->id(), $this->entity === 'suppliers' ? 'supplier' : 'customer', $this->partyId,
            ['from' => $this->periodFrom, 'to' => $this->periodTo, 'branch' => $this->branch ?: null,
                'invoices_page' => $this->invoicesPage, 'movements_page' => $this->movementsPage, 'statement_page' => $this->statementPage]);
    }

    public function exportStatement()
    {
        (new PartyPresentation($this->entity))->authorize(auth()->id(), 'export');
        $profile = $this->data(true);
        abort_unless($profile['authorized'], 403);
        $statement = $profile['statement'];
        $rows = [['date' => $this->periodFrom ?: '—', 'reference' => 'رصيد أول الفترة', 'increase' => '', 'decrease' => '', 'balance' => $statement['opening']]];
        foreach ($statement['rows']['items'] as $row) {
            $rows[] = (array) $row;
        }
        $rows[] = ['date' => $this->periodTo ?: '—', 'reference' => 'رصيد آخر الفترة', 'increase' => '', 'decrease' => '', 'balance' => $statement['closing']];
        $path = app(WorkbookCodec::class)->write('party-statement', ['date' => 'تاريخ الإثبات', 'reference' => 'المرجع', 'increase' => 'زيادة الذمة', 'decrease' => 'تخفيض الذمة', 'balance' => 'الرصيد EGP'], $rows);

        return response()->download($path, 'party-statement.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
    }

    public function render()
    {
        return view('parties.financial-section', ['financial' => $this->data(), 'supplier' => $this->entity === 'suppliers',
            'canExport' => (new PartyPresentation($this->entity))->allows(auth()->id(), 'export')]);
    }
}

<?php

namespace App\Modules\System\Presentation;

use App\Modules\System\Application\DocumentNumbering;
use Livewire\Component;

final class DocumentNumberingPage extends Component
{
    public int $year;

    public array $starts = [];

    public function mount(): void
    {
        $this->year = (int) now('Africa/Cairo')->year;
        $this->load();
    }

    public function updatedYear(): void
    {
        $this->load();
    }

    public function save(string $type): void
    {
        $this->resetErrorBag();
        $value = (string) ($this->starts[$type] ?? '');
        if (! ctype_digit($value)) {
            $this->addError('start', 'أدخل رقم بداية صحيحًا.');

            return;
        }
        app(DocumentNumbering::class)->setStart(auth()->id(), $type, $this->year, (int) $value);
        session()->flash('status', 'تم حفظ رقم البداية.');
        $this->load();
    }

    public function render()
    {
        return view('system.document-numbering', ['rows' => app(DocumentNumbering::class)->list(auth()->id(), $this->year)])
            ->layout('layouts::app', ['title' => 'ترقيم المستندات']);
    }

    private function load(): void
    {
        $this->starts = array_column(app(DocumentNumbering::class)->list(auth()->id(), $this->year), 'next', 'type');
    }
}

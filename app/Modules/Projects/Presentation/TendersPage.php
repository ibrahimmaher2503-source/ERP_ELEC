<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\TenderAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class TendersPage extends Component
{
    use WithPagination;

    #[Locked]
    public string $screen = 'register';

    public string $search = '';

    public string $status = '';

    public string $category = '';

    public string $customer = '';

    public bool $upcoming = false;

    public function mount(string $screen = 'register'): void
    {
        abort_unless(in_array($screen, ['register', 'dashboard', 'reports'], true), 404);
        $this->screen = $screen;
        abort_unless(app(TenderAccess::class)->branches(auth()->id()), 403);
        if ($screen === 'reports') {
            abort_unless(app(TenderAccess::class)->branches(auth()->id(), 'report'), 403);
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $a = app(TenderAccess::class);
        $actor = auth()->id();
        abort_unless($a->branches($actor), 403);
        if ($this->screen === 'reports') {
            abort_unless($a->branches($actor, 'report'), 403);
        }
        $q = $a->query($actor, $this->screen === 'reports' ? 'report' : 'view');
        $stats = (clone $q)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $q->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q->where('number', 'ilike', '%'.mb_substr($this->search, 0, 150).'%')->orWhere('title', 'ilike', '%'.mb_substr($this->search, 0, 150).'%')->orWhere('issuer', 'ilike', '%'.mb_substr($this->search, 0, 150).'%')->orWhere('customer_name', 'ilike', '%'.mb_substr($this->search, 0, 150).'%')))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))->when($this->category !== '', fn ($q) => $q->where('category', $this->category))->when($this->customer !== '', fn ($q) => $q->where('customer_id', (int) $this->customer))
            ->when($this->upcoming, fn ($q) => $q->whereNotIn('status', ['awarded', 'lost', 'cancelled', 'no_bid', 'withdrawn'])->whereRaw("(deadlines->>'final_at')::timestamptz BETWEEN ? AND ?", [now(), now()->addDays(14)]));

        return view('tenders.index', ['rows' => $q->orderByDesc('id')->paginate(20), 'stats' => $stats, 'access' => $a, 'canCreate' => $a->branches($actor, 'manage') !== []])->layout('layouts::app', ['title' => 'المناقصات']);
    }
}

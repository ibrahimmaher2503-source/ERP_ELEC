<?php

namespace App\Modules\Sales\Presentation;

use App\Modules\Sales\Application\SalesOrderAccess;
use Livewire\Component;

class SalesReturnsPage extends Component
{
    public function boot(): void
    {
        app(SalesOrderAccess::class)->authorize(auth()->id(), 'return_view');
    }

    public function render()
    {
        return view('sales.returns.index')->layout('layouts::app', ['title' => 'مرتجعات البيع']);
    }
}

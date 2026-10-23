<?php

namespace App\Modules\Purchasing\Presentation;

use App\Modules\Purchasing\Application\PurchasingAccess;
use Livewire\Component;

final class PurchaseReturnsPage extends Component
{
    public function render()
    {
        abort_unless(app(PurchasingAccess::class)->branches(auth()->id(), 'return_view'), 403);

        return view('purchasing.returns')->layout('layouts::app', ['title' => 'مرتجعات المشتريات']);
    }
}

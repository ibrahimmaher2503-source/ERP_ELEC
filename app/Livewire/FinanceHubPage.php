<?php

namespace App\Livewire;

use Livewire\Component;

/** Task-first entry to the finance area. It only links to screens the user's permissions already expose. */
final class FinanceHubPage extends Component
{
    public function render()
    {
        return view('finance.hub');
    }
}

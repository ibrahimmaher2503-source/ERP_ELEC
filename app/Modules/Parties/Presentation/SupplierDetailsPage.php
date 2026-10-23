<?php

namespace App\Modules\Parties\Presentation;

class SupplierDetailsPage extends PartyDetailsPage
{
    protected const ENTITY = 'suppliers';

    public function mount(int $supplier): void
    {
        $this->initialize($supplier);
    }
}

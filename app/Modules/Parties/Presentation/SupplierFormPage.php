<?php

namespace App\Modules\Parties\Presentation;

class SupplierFormPage extends PartyFormPage
{
    protected const ENTITY = 'suppliers';

    public function mount(?int $supplier = null): void
    {
        $this->initialize($supplier);
    }
}

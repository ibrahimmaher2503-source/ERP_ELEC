<?php

namespace App\Modules\Parties\Presentation;

class CustomerFormPage extends PartyFormPage
{
    protected const ENTITY = 'customers';

    public function mount(?int $customer = null): void
    {
        $this->initialize($customer);
    }
}

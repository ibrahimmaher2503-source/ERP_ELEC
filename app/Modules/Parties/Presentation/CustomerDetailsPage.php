<?php

namespace App\Modules\Parties\Presentation;

class CustomerDetailsPage extends PartyDetailsPage
{
    protected const ENTITY = 'customers';

    public function mount(int $customer): void
    {
        $this->initialize($customer);
    }
}

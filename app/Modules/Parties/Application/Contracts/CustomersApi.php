<?php

namespace App\Modules\Parties\Application\Contracts;

interface CustomersApi
{
    public function getCustomer(GetCustomerRequest $request): CustomerIdentity;

    /** @return list<CustomerIdentity> */
    public function searchCustomers(SearchCustomersRequest $request): array;
}

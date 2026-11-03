<?php

namespace App\Modules\Parties\Application\Contracts;

interface CustomersApi
{
    public function maintenanceContext(int $actor, int $customer, ?int $site = null, ?int $contact = null): array;

    public function getCustomer(GetCustomerRequest $request): CustomerIdentity;

    /** @return list<CustomerIdentity> */
    public function searchCustomers(SearchCustomersRequest $request): array;
}

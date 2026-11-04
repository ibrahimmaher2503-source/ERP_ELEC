<?php

namespace App\Modules\Parties\Application\Contracts;

interface CustomersApi
{
    public function maintenanceContext(int $actor, int $customer, ?int $site = null, ?int $contact = null): array;

    public function getCustomer(GetCustomerRequest $request): CustomerIdentity;

    /** Credit limit and payment terms; a null limit means no limit is set. */
    public function commercialProfile(int $actor, int $customer): array;

    /** @return list<CustomerIdentity> */
    public function searchCustomers(SearchCustomersRequest $request): array;
}

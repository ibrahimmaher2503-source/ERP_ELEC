<?php

namespace App\Modules\Parties\Application\Contracts;

interface SuppliersApi
{
    public function getSupplier(GetSupplierRequest $request): SupplierIdentity;

    /** @return list<SupplierIdentity> */
    public function searchSuppliers(SearchSuppliersRequest $request): array;
}

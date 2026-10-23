<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Application\Contracts\CustomerIdentity;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Parties\Domain\Customer;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomersService implements CustomersApi
{
    private function authorize(int $actor): void
    {
        try {
            app(CustomerAccess::class)->authorize($actor, 'M03.customer_view');
        } catch (HttpException $e) {
            throw new CustomerContractException('Customer access denied.', 403);
        }
    }

    private function identity(Customer $r): CustomerIdentity
    {
        return new CustomerIdentity($r->id, $r->code, $r->name, $r->entity_type, $r->status);
    }

    public function getCustomer(GetCustomerRequest $request): CustomerIdentity
    {
        $this->authorize($request->actorId);
        $r = Customer::find($request->customerId);
        if (! $r) {
            throw new CustomerContractException('Customer not found.', 404);
        }
        if ($request->requireActive && $r->status !== 'active') {
            throw new CustomerContractException('Customer inactive.', 422);
        }

        return $this->identity($r);
    }

    public function searchCustomers(SearchCustomersRequest $request): array
    {
        $this->authorize($request->actorId);

        return app(CustomerCatalogQuery::class)->query($request->actorId, ['search' => $request->search, 'status' => 'active'])->orderBy('code')->limit(max(1, min(50, $request->limit)))->get()->map(fn ($r) => $this->identity($r))->all();
    }
}

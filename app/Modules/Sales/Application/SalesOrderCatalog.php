<?php

namespace App\Modules\Sales\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;

final class SalesOrderCatalog
{
    public function branches(int $actor, string $action = 'create'): array
    {
        app(SalesOrderAccess::class)->authorize($actor);

        $branches = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M04.sales_order_'.$action));

        return array_values(array_filter($branches, fn ($branch) => $action === 'view' || $branch['active']));
    }

    public function customers(int $actor, string $search = ''): array
    {
        app(SalesOrderAccess::class)->authorize($actor);

        return array_map(fn ($c) => (array) $c, app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actor, $search, 50)));
    }

    public function customer(int $actor, int $id): array
    {
        app(SalesOrderAccess::class)->authorize($actor);

        return app(QuotationCatalog::class)->customer($actor, $id);
    }

    public function products(int $actor, string $search = ''): array
    {
        app(SalesOrderAccess::class)->authorize($actor);

        return app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'products', $search, true, 1, 50))->items;
    }

    public function units(int $actor): array
    {
        app(SalesOrderAccess::class)->authorize($actor);

        return app(QuotationCatalog::class)->units($actor);
    }

    public function product(int $actor, int $id, int $unit, string $quantity): array
    {
        app(SalesOrderAccess::class)->authorize($actor);

        return app(QuotationCatalog::class)->product($actor, $id, $unit, $quantity);
    }
}

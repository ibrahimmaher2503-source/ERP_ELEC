<?php

namespace App\Modules\Sales\Application;

use App\Modules\Parties\Application\Contracts\CustomerContractException;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use Illuminate\Validation\ValidationException;

final class QuotationCatalog
{
    public function customers(int $actor, string $search = ''): array
    {
        app(QuotationAccess::class)->authorize($actor);

        try {
            return array_map(fn ($c) => (array) $c, app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actor, $search, 50)));
        } catch (CustomerContractException $e) {
            abort($e->getCode(), $e->getMessage());
        }
    }

    public function customer(int $actor, int $id): array
    {
        try {
            return (array) app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $id, true));
        } catch (CustomerContractException $e) {
            abort($e->getCode(), $e->getMessage());
        }
    }

    public function products(int $actor, string $search = ''): array
    {
        app(QuotationAccess::class)->authorize($actor);

        return app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'products', $search, true, 1, 50))->items;
    }

    public function product(int $actor, int $id, int $unit, string $quantity): array
    {
        $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, $id))->product;
        if (! $product['active']) {
            throw ValidationException::withMessages(['lines' => 'الصنف غير نشط.']);
        }
        app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor, $id, $unit, $quantity));
        $units = $this->units($actor);
        $label = collect($units)->firstWhere('id', $unit);
        if (! $label) {
            throw ValidationException::withMessages(['lines' => 'وحدة القياس غير متاحة.']);
        }

        return ['id' => $product['id'], 'sku' => $product['sku'], 'name' => $product['name'], 'description' => $product['short_description'], 'unit_id' => $unit, 'unit_code' => $label['code'], 'unit_name' => $label['name'], 'version' => $product['version']];
    }

    public function units(int $actor): array
    {
        $result = [];
        $page = 1;
        do {
            $batch = app(ProductsApi::class)->listMasters(new ListMastersRequest($actor, 'units', '', true, $page, 100));
            $result = array_merge($result, $batch->items);
            $page++;
        } while (count($result) < $batch->total && $page <= 100);

        return $result;
    }

    public function bySku(int $actor, string $sku): array
    {
        $sku = trim($sku);
        foreach ($this->products($actor, $sku) as $p) {
            if (mb_strtoupper($p['sku']) === mb_strtoupper($sku)) {
                return $p;
            }
        }
        throw ValidationException::withMessages(['sku' => 'كود الصنف غير موجود أو غير نشط.']);
    }
}

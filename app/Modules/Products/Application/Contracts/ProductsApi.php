<?php

namespace App\Modules\Products\Application\Contracts;

interface ProductsApi
{
    public function inventoryProductBySku(int $actor, string $sku): array;

    public function inventoryProduct(int $actor, int $product): array;

    public function inventoryProducts(int $actor, string $search = ''): array;

    public function getProduct(GetProductRequest $request): GetProductResult;

    public function listMasters(ListMastersRequest $request): ListMastersResult;

    public function findByBarcode(FindByBarcodeRequest $request): FindByBarcodeResult;

    public function convertQuantity(ConvertQuantityRequest $request): ConvertQuantityResult;

    public function guidedCatalog(int $actor, string $search = '', ?int $categoryId = null, ?int $brandId = null): array;

    public function extractGuidedSpec(array $product): array;
}

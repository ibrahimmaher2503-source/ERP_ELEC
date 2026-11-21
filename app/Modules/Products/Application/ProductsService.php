<?php

namespace App\Modules\Products\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\ConvertQuantityResult;
use App\Modules\Products\Application\Contracts\FindByBarcodeRequest;
use App\Modules\Products\Application\Contracts\FindByBarcodeResult;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\GetProductResult;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ListMastersResult;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductsService implements ProductsApi
{
    private const FIELDS = ['id', 'sku', 'name', 'name_en', 'short_description', 'kind', 'tracking', 'base_unit_id', 'purchase_unit_id', 'sales_unit_id', 'category_id', 'brand_id', 'model', 'barcode', 'active', 'version', 'attributes'];

    private function authorizeInventory(int $actor): void
    {
        app(OrganizationApi::class)->authorizeInventoryLookup($actor);
    }

    public function inventoryProductBySku(int $actor, string $sku): array
    {
        $this->authorizeInventory($actor);
        Validator::make(['sku' => $sku], ['sku' => ['required', 'string', 'max:100', 'not_regex:/\x00/']])->validate();

        return $this->inventoryProduct($actor, (int) Product::where('sku', $sku)->firstOrFail()->id);
    }

    public function inventoryProduct(int $actor, int $product): array
    {
        $this->authorizeInventory($actor);
        $p = Product::with(['baseUnit', 'category'])->when(DB::transactionLevel() > 0, fn ($q) => $q->sharedLock())->findOrFail($product);

        return $p->only(self::FIELDS) + ['unit_name' => $p->baseUnit?->name ?? '', 'category_name' => $p->category?->name];
    }

    public function inventoryProducts(int $actor, string $search = ''): array
    {
        $this->authorizeInventory($actor);
        $search = mb_substr(str_replace("\0", '', $search), 0, 200);

        return Product::with('baseUnit')->where('active', true)->whereIn('tracking', ['quantity', 'serial'])->where('kind', '<>', 'service')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$search.'%')->orWhere('sku', 'ilike', '%'.$search.'%')))
            ->orderBy('id')->limit(100)->get()->map(fn ($p) => $p->only(self::FIELDS) + ['unit_name' => $p->baseUnit?->name ?? ''])->all();
    }

    public function getProduct(GetProductRequest $request): GetProductResult
    {
        app(ProductAccess::class)->authorize($request->actorId, 'M02.product_view');

        return new GetProductResult(Product::query()->when(DB::transactionLevel() > 0, fn ($q) => $q->sharedLock())->findOrFail($request->productId)->only(self::FIELDS));
    }

    public function findByBarcode(FindByBarcodeRequest $request): FindByBarcodeResult
    {
        app(ProductAccess::class)->authorize($request->actorId, 'M02.product_view');
        if (! preg_match('/^[\x21-\x7E]{1,100}$/D', $request->barcode)) {
            throw ValidationException::withMessages(['barcode' => 'معرّف باركود غير صالح.']);
        }

        return new FindByBarcodeResult(Product::where('barcode', $request->barcode)->where('active', true)->firstOrFail()->only(self::FIELDS));
    }

    public function listMasters(ListMastersRequest $request): ListMastersResult
    {
        app(ProductAccess::class)->authorize($request->actorId, 'M02.product_view');
        if ($request->page < 1 || $request->pageSize < 1 || $request->pageSize > 100 || mb_strlen($request->search) > 200 || str_contains($request->search, "\0")) {
            throw ValidationException::withMessages(['query' => 'حدود البحث غير صالحة.']);
        }
        $class = match ($request->entityType) {
            'products' => Product::class, 'categories' => Category::class, 'brands' => Brand::class, 'units' => Unit::class, default => null
        };
        if (! $class) {
            throw ValidationException::withMessages(['entityType' => 'نوع دليل غير مدعوم.']);
        }
        $query = $class::query();
        if ($request->activeOnly) {
            $query->where('active', true);
        }
        if ($request->search !== '') {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%'.$request->search.'%')->orWhere($request->entityType === 'products' ? 'sku' : 'code', 'ilike', '%'.$request->search.'%');
            });
        }
        $total = (clone $query)->count();
        $fields = $request->entityType === 'products' ? self::FIELDS : ['id', 'code', 'name', 'active'];
        $items = $query->orderBy('id')->forPage($request->page, $request->pageSize)->get()->map(fn ($record) => $record->only($fields))->all();

        return new ListMastersResult($items, $total, $request->page, $request->pageSize);
    }

    public function convertQuantity(ConvertQuantityRequest $request): ConvertQuantityResult
    {
        app(ProductAccess::class)->authorize($request->actorId, 'M02.product_view');
        if (! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,8})?$/D', $request->quantity) || bccomp($request->quantity, '0', 8) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'الكمية يجب أن تكون سلسلة عشرية موجبة.']);
        }
        $product = Product::where('active', true)->findOrFail($request->productId);
        $factor = $request->unitId === $product->base_unit_id ? '1' : $product->conversions()->where('unit_id', $request->unitId)->firstOrFail()->factor;
        $exact = bcmul($request->quantity, $factor, 16);
        $result = bcadd($exact, '0', 8);
        if (bccomp($exact, $result, 16) !== 0 || bccomp($result, '9999999999.99999999', 8) > 0) {
            throw ValidationException::withMessages(['quantity' => 'التحويل يتجاوز الدقة المتاحة؛ لا يتم التقريب تلقائيًا.']);
        }

        return new ConvertQuantityResult($result, $product->base_unit_id);
    }

    public function extractGuidedSpec(array $product): array
    {
        $attrs = [];
        foreach ((array) ($product['attributes'] ?? []) as $row) {
            if (is_array($row) && isset($row['name'], $row['value'])) {
                $attrs[trim((string) $row['name'])] = trim((string) $row['value']);
            }
        }
        $type = $attrs['النوع'] ?? $attrs['عدد الأقطاب'] ?? $attrs['الفئة'] ?? $attrs['عدد القلوب'] ?? $attrs['التغذية'] ?? '';
        $ampere = $attrs['التيار المقنن'] ?? $attrs['نطاق التيار'] ?? $attrs['مقطع الموصل'] ?? $attrs['مقطع التوصيل'] ?? '';
        $kilo = $attrs['قدرة القطع'] ?? $attrs['القدرة'] ?? $attrs['منحنى الفصل'] ?? $attrs['جهد الملف'] ?? '';

        return [
            'spec_model' => trim((string) ($product['model'] ?? '')),
            'spec_type' => $type,
            'spec_ampere' => $ampere,
            'spec_kilo' => $kilo,
        ];
    }

    public function guidedCatalog(int $actor, string $search = '', ?int $categoryId = null, ?int $brandId = null): array
    {
        app(ProductAccess::class)->authorize($actor, 'M02.product_view');
        $search = mb_substr(str_replace("\0", '', trim($search)), 0, 200);
        $categories = Category::where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'parent_id'])->map(fn ($c) => $c->only(['id', 'code', 'name', 'parent_id']))->all();
        $brands = Brand::where('active', true)->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($b) => $b->only(['id', 'code', 'name']))->all();

        $query = Product::where('active', true);
        if ($categoryId !== null && $categoryId > 0) {
            $childIds = collect($categories)->where('parent_id', $categoryId)->pluck('id')->all();
            $query->whereIn('category_id', array_merge([$categoryId], $childIds));
        }
        if ($brandId !== null && $brandId > 0) {
            $query->where('brand_id', $brandId);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('sku', 'ilike', '%'.$search.'%')
                    ->orWhere('model', 'ilike', '%'.$search.'%');
            });
        }

        $products = $query->orderBy('id')->limit(100)->get()->map(function (Product $p) {
            $row = $p->only(self::FIELDS);

            return $row + $this->extractGuidedSpec($row);
        })->all();

        return [
            'categories' => $categories,
            'brands' => $brands,
            'products' => $products,
        ];
    }
}

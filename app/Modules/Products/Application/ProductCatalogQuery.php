<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Domain\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ProductCatalogQuery
{
    public function query(int $actorId, array $filters = []): Builder
    {
        app(ProductAccess::class)->authorize($actorId, 'M02.product_view');
        $allowed = ['search', 'categoryFilter', 'brandFilter', 'typeFilter', 'statusFilter', 'unitFilter'];
        if (array_diff(array_keys($filters), $allowed)) {
            throw ValidationException::withMessages(['filters' => 'فلاتر غير مدعومة.']);
        }
        $query = Product::query();
        $search = mb_substr(str_replace("\0", '', trim((string) ($filters['search'] ?? ''))), 0, 200);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                foreach (['sku', 'name', 'name_en', 'barcode', 'model'] as $field) {
                    $q->orWhere($field, 'ilike', '%'.$search.'%');
                }
                $q->orWhereHas('brand', fn ($brand) => $brand->where('name', 'ilike', '%'.$search.'%'));
            });
        }
        foreach (['categoryFilter' => 'category_id', 'brandFilter' => 'brand_id', 'unitFilter' => 'base_unit_id'] as $key => $column) {
            $value = (string) ($filters[$key] ?? '');
            if ($value !== '') {
                $query->where($column, ctype_digit($value) ? (int) $value : -1);
            }
        }
        if (($filters['typeFilter'] ?? '') !== '') {
            abort_unless(in_array($filters['typeFilter'], ['serialized_equipment', 'bulk', 'service'], true), 422);
            $query->where('kind', $filters['typeFilter']);
        }
        if (($filters['statusFilter'] ?? '') !== '') {
            abort_unless(in_array($filters['statusFilter'], ['active', 'inactive'], true), 422);
            $query->where('active', $filters['statusFilter'] === 'active');
        }

        return $query;
    }
}

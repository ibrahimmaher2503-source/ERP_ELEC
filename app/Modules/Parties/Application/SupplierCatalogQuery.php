<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Supplier;
use Illuminate\Support\Facades\Validator;

class SupplierCatalogQuery
{
    public function query(int $actorId, array $filters = [])
    {
        app(SupplierAccess::class)->authorize($actorId, 'M03.supplier_view');
        Validator::make($filters, ['category_id' => ['nullable', 'integer', 'min:1']])->validate();
        $q = Supplier::query();
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $q->whereRaw("to_tsvector('simple', coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(name_en,'') || ' ' || coalesce(trading_name,'') || ' ' || coalesce(registration_number,'') || ' ' || coalesce(email,'') || ' ' || coalesce(phone,'') || ' ' || coalesce(mobile,'')) @@ plainto_tsquery('simple', ?)", [$search]);
        }
        foreach (['status', 'entity_type', 'category_id', 'supplier_type', 'city', 'country'] as $f) {
            if (($filters[$f] ?? '') !== '') {
                $q->where($f, $filters[$f]);
            }
        }

        return $q;
    }
}

<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Domain\Customer;

class CustomerCatalogQuery
{
    public function query(int $actorId, array $filters = [])
    {
        app(CustomerAccess::class)->authorize($actorId, 'M03.customer_view');
        $q = Customer::query();
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $q->whereRaw("to_tsvector('simple', coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(name_en,'') || ' ' || coalesce(trading_name,'') || ' ' || coalesce(email,'') || ' ' || coalesce(phone,'') || ' ' || coalesce(mobile,'')) @@ plainto_tsquery('simple', ?)", [$search]);
        }
        foreach (['status', 'entity_type', 'segment', 'city', 'country'] as $f) {
            if (($filters[$f] ?? '') !== '') {
                $q->where($f, $filters[$f]);
            }
        }

        return $q;
    }
}

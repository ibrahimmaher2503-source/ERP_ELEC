<?php

namespace App\Modules\Parties\Domain;

use Illuminate\Support\Collection;

interface SupplierHistoryReader
{
    public function forSupplier(int $id): Collection;
}

<?php

namespace App\Modules\Parties\Domain;

use Illuminate\Support\Collection;

interface CustomerHistoryReader
{
    public function forCustomer(int $id): Collection;
}

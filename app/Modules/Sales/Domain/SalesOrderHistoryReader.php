<?php

namespace App\Modules\Sales\Domain;

interface SalesOrderHistoryReader
{
    public function read(int $actorId, int $orderId): array;
}

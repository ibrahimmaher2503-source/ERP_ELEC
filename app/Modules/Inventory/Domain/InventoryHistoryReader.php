<?php

namespace App\Modules\Inventory\Domain;

interface InventoryHistoryReader
{
    public function read(int $actor, int $document): array;
}

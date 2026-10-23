<?php

namespace App\Modules\Purchasing\Application\Contracts;

interface PurchasingApi
{
    /** Current authorized reference projection, never evidence of settlement or recognized cost. */
    public function projectDocument(int $actor, string $type, int $id): array;
}

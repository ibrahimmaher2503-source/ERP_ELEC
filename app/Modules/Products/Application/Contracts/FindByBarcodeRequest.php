<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class FindByBarcodeRequest
{
    public function __construct(public int $actorId, public string $barcode) {}
}

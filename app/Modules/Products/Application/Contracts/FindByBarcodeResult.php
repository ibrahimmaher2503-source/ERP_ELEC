<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class FindByBarcodeResult
{
    public function __construct(public array $product) {}
}

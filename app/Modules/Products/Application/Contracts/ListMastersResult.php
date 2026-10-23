<?php

namespace App\Modules\Products\Application\Contracts;

final readonly class ListMastersResult
{
    public function __construct(public array $items, public int $total, public int $page, public int $pageSize) {}
}

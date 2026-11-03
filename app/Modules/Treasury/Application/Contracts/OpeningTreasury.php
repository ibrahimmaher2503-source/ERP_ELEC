<?php

namespace App\Modules\Treasury\Application\Contracts;

interface OpeningTreasury
{
    public function postOpening(int $actor, int $batch, string $date, array $lines): void;
}

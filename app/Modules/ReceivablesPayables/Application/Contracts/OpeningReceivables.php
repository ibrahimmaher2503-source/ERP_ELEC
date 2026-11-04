<?php

namespace App\Modules\ReceivablesPayables\Application\Contracts;

interface OpeningReceivables
{
    public function postOpening(int $actor, int $batch, string $date, array $lines): void;
}

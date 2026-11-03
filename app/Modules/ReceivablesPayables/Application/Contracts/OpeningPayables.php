<?php

namespace App\Modules\ReceivablesPayables\Application\Contracts;

interface OpeningPayables
{
    public function postOpening(int $actor, int $batch, string $date, array $lines): void;
}

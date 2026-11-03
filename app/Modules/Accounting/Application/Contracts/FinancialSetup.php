<?php

namespace App\Modules\Accounting\Application\Contracts;

interface FinancialSetup
{
    public function prepareActivation(int $actor, int $batch, int $version): array;

    public function completeActivation(int $actor, array $source): void;
}

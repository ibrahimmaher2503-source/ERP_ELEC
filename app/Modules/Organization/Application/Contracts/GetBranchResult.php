<?php

namespace App\Modules\Organization\Application\Contracts;

final readonly class GetBranchResult
{
    /** @param array<string, int|string|bool|null> $branch */
    public function __construct(public array $branch) {}
}

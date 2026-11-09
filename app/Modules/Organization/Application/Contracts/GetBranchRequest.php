<?php

namespace App\Modules\Organization\Application\Contracts;

final readonly class GetBranchRequest
{
    /** @param list<int> $warehouseIds @param list<string> $requestedFields @param array<string, scalar|null> $filters */
    public function __construct(public int $actorId, public int $branchId, public ?int $scopeBranchId = null, public array $warehouseIds = [], public array $requestedFields = [], public array $filters = [], public int $page = 1, public int $pageSize = 100)
    {
        if ($actorId < 1 || $branchId < 1 || ($scopeBranchId !== null && $scopeBranchId < 1) || $page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw new ContractException(ErrorCode::ValidationFailed, 'سياق البحث عن الفرع غير صالح.');
        }
    }
}

<?php

namespace App\Modules\Organization\Application\Contracts;

final readonly class AuthorizeRequest
{
    /** @param list<string> $requestedFields */
    public function __construct(public int $actorId, public string $permissionCode, public ?int $recordBranchId = null, public ?int $warehouseId = null, public array $requestedFields = [])
    {
        if ($actorId < 1 || ($recordBranchId !== null && $recordBranchId < 1) || ($warehouseId !== null && $warehouseId < 1)) {
            throw new ContractException(ErrorCode::ValidationFailed, 'Invalid authorization identifiers.');
        }
    }
}

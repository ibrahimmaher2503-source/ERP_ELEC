<?php

namespace App\Modules\Organization\Application\Contracts;

use RuntimeException;

final class ContractException extends RuntimeException
{
    public function __construct(public readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message, match ($errorCode) {
            ErrorCode::Forbidden => 403, ErrorCode::NotFound => 404, ErrorCode::ValidationFailed => 422, ErrorCode::IncompleteSetup => 503
        });
    }
}

<?php

namespace App\Modules\Organization\Application\Contracts;

enum ErrorCode: string
{
    case Forbidden = 'Forbidden';
    case NotFound = 'NotFound';
    case ValidationFailed = 'ValidationFailed';
    case IncompleteSetup = 'IncompleteSetup';
}

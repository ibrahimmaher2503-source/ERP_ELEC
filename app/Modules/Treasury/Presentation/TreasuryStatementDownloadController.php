<?php

namespace App\Modules\Treasury\Presentation;

use App\Modules\Treasury\Application\TreasuryStatementActions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TreasuryStatementDownloadController
{
    public function __invoke(int $statement): BinaryFileResponse
    {
        return app(TreasuryStatementActions::class)->download(auth()->id(), $statement);
    }
}

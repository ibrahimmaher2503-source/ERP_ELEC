<?php

namespace App\Modules\Organization\Presentation;

use App\Modules\Organization\Application\OrganizationAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanyLogoController
{
    public function __invoke()
    {
        app(OrganizationAccess::class)->authorize(Auth::user(), 'M01.company_view');
        $path = DB::table('companies')->where('id', 1)->value('logo_path');
        abort_unless($path && str_starts_with($path, 'company-logos/') && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ])->setPrivate();
    }
}

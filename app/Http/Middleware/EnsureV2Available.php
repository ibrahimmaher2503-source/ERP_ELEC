<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureV2Available
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('release.v2_enabled'), 404);

        return $next($request);
    }
}

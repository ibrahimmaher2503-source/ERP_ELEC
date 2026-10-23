<?php

namespace App\Modules\Organization\Presentation;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            $account = DB::table('users')->where('id', $request->user()->id)->first(['active', 'session_version']);
            if (! $account?->active || (int) $request->session()->get('organization_auth_version', 1) !== $account->session_version) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                if ($request->expectsJson()) {
                    abort(401, 'انتهت صلاحية الجلسة. سجّل الدخول مجددًا.');
                }

                return redirect()->route('login');
            }
            $request->session()->put('organization_auth_version', $account->session_version);
        }

        return $next($request);
    }
}

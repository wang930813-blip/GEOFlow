<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgentAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        $site = app(CurrentSite::class)->get();

        if (! $admin instanceof Admin || ! $admin->isAgentAdmin() || (string) ($site?->customer_mode ?? '') !== 'agent') {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}

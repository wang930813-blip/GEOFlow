<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentSite
{
    public function __construct(private readonly CurrentSite $currentSite) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();
        if ($admin instanceof Admin) {
            $this->currentSite->ensureForAdmin($admin, $request);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AiConfigurationScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiConfigurationManager
{
    public function __construct(private readonly AiConfigurationScope $scope) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $this->scope->canManage($admin)) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}

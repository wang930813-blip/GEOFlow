<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\CrebeeAgent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCrebeeAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $agentUid = trim((string) $request->header('X-CreBee-Agent-Id', ''));
        $agentSecret = trim((string) $request->header('X-CreBee-Agent-Secret', ''));

        if ($agentUid === '' || $agentSecret === '') {
            throw new ApiException('crebee_agent_unauthorized', 'CreBee Agent 鉴权失败', 401);
        }

        $agent = CrebeeAgent::query()
            ->where('agent_uid', $agentUid)
            ->where('status', 'active')
            ->first();

        if (! $agent instanceof CrebeeAgent || ! Hash::check($agentSecret, (string) $agent->secret_hash)) {
            throw new ApiException('crebee_agent_unauthorized', 'CreBee Agent 鉴权失败', 401);
        }

        $request->attributes->set('crebee_agent', $agent);

        return $next($request);
    }
}

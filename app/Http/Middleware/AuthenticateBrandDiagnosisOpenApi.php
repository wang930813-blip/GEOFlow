<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrandDiagnosisOpenApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('brand_diagnosis.open_api.enabled', false)) {
            throw new ApiException('brand_diagnosis_api_disabled', '品牌诊断开放 API 未启用', 403);
        }

        $expected = trim((string) config('brand_diagnosis.open_api.api_key', ''));
        $provided = trim((string) $request->header('X-Api-Key', ''));

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new ApiException('invalid_api_key', 'API Key 无效', 401);
        }

        return $next($request);
    }
}

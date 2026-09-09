<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateBrandDiagnosisLookupApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('brand_diagnosis.lookup_api.enabled', false)) {
            throw new ApiException(
                'brand_diagnosis_lookup_api_disabled',
                '品牌诊断查询 API 未启用',
                403
            );
        }

        $expected = trim((string) config('brand_diagnosis.lookup_api.api_key', ''));
        $provided = trim((string) $request->header('X-Api-Key', ''));

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new ApiException('invalid_api_key', 'API Key 无效', 401);
        }

        return $next($request);
    }
}

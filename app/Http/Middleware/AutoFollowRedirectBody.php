<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class AutoFollowRedirectBody
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $targetUrl = $response->getTargetUrl();
        $escapedUrl = htmlspecialchars($targetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsonUrl = json_encode($targetUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $response->setContent(<<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="0;url={$escapedUrl}">
    <title>正在跳转</title>
    <script>
        window.location.replace({$jsonUrl});
    </script>
</head>
<body>
    <noscript><a href="{$escapedUrl}">继续</a></noscript>
</body>
</html>
HTML);

        return $response;
    }
}

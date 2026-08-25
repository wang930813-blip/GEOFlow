<?php

namespace Tests\Unit;

use App\Http\Middleware\AutoFollowRedirectBody;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AutoFollowRedirectBodyMiddlewareTest extends TestCase
{
    public function test_it_injects_meta_and_javascript_redirect_body(): void
    {
        $middleware = new AutoFollowRedirectBody();
        $request = Request::create('/submit', 'POST');
        $response = $middleware->handle(
            $request,
            fn (): RedirectResponse => new RedirectResponse('http://localhost:18080/geo_admin/dashboard')
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('window.location.replace', (string) $response->getContent());
        $this->assertStringContainsString('http://localhost:18080/geo_admin/dashboard', (string) $response->getContent());
        $this->assertStringNotContainsString('Redirecting to', (string) $response->getContent());
    }
}

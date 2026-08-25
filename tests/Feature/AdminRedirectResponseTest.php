<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminRedirectResponseTest extends TestCase
{
    public function test_admin_redirect_responses_use_auto_follow_body(): void
    {
        $response = $this->get('/geo_admin');

        $response->assertRedirect(route('admin.login'));
        $response->assertSee('window.location.replace', false);
        $response->assertDontSee('Redirecting to', false);
        $response->assertDontSee('<title>Redirecting</title>', false);
    }
}

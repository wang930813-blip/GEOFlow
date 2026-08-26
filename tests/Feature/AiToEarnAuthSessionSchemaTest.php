<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiToEarnAuthSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_url_column_is_text_for_qr_code_data_urls(): void
    {
        $this->assertSame(
            'text',
            Schema::getColumnType('self_media_auth_sessions', 'authorization_url')
        );
    }
}

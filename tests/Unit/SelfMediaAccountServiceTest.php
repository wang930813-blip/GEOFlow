<?php

namespace Tests\Unit;

use App\Services\SelfMedia\SelfMediaAccountService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SelfMediaAccountServiceTest extends TestCase
{
    public function test_normalizes_numeric_account_status_from_aitoearn(): void
    {
        $service = app(SelfMediaAccountService::class);

        $this->assertSame('authorized', $service->normalizeAccountStatus(1));
        $this->assertSame('unavailable', $service->normalizeAccountStatus(0));
    }

    public function test_platform_catalog_tolerates_non_scalar_remote_platform_fields(): void
    {
        config([
            'aitoearn.enabled' => true,
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'cache.default' => 'array',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => ['zh-CN' => '抖音'],
                        'logoUrl' => ['url' => 'https://cdn.example.com/douyin.png'],
                        'status' => ['value' => 'available'],
                        'contentLimits' => ['modes' => ['video', 'article']],
                    ],
                ],
            ]),
        ]);

        $catalog = app(SelfMediaAccountService::class)->platformCatalog();

        $this->assertSame('抖音', $catalog['douyin']['label']);
        $this->assertSame('https://cdn.example.com/douyin.png', $catalog['douyin']['logo_url']);
        $this->assertSame('available', $catalog['douyin']['status']);
    }

    public function test_platform_catalog_exposes_remote_authorization_capability(): void
    {
        config([
            'aitoearn.enabled' => true,
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'cache.default' => 'array',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    [
                        'platform' => 'xhs',
                        'displayName' => ['zh-CN' => '小红书'],
                        'status' => 'available',
                        'authType' => 'plugin',
                        'capabilities' => [
                            'auth' => ['supported' => false],
                        ],
                    ],
                    [
                        'platform' => 'douyin',
                        'displayName' => ['zh-CN' => '抖音'],
                        'status' => 'available',
                        'authType' => 'qrcode',
                        'capabilities' => [
                            'auth' => ['supported' => true],
                        ],
                    ],
                ],
            ]),
        ]);

        $catalog = app(SelfMediaAccountService::class)->platformCatalog();

        $this->assertFalse($catalog['xhs']['auth_supported']);
        $this->assertSame('plugin', $catalog['xhs']['auth_type']);
        $this->assertTrue($catalog['douyin']['auth_supported']);
        $this->assertSame('qrcode', $catalog['douyin']['auth_type']);
    }

    public function test_platform_catalog_only_exposes_domestic_platforms(): void
    {
        config([
            'aitoearn.enabled' => true,
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'cache.default' => 'array',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => 'Douyin',
                        'status' => 'available',
                    ],
                    [
                        'platform' => 'youtube',
                        'displayName' => 'YouTube',
                        'status' => 'available',
                    ],
                    [
                        'platform' => 'facebook',
                        'displayName' => 'Facebook',
                        'status' => 'available',
                    ],
                ],
            ]),
        ]);

        $catalog = app(SelfMediaAccountService::class)->platformCatalog();

        $this->assertArrayHasKey('douyin', $catalog);
        $this->assertArrayNotHasKey('youtube', $catalog);
        $this->assertArrayNotHasKey('facebook', $catalog);
    }

    public function test_international_display_catalog_returns_exactly_eight_overseas_platforms_in_fixed_order(): void
    {
        config([
            'aitoearn.enabled' => true,
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'cache.default' => 'array',
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => 'Douyin',
                        'status' => 'available',
                    ],
                    [
                        'platform' => 'linkedin',
                        'displayName' => 'LinkedIn API',
                        'status' => 'available',
                        'logoUrl' => 'https://cdn.example.com/linkedin.png',
                    ],
                    [
                        'platform' => 'youtube',
                        'displayName' => 'YouTube API',
                        'status' => 'available',
                    ],
                    [
                        'platform' => 'facebook',
                        'displayName' => 'Facebook API',
                        'status' => 'available',
                    ],
                ],
            ]),
        ]);

        $catalog = app(SelfMediaAccountService::class)->internationalPlatformCatalog();

        $this->assertSame([
            'youtube',
            'twitter',
            'tiktok',
            'facebook',
            'instagram',
            'threads',
            'pinterest',
            'linkedin',
        ], array_keys($catalog));
        $this->assertSame('YouTube API', $catalog['youtube']['label']);
        $this->assertSame('Facebook API', $catalog['facebook']['label']);
        $this->assertSame('LinkedIn API', $catalog['linkedin']['label']);
        $this->assertSame('https://cdn.example.com/linkedin.png', $catalog['linkedin']['logo_url']);
        $this->assertArrayNotHasKey('douyin', $catalog);
        $this->assertSame('available', $catalog['twitter']['status']);
    }
}

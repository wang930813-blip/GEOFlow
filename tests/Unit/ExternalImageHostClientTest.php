<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ExternalImageHostClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExternalImageHostClientTest extends TestCase
{
    public function test_it_uploads_image_binary_and_returns_remote_url(): void
    {
        Config::set('geoflow.image_host.upload_url', 'https://files.example.com/api/upload');
        Config::set('geoflow.image_host.token', 'secret-token');
        Http::fake([
            'https://files.example.com/api/upload' => Http::response([
                'success' => true,
                'data' => [
                    'key' => '2026-05-22/example.webp',
                    'url' => 'https://cdn.example.com/2026-05-22/example.webp',
                    'size' => 123,
                    'mimeType' => 'image/webp',
                ],
            ]),
        ]);

        $uploaded = app(ExternalImageHostClient::class)->upload('binary-image', 'image/webp', 'example.webp');

        $this->assertSame('https://cdn.example.com/2026-05-22/example.webp', $uploaded['url']);
        $this->assertSame('2026-05-22/example.webp', $uploaded['key']);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://files.example.com/api/upload'
                && $request->hasHeader('Authorization', 'Bearer secret-token');
        });
    }

    public function test_it_reports_missing_configuration(): void
    {
        Config::set('geoflow.image_host.upload_url', '');
        Config::set('geoflow.image_host.token', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('图床上传地址或 Token 未配置');

        app(ExternalImageHostClient::class)->upload('binary-image', 'image/png', 'example.png');
    }

    public function test_it_reports_image_host_connection_errors(): void
    {
        Config::set('geoflow.image_host.upload_url', 'https://files.example.com/api/upload');
        Config::set('geoflow.image_host.token', 'secret-token');
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 52: Empty reply from server');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('图床请求失败: cURL error 52: Empty reply from server');

        app(ExternalImageHostClient::class)->upload('binary-image', 'image/png', 'example.png');
    }
}

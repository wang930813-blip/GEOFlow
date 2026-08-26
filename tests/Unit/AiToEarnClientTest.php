<?php

namespace Tests\Unit;

use App\Services\AiToEarn\AiToEarnClient;
use App\Services\AiToEarn\AiToEarnException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiToEarnClientTest extends TestCase
{
    public function test_client_sends_x_api_key_and_unwraps_successful_channel_responses(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'aitoearn.timeout' => 15,
            'aitoearn.connect_timeout' => 5,
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/platforms' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    [
                        'platform' => 'douyin',
                        'displayName' => '抖音',
                        'status' => 'available',
                    ],
                ],
            ]),
        ]);

        $platforms = app(AiToEarnClient::class)->platforms();

        $this->assertSame('douyin', $platforms[0]['platform']);
        $this->assertSame('抖音', $platforms[0]['displayName']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://aitoearn.test/api/v2/channels/platforms'
            && $request->hasHeader('X-Api-Key', 'test-api-key')
            && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_client_posts_publish_flow_with_required_payload_shape(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/publish/flows' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    'flowId' => 'flow_123',
                    'tasks' => [
                        [
                            'id' => 'task_123',
                            'accountId' => 'account_123',
                            'platform' => 'douyin',
                            'status' => 0,
                        ],
                    ],
                ],
            ]),
        ]);

        $result = app(AiToEarnClient::class)->createPublishFlow([
            'flowId' => 'local-flow-1',
            'content' => [
                'title' => '测试文章',
                'body' => '<p>正文</p>',
            ],
            'publishAt' => '2026-08-25T12:00:00+08:00',
            'items' => [
                [
                    'platform' => 'douyin',
                    'accountId' => 'account_123',
                ],
            ],
        ]);

        $this->assertSame('flow_123', $result['flowId']);
        $this->assertSame('task_123', $result['tasks'][0]['id']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/publish/flows'
            && $request['content']['title'] === '测试文章'
            && $request['items'][0]['accountId'] === 'account_123');
    }

    public function test_client_throws_when_business_code_is_not_successful(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts' => Http::response([
                'code' => 1001,
                'message' => 'API Key 无效',
            ], 200),
        ]);

        $this->expectException(AiToEarnException::class);
        $this->expectExceptionMessage('API Key 无效');

        app(AiToEarnClient::class)->accounts();
    }

    public function test_client_includes_business_error_details_when_available(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/auth/douyin*' => Http::response([
                'code' => 400,
                'message' => 'Validation failed',
                'errors' => [
                    'redirectUri' => ['must be a valid callback URL'],
                ],
                'requestId' => 'request-001',
            ], 200),
        ]);

        $this->expectException(AiToEarnException::class);
        $this->expectExceptionMessage('Validation failed');
        $this->expectExceptionMessage('redirectUri: must be a valid callback URL');
        $this->expectExceptionMessage('requestId: request-001');

        app(AiToEarnClient::class)->startAuthorization('douyin', redirectUri: 'http://localhost:18080/geo_admin/crebee-accounts');
    }

    public function test_accounts_filters_by_type_when_platform_is_provided(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts*' => Http::response([
                'code' => 0,
                'message' => '请求成功',
                'data' => [
                    'total' => 1,
                    'accounts' => [
                        [
                            'id' => 'account_123',
                            'type' => 'douyin',
                            'nickname' => '抖音号',
                            'status' => 1,
                        ],
                    ],
                ],
            ]),
        ]);

        $accounts = app(AiToEarnClient::class)->accounts('douyin');

        $this->assertSame('account_123', $accounts['list'][0]['id']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/channels/accounts?')
            && str_contains($request->url(), 'type=douyin')
            && ! str_contains($request->url(), 'types=douyin'));
    }

    public function test_client_fetches_account_publish_option_values(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/accounts/account-bilibili-001/publish-options/tid/values' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'items' => [
                        [
                            'value' => '160',
                            'label' => 'Life',
                            'disabled' => true,
                        ],
                    ],
                ],
            ]),
        ]);

        $values = app(AiToEarnClient::class)->publishOptionValues('account-bilibili-001', 'tid');

        $this->assertSame('160', (string) data_get($values, 'items.0.value'));
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/accounts/account-bilibili-001/publish-options/tid/values');
    }

    public function test_client_fetches_douyin_publish_user_action(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
        ]);

        Http::fake([
            'https://aitoearn.test/api/v2/channels/publish/records/record-douyin-001/user-action' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'recordId' => 'record-douyin-001',
                    'platform' => 'douyin',
                    'shortLink' => 'https://aitoearn.test/api/shortLink/abc123',
                    'schemeUrl' => 'snssdk1128://openplatform/share',
                    'expiresAt' => '2026-08-26T02:09:41.217Z',
                ],
            ]),
        ]);

        $action = app(AiToEarnClient::class)->publishRecordUserAction('record-douyin-001');

        $this->assertSame('https://aitoearn.test/api/shortLink/abc123', $action['shortLink']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://aitoearn.test/api/v2/channels/publish/records/record-douyin-001/user-action');
    }

    public function test_import_remote_asset_uses_dedicated_upload_timeout_for_large_asset_transfers(): void
    {
        config([
            'aitoearn.base_url' => 'https://aitoearn.test',
            'aitoearn.api_key' => 'test-api-key',
            'aitoearn.timeout' => 60,
            'aitoearn.connect_timeout' => 5,
            'aitoearn.upload_timeout' => 300,
            'aitoearn.upload_connect_timeout' => 20,
        ]);

        $inspectedUploadOptions = false;

        Http::fake([
            'https://cdn.example.test/video.mp4' => function ($request, array $options) {
                $this->assertSame(300, (int) $options['timeout']);
                $this->assertSame(20, (int) $options['connect_timeout']);

                return Http::response('video-bytes', 200, [
                    'Content-Type' => 'video/mp4',
                ]);
            },
            'https://aitoearn.test/api/assets/uploadSign' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => 'asset-video-001',
                    'uploadUrl' => 'https://upload.aitoearn.test/asset-video-001',
                    'url' => 'https://assets.aitoearn.cn/pending/video.mp4',
                ],
            ]),
            'https://upload.aitoearn.test/asset-video-001' => function ($request, array $options) use (&$inspectedUploadOptions) {
                $inspectedUploadOptions = true;

                $this->assertSame(300, (int) $options['timeout']);
                $this->assertSame(20, (int) $options['connect_timeout']);
                $this->assertFalse($request->hasHeader('X-Api-Key'));

                return Http::response('', 200);
            },
            'https://aitoearn.test/api/assets/asset-video-001/confirm' => Http::response([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'id' => 'asset-video-001',
                    'url' => 'https://assets.aitoearn.cn/confirmed/video.mp4',
                    'type' => 'publishMedia',
                    'mimeType' => 'video/mp4',
                    'size' => 11,
                ],
            ]),
        ]);

        $asset = app(AiToEarnClient::class)->importRemoteAsset('https://cdn.example.test/video.mp4');

        $this->assertTrue($inspectedUploadOptions);
        $this->assertSame('https://assets.aitoearn.cn/confirmed/video.mp4', $asset['url']);
    }
}

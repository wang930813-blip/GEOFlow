<?php

namespace Tests\Feature;

use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandDiagnosisClaudeFlowTest extends TestCase
{
    public function test_claude_messages_request_supports_web_search_and_normalizes_response(): void
    {
        config([
            'brand_diagnosis.public_platforms.claude.enabled' => true,
            'brand_diagnosis.public_platforms.claude.base_url' => 'https://api.anthropic.test',
            'brand_diagnosis.public_platforms.claude.api_key' => 'claude-test-key',
            'brand_diagnosis.public_platforms.claude.model' => 'claude-sonnet-4-5-20250929',
            'brand_diagnosis.public_platforms.claude.max_tokens' => 4096,
            'brand_diagnosis.public_platforms.claude.max_keywords' => 5,
            'brand_diagnosis.public_platforms.claude.supports_web_search' => true,
        ]);

        Http::fake(function (Request $request) {
            return Http::response([
                'id' => 'msg_claude_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-5-20250929',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '我会先检索 Acme 的公开资料。',
                    ],
                    [
                        'type' => 'text',
                        'text' => '{"found":true,"summary":"Acme 是一家提供企业软件服务的品牌。"}',
                        'citations' => [
                            [
                                'type' => 'web_search_result_location',
                                'url' => 'https://example.com/acme',
                                'title' => 'Acme 官方介绍',
                                'cited_text' => 'Acme 官方品牌信息',
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                ],
            ], 200);
        });

        $result = app(DoubaoBrandDiagnosisClient::class)
            ->generateBrandProfileWithWebSearch('Acme', 'claude');

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.anthropic.test/v1/messages'
                && $request->hasHeader('x-api-key', 'claude-test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && data_get($payload, 'model') === 'claude-sonnet-4-5-20250929'
                && data_get($payload, 'max_tokens') === 4096
                && data_get($payload, 'messages.0.role') === 'user'
                && data_get($payload, 'tools.0.type') === 'web_search_20250305'
                && data_get($payload, 'tools.0.name') === 'web_search'
                && data_get($payload, 'tools.0.max_uses') === 5;
        });

        $this->assertSame('claude', $result['platform']);
        $this->assertStringNotContainsString('我会先检索', $result['text']);
        $this->assertStringContainsString('Acme 是一家提供企业软件服务的品牌', $result['text']);
        $this->assertSame('https://example.com/acme', data_get($result, 'sources.0.url'));
        $this->assertSame('Acme 官方介绍', data_get($result, 'sources.0.title'));
    }

    public function test_claude_pause_turn_continues_with_the_assistant_content(): void
    {
        config([
            'brand_diagnosis.public_platforms.claude.enabled' => true,
            'brand_diagnosis.public_platforms.claude.base_url' => 'https://api.anthropic.test',
            'brand_diagnosis.public_platforms.claude.api_key' => 'claude-test-key',
            'brand_diagnosis.public_platforms.claude.model' => 'claude-sonnet-4-5-20250929',
            'brand_diagnosis.public_platforms.claude.max_tokens' => 4096,
            'brand_diagnosis.public_platforms.claude.max_keywords' => 5,
            'brand_diagnosis.public_platforms.claude.supports_web_search' => true,
        ]);

        $firstResponse = [
            'id' => 'msg_claude_pause',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'pause_turn',
            'content' => [
                [
                    'type' => 'server_tool_use',
                    'id' => 'srvtoolu_1',
                    'name' => 'web_search',
                    'input' => ['query' => 'Acme brand'],
                ],
                [
                    'type' => 'web_search_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => [
                        [
                            'type' => 'web_search_result',
                            'title' => 'Acme search result',
                            'url' => 'https://example.com/acme',
                        ],
                    ],
                ],
            ],
        ];
        $secondResponse = [
            'id' => 'msg_claude_final',
            'type' => 'message',
            'role' => 'assistant',
            'stop_reason' => 'end_turn',
            'content' => [
                [
                    'type' => 'text',
                    'text' => '{"found":true,"summary":"Acme 是一家提供企业软件服务的品牌。"}',
                ],
            ],
        ];

        Http::fakeSequence('api.anthropic.test/v1/messages')
            ->push($firstResponse)
            ->push($secondResponse);

        $result = app(DoubaoBrandDiagnosisClient::class)
            ->generateBrandProfileWithWebSearch('Acme', 'claude');

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $messages = (array) data_get($request->data(), 'messages');

            return count($messages) === 2
                && data_get($messages, '1.role') === 'assistant'
                && data_get($messages, '1.content.0.type') === 'server_tool_use'
                && data_get($request->data(), 'tools.0.type') === 'web_search_20250305';
        });

        $this->assertStringContainsString('Acme 是一家提供企业软件服务的品牌', $result['text']);
    }
}

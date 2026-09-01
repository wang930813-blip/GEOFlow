<?php

namespace Tests\Unit;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandProfileResolver;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Tests\TestCase;

class BrandProfileResolverTest extends TestCase
{
    public function test_it_prefers_the_first_enabled_public_platform_for_profile_search(): void
    {
        config()->set('brand_diagnosis.public_platforms.chatgpt.enabled', true);
        config()->set('brand_diagnosis.public_platforms.grok.enabled', true);

        $client = $this->createMock(DoubaoBrandDiagnosisClient::class);
        $client->expects($this->once())
            ->method('generateBrandProfileWithWebSearch')
            ->with('Acme AI', 'chatgpt')
            ->willReturn([
                'text' => '{"found":true,"summary":"Acme AI profile","industry":"SaaS"}',
                'sources' => [
                    [
                        'title' => 'Acme AI',
                        'url' => 'https://example.com/acme-ai',
                        'type' => 'citation',
                        'meta' => [],
                    ],
                ],
            ]);

        $resolver = new BrandProfileResolver($client);
        $run = new BrandDiagnosisRun([
            'brand_name' => 'Acme AI',
            'platforms' => ['chatgpt', 'grok'],
        ]);

        $profile = $resolver->resolve($run, ['chatgpt', 'grok']);

        $this->assertSame('success', $profile['status']);
        $this->assertSame('web_search', $profile['source']);
        $this->assertSame('ChatGPT', $profile['model']);
        $this->assertSame('chatgpt', $profile['meta']['platform']);
        $this->assertStringContainsString('Acme AI profile', $profile['profile']);
    }

    public function test_it_falls_back_to_doubao_when_only_legacy_platforms_are_selected(): void
    {
        config()->set('brand_diagnosis.public_platforms.chatgpt.enabled', false);
        config()->set('brand_diagnosis.public_platforms.grok.enabled', false);
        config()->set('brand_diagnosis.public_platforms.gemini.enabled', false);

        $client = $this->createMock(DoubaoBrandDiagnosisClient::class);
        $client->expects($this->once())
            ->method('generateBrandProfileWithWebSearch')
            ->with('Legacy Brand', 'doubao')
            ->willReturn([
                'text' => '{"found":true,"summary":"Legacy Brand profile","industry":"Brand"}',
                'sources' => [],
            ]);

        $resolver = new BrandProfileResolver($client);
        $run = new BrandDiagnosisRun([
            'brand_name' => 'Legacy Brand',
            'platforms' => ['qianwen'],
        ]);

        $profile = $resolver->resolve($run, ['qianwen']);

        $this->assertSame('success', $profile['status']);
        $this->assertSame('doubao', $profile['meta']['platform']);
    }
}

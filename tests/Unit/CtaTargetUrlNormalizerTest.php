<?php

namespace Tests\Unit;

use App\Support\Site\CtaTargetUrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CtaTargetUrlNormalizerTest extends TestCase
{
    #[DataProvider('safeUrlProvider')]
    public function test_normalizes_safe_cta_urls(string $input, string $expected): void
    {
        $this->assertSame($expected, CtaTargetUrlNormalizer::normalize($input));
    }

    public static function safeUrlProvider(): array
    {
        return [
            'relative path' => ['/contact', '/contact'],
            'relative segment' => ['contact', '/contact'],
            'https url' => ['https://example.test/contact', 'https://example.test/contact'],
            'http url' => ['http://example.test/contact', 'http://example.test/contact'],
        ];
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_rejects_unsafe_cta_urls(string $input): void
    {
        $this->assertSame('', CtaTargetUrlNormalizer::normalize($input));
    }

    public static function unsafeUrlProvider(): array
    {
        return [
            'protocol relative url' => ['//evil.example.test/redirect'],
            'javascript url' => ['javascript:alert(1)'],
            'data url' => ['data:text/html,<script>alert(1)</script>'],
            'mailto url' => ['mailto:admin@example.test'],
            'backslash path' => ['\\evil.example.test\\redirect'],
            'control character' => ["/contact\njavascript:alert(1)"],
        ];
    }
}

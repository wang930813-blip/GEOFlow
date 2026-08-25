<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FixedUiBrandingCopyTest extends TestCase
{
    #[DataProvider('fixedUiSourceProvider')]
    public function test_fixed_admin_and_user_copy_does_not_show_ceying(string $path): void
    {
        $content = file_get_contents(base_path($path));

        $this->assertIsString($content);
        $this->assertStringNotContainsString('策影', $content, $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixedUiSourceProvider(): iterable
    {
        $paths = [
            'config/geoflow.php',
            'app/Support/MediaDistribution/MediaPlatform.php',
            'app/Support/AdminWelcome/intro_copy.php',
            'app/Support/AdminWelcome/update_welcome_copy.php',
            'app/Services/MediaDistribution/ChaoJiMeiJieClient.php',
            'resources/views/admin/media-distribution/settings.blade.php',
            'resources/views/admin/monitoring-center/reports/enterprise.blade.php',
            'resources/views/admin/monitoring-center/reports/enterprise.html',
            'resources/views/admin/monitoring-center/reports/industry.blade.php',
            'resources/views/admin/monitoring-center/reports/industry.html',
            'public/previews/toutiao-news-20260426/index.html',
            'public/previews/toutiao-news-20260426/category.html',
            'public/previews/toutiao-news-20260426/article.html',
        ];

        $basePath = dirname(__DIR__, 2);

        foreach (glob($basePath.'/lang/*/admin.php') ?: [] as $languageFile) {
            $paths[] = str_replace('\\', '/', substr($languageFile, strlen($basePath) + 1));
        }

        foreach ($paths as $path) {
            yield $path => [$path];
        }
    }
}

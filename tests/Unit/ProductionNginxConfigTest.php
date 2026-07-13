<?php

namespace Tests\Unit;

use Tests\TestCase;

class ProductionNginxConfigTest extends TestCase
{
    public function test_public_monitoring_report_share_route_is_forwarded_to_laravel(): void
    {
        $config = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertIsString($config);
        $this->assertMatchesRegularExpression(
            '/location \^~ \/monitoring-report\/share\/ \{\s+try_files \$uri \$uri\/ \/index\.php\?\$query_string;\s+\}/',
            $config
        );
    }
}

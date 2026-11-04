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

    public function test_public_brand_diagnosis_snapshot_route_is_forwarded_to_laravel(): void
    {
        $config = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertIsString($config);
        $this->assertMatchesRegularExpression(
            '/location \^~ \/brand-diagnosis\/snapshot\/ \{\s+try_files \$uri \$uri\/ \/index\.php\?\$query_string;\s+\}/',
            $config
        );
    }

    public function test_monitoring_center_static_app_is_served_in_both_virtual_hosts(): void
    {
        $config = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertIsString($config);
        $pattern = '/location = \/monitor \{\s+return 302 \/monitor\/login\/;\s+\}\s+location \^~ \/monitor\/ \{\s+root \/var\/www\/html\/public;\s+index index\.html;\s+try_files \$uri \$uri\/ \/monitor\/login\/index\.html;\s+\}/';

        $this->assertSame(2, preg_match_all($pattern, $config));
    }
}

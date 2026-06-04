<?php

namespace Tests\Unit;

use App\Jobs\ProcessMediaResourceSyncJob;
use PHPUnit\Framework\TestCase;

class DistributionQueueConfigurationTest extends TestCase
{
    public function test_docker_queue_workers_listen_to_distribution_queue(): void
    {
        $root = dirname(__DIR__, 2);
        $composeFiles = [
            $root.'/docker-compose.yml',
            $root.'/docker-compose.prod.yml',
        ];

        foreach ($composeFiles as $composeFile) {
            $contents = file_get_contents($composeFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString('--queue=geoflow,distribution,default', $contents, basename($composeFile));
            $this->assertStringContainsString('--tries=3', $contents, basename($composeFile));
            $this->assertStringContainsString('--timeout=600', $contents, basename($composeFile));
        }
    }

    public function test_horizon_supervisor_listens_to_distribution_queue(): void
    {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame(
            ['geoflow', 'distribution'],
            $horizon['defaults']['supervisor-1']['queue'] ?? null
        );
        $this->assertSame(600, $horizon['defaults']['supervisor-1']['timeout'] ?? null);
    }

    public function test_redis_retry_after_exceeds_longest_queue_timeout(): void
    {
        $queue = require dirname(__DIR__, 2).'/config/queue.php';

        $this->assertGreaterThan(
            600,
            $queue['connections']['redis']['retry_after'] ?? null
        );
    }

    public function test_media_resource_sync_job_retries_transient_remote_failures(): void
    {
        $job = new ProcessMediaResourceSyncJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_docker_entrypoints_prepare_private_knowledge_upload_directory(): void
    {
        $root = dirname(__DIR__, 2);
        $entrypoints = [
            $root.'/docker/entrypoint.sh',
            $root.'/docker/entrypoint.prod.sh',
        ];

        foreach ($entrypoints as $entrypoint) {
            $contents = file_get_contents($entrypoint);
            $this->assertIsString($contents);
            $this->assertStringContainsString('storage/app/private/uploads/knowledge', $contents, basename($entrypoint));
            $this->assertStringContainsString('chown -R www-data:www-data', $contents, basename($entrypoint));
            $this->assertStringContainsString('chmod -R ug+rwX', $contents, basename($entrypoint));
        }
    }
}

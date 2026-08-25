<?php

namespace App\Console\Commands;

use App\Services\MediaDistribution\MediaSubmissionService;
use Illuminate\Console\Command;

class MediaDistributionSyncSubmissionsCommand extends Command
{
    protected $signature = 'media-distribution:sync-submissions {--limit=100 : Maximum unfinished orders to sync}';

    protected $description = 'Sync unfinished media distribution submissions from the external media platform';

    public function handle(MediaSubmissionService $submissions): int
    {
        $limit = (int) $this->option('limit');
        $result = $submissions->syncPending($limit);

        $this->info(sprintf(
            'Media submissions synced: %d, failed: %d',
            $result['synced'],
            $result['failed']
        ));

        return self::SUCCESS;
    }
}

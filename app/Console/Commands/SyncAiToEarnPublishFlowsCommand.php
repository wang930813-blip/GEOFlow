<?php

namespace App\Console\Commands;

use App\Jobs\SyncAiToEarnPublishStatusJob;
use App\Models\SelfMediaPublishJob;
use Illuminate\Console\Command;

class SyncAiToEarnPublishFlowsCommand extends Command
{
    protected $signature = 'self-media:sync-aitoearn-flows {--limit=50}';

    protected $description = 'Sync unfinished AiToEarn self-media publish flows.';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $maxAttempts = max(1, (int) config('aitoearn.status_max_attempts', 40));

        $jobs = SelfMediaPublishJob::query()
            ->where('provider', 'aitoearn')
            ->where('external_flow_id', '!=', '')
            ->whereIn('status', ['submitted', 'publishing'])
            ->where('sync_attempts', '<', $maxAttempts)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        foreach ($jobs as $job) {
            SyncAiToEarnPublishStatusJob::dispatch((int) $job->id)->onQueue('self-media');
        }

        $this->info('Queued '.$jobs->count().' AiToEarn publish flow sync jobs.');

        return self::SUCCESS;
    }
}

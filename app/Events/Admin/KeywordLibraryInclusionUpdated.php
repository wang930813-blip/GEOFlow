<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KeywordLibraryInclusionUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $libraryId,
        public readonly int $runId,
        public readonly string $status,
        public readonly int $completedChecks,
        public readonly int $totalChecks,
        public readonly int $failedChecks,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.keyword-libraries.'.$this->libraryId);
    }

    public function broadcastAs(): string
    {
        return 'keyword-library.inclusion.updated';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'library_id' => $this->libraryId,
            'run_id' => $this->runId,
            'status' => $this->status,
            'completed_checks' => $this->completedChecks,
            'total_checks' => $this->totalChecks,
            'failed_checks' => $this->failedChecks,
        ];
    }
}

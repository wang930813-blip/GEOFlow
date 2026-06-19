<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\CrebeeAccount;
use App\Models\CrebeeAgent;
use App\Models\CrebeeBindRequest;
use App\Models\CrebeePublishEvent;
use App\Models\CrebeePublishJob;
use App\Models\CrebeePublishJobItem;
use App\Services\Crebee\CrebeeAccountAvatarCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrebeeAgentController extends BaseApiController
{
    public function __construct(private readonly CrebeeAccountAvatarCache $avatarCache) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $payload = $request->validate([
            'version' => ['nullable', 'string', 'max:60'],
            'crebee_status' => ['nullable', 'string', 'max:30'],
            'meta' => ['nullable', 'array'],
        ]);

        $agent->forceFill([
            'last_seen_at' => now(),
            'version' => trim((string) ($payload['version'] ?? $agent->version)),
            'crebee_status' => trim((string) ($payload['crebee_status'] ?? $agent->crebee_status)) ?: 'unknown',
            'meta' => (array) ($payload['meta'] ?? $agent->meta ?? []),
        ])->save();

        return $this->success($request, [
            'agent_id' => (int) $agent->id,
            'status' => 'ok',
        ]);
    }

    public function syncAccounts(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $payload = $request->validate([
            'accounts' => ['present', 'array', 'max:500'],
            'accounts.*.account_id' => ['required', 'string', 'max:160'],
            'accounts.*.account_platform' => ['required', 'string', 'max:40'],
            'accounts.*.nickname' => ['nullable', 'string', 'max:160'],
            'accounts.*.avatar' => ['nullable', 'string', 'max:500'],
            'accounts.*.raw' => ['nullable', 'array'],
        ]);

        $synced = 0;
        $autoBound = 0;
        $syncedAccountKeys = [];
        foreach ($payload['accounts'] as $accountPayload) {
            $accountId = trim((string) $accountPayload['account_id']);
            $platform = trim((string) $accountPayload['account_platform']);
            if ($accountId === '' || $platform === '') {
                continue;
            }
            $syncedAccountKeys[] = $this->accountSyncKey($platform, $accountId);

            $wasCreated = false;
            $wasAutoBound = false;
            $avatarOriginal = $this->avatarCache->normalizeImageUrl((string) ($accountPayload['avatar'] ?? ''));
            $avatar = $this->avatarCache->cache($avatarOriginal, $platform, $accountId) ?? '';

            DB::transaction(function () use ($agent, $accountPayload, $accountId, $platform, $avatarOriginal, $avatar, &$wasCreated, &$wasAutoBound): void {
                $account = CrebeeAccount::query()
                    ->where('agent_id', (int) $agent->id)
                    ->where('platform', $platform)
                    ->where('crebee_account_id', $accountId)
                    ->lockForUpdate()
                    ->first();

                if (! $account instanceof CrebeeAccount) {
                    $wasCreated = true;
                    $account = new CrebeeAccount([
                        'agent_id' => (int) $agent->id,
                        'platform' => $platform,
                        'crebee_account_id' => $accountId,
                        'status' => 'available',
                    ]);
                }

                $account->fill([
                    'account_name' => trim((string) ($accountPayload['nickname'] ?? '')),
                    'avatar' => $avatar,
                    'status' => $account->exists ? (string) $account->status : 'available',
                    'last_synced_at' => now(),
                    'raw_account' => array_merge((array) ($accountPayload['raw'] ?? $accountPayload), [
                        'avatar_original' => $avatarOriginal,
                    ]),
                ]);

                if ($wasCreated && $this->autoBindNewAccount($account, $agent)) {
                    $wasAutoBound = true;
                }

                $account->save();
            });

            if ($wasAutoBound) {
                $autoBound++;
            }
            $synced++;
        }

        $this->markMissingUnboundAccountsUnavailable($agent, $syncedAccountKeys);

        return $this->success($request, [
            'synced' => $synced,
            'auto_bound' => $autoBound,
        ]);
    }

    public function nextJob(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $job = DB::transaction(function () use ($agent): ?CrebeePublishJob {
            $job = CrebeePublishJob::query()
                ->where('agent_id', (int) $agent->id)
                ->where('status', 'queued')
                ->where(function ($query): void {
                    $query->whereNull('scheduled_at')
                        ->orWhere('scheduled_at', '<=', now());
                })
                ->orderByRaw('scheduled_at is null desc')
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $job instanceof CrebeePublishJob) {
                return null;
            }

            $job->forceFill([
                'status' => 'dispatching',
                'dispatch_started_at' => now(),
            ])->save();

            CrebeePublishJobItem::query()
                ->where('job_id', (int) $job->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'dispatching',
                    'updated_at' => now(),
                ]);

            return $job;
        });

        if (! $job instanceof CrebeePublishJob) {
            return $this->success($request, [
                'job' => null,
            ]);
        }

        $job->load(['items.account']);

        return $this->success($request, [
            'job' => $this->jobPayload($job),
        ]);
    }

    public function accepted(Request $request, CrebeePublishJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);
        $payload = $request->validate([
            'raw' => ['nullable', 'array'],
        ]);

        $job->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'raw_response' => (array) ($payload['raw'] ?? []),
        ])->save();

        CrebeePublishJobItem::query()
            ->where('job_id', (int) $job->id)
            ->whereIn('status', ['queued', 'dispatching'])
            ->update([
                'status' => 'submitted',
                'updated_at' => now(),
            ]);

        return $this->success($request, [
            'status' => 'submitted',
        ]);
    }

    public function events(Request $request, CrebeePublishJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);
        $payload = $request->validate([
            'events' => ['required', 'array', 'max:200'],
            'events.*.taskId' => ['required', 'string', 'max:120'],
            'events.*.type' => ['required', 'string', 'max:60'],
            'events.*.progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'events.*.message' => ['nullable', 'string', 'max:5000'],
            'events.*.raw' => ['nullable', 'array'],
        ]);

        $job->load('items');
        $items = $job->items->keyBy('crebee_task_id');
        $recorded = 0;

        foreach ($payload['events'] as $eventPayload) {
            $taskId = trim((string) $eventPayload['taskId']);
            $type = trim((string) $eventPayload['type']);
            $item = $items->get($taskId);

            CrebeePublishEvent::query()->create([
                'job_id' => (int) $job->id,
                'job_item_id' => $item instanceof CrebeePublishJobItem ? (int) $item->id : null,
                'crebee_task_id' => $taskId,
                'event_type' => $type,
                'progress' => isset($eventPayload['progress']) ? (int) $eventPayload['progress'] : null,
                'message' => $this->truncateText($eventPayload['message'] ?? '', 500),
                'raw_event' => (array) ($eventPayload['raw'] ?? $eventPayload),
                'created_at' => now(),
            ]);

            if ($item instanceof CrebeePublishJobItem) {
                $item->forceFill([
                    'status' => $this->eventStatus($type),
                    'progress' => isset($eventPayload['progress']) ? (int) $eventPayload['progress'] : (int) $item->progress,
                    'message' => $this->truncateText($eventPayload['message'] ?? $item->message, 500),
                    'last_event_at' => now(),
                ])->save();
            }

            $recorded++;
        }

        if ((string) $job->status !== 'publishing') {
            $job->forceFill(['status' => 'publishing'])->save();
        }

        return $this->success($request, [
            'recorded' => $recorded,
        ]);
    }

    public function finished(Request $request, CrebeePublishJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);
        $payload = $request->validate([
            'items' => ['required', 'array', 'max:200'],
            'items.*.taskId' => ['required', 'string', 'max:120'],
            'items.*.status' => ['required', 'string', 'max:30'],
            'items.*.published_url' => ['nullable', 'string', 'max:1000'],
            'items.*.message' => ['nullable', 'string', 'max:5000'],
            'items.*.raw' => ['nullable', 'array'],
        ]);

        $job->load('items');
        $items = $job->items->keyBy('crebee_task_id');

        foreach ($payload['items'] as $itemPayload) {
            $item = $items->get(trim((string) $itemPayload['taskId']));
            if (! $item instanceof CrebeePublishJobItem) {
                continue;
            }

            $status = $this->finalItemStatus((string) $itemPayload['status']);
            $item->forceFill([
                'status' => $status,
                'progress' => $status === 'success' ? 100 : (int) $item->progress,
                'message' => $this->truncateText($itemPayload['message'] ?? $item->message, 500),
                'published_url' => trim((string) ($itemPayload['published_url'] ?? $item->published_url)),
                'published_at' => $status === 'success' ? now() : $item->published_at,
                'raw_response' => (array) ($itemPayload['raw'] ?? []),
                'last_event_at' => now(),
            ])->save();
        }

        $status = $this->refreshFinalJobStatus($job);

        return $this->success($request, [
            'status' => $status,
        ]);
    }

    public function failed(Request $request, CrebeePublishJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);
        $payload = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'raw' => ['nullable', 'array'],
        ]);

        $job->forceFill([
            'status' => 'failed',
            'failure_reason' => $this->truncateText($payload['message'] ?? '', 2000),
            'finished_at' => now(),
            'raw_response' => (array) ($payload['raw'] ?? []),
        ])->save();

        CrebeePublishJobItem::query()
            ->where('job_id', (int) $job->id)
            ->whereNotIn('status', ['success', 'failed'])
            ->update([
                'status' => 'failed',
                'message' => $this->truncateText($payload['message'] ?? '', 500),
                'last_event_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->success($request, [
            'status' => 'failed',
        ]);
    }

    private function agent(Request $request): CrebeeAgent
    {
        $agent = $request->attributes->get('crebee_agent');
        if (! $agent instanceof CrebeeAgent) {
            throw new ApiException('crebee_agent_unauthorized', 'CreBee Agent 鉴权失败', 401);
        }

        return $agent;
    }

    private function autoBindNewAccount(CrebeeAccount $account, CrebeeAgent $agent): bool
    {
        $activeRequests = CrebeeBindRequest::query()
            ->where('platform', (string) $account->platform)
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($query): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>', now());
            })
            ->orderBy('requested_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($activeRequests->count() !== 1) {
            return false;
        }

        $bindRequest = $activeRequests->first();
        if (! $bindRequest instanceof CrebeeBindRequest || $bindRequest->site_id === null || $bindRequest->owner_admin_id === null) {
            return false;
        }

        $account->forceFill([
            'site_id' => (int) $bindRequest->site_id,
            'owner_admin_id' => (int) $bindRequest->owner_admin_id,
            'status' => 'bound',
            'bound_at' => now(),
        ]);

        $bindRequest->forceFill([
            'agent_id' => (int) $agent->id,
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'meta' => array_merge((array) $bindRequest->meta, [
                'auto_bound_crebee_account_id' => (string) $account->crebee_account_id,
                'auto_bound_account_name' => (string) $account->account_name,
            ]),
        ])->save();

        return true;
    }

    /**
     * @param  array<int,string>  $syncedAccountKeys
     */
    private function markMissingUnboundAccountsUnavailable(CrebeeAgent $agent, array $syncedAccountKeys): void
    {
        $currentKeys = array_fill_keys($syncedAccountKeys, true);

        CrebeeAccount::query()
            ->where('agent_id', (int) $agent->id)
            ->whereNull('site_id')
            ->whereNull('owner_admin_id')
            ->whereIn('status', ['available', 'unavailable'])
            ->chunkById(200, function ($accounts) use ($currentKeys): void {
                foreach ($accounts as $account) {
                    if (! $account instanceof CrebeeAccount) {
                        continue;
                    }

                    $isCurrent = isset($currentKeys[$this->accountSyncKey(
                        (string) $account->platform,
                        (string) $account->crebee_account_id
                    )]);
                    $nextStatus = $isCurrent ? 'available' : 'unavailable';

                    if ((string) $account->status !== $nextStatus) {
                        $account->forceFill(['status' => $nextStatus])->save();
                    }
                }
            });
    }

    private function accountSyncKey(string $platform, string $accountId): string
    {
        return $platform."\n".$accountId;
    }

    private function assertOwnsJob(Request $request, CrebeePublishJob $job): void
    {
        $agent = $this->agent($request);
        if ((int) $job->agent_id !== (int) $agent->id) {
            throw new ApiException('crebee_job_forbidden', 'Agent 无权操作该 CreBee 发布任务', 403);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jobPayload(CrebeePublishJob $job): array
    {
        $payload = (array) $job->payload;
        /** @var Collection<int, CrebeePublishJobItem> $items */
        $items = $job->items;

        return [
            'id' => (int) $job->id,
            'contentType' => (string) ($payload['contentType'] ?? $job->content_type),
            'commonForm' => (array) ($payload['commonForm'] ?? []),
            'assets' => (array) ($payload['assets'] ?? []),
            'createdAt' => $job->created_at?->toIso8601String(),
            'submittedAt' => $job->submitted_at?->toIso8601String(),
            'tasks' => $items->map(fn (CrebeePublishJobItem $item): array => $this->taskPayload($item, $job))->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taskPayload(CrebeePublishJobItem $item, CrebeePublishJob $job): array
    {
        $payload = (array) $item->payload;
        $account = $item->account;

        return [
            'taskId' => (string) ($payload['taskId'] ?? $item->crebee_task_id),
            'accountId' => (string) ($payload['accountId'] ?? ($account instanceof CrebeeAccount ? $account->crebee_account_id : '')),
            'platform' => (string) ($payload['platform'] ?? $item->platform),
            'contentType' => (string) ($payload['contentType'] ?? $job->content_type),
            'params' => (array) ($payload['params'] ?? []),
        ];
    }

    private function eventStatus(string $type): string
    {
        return match ($type) {
            'taskQueued' => 'queued',
            'publishing', 'taskRetrying' => 'publishing',
            'success' => 'success',
            'error', 'taskCancelled' => 'failed',
            default => 'publishing',
        };
    }

    private function finalItemStatus(string $status): string
    {
        $status = trim(strtolower($status));

        return in_array($status, ['success', 'failed', 'error'], true)
            ? ($status === 'success' ? 'success' : 'failed')
            : 'failed';
    }

    private function truncateText(mixed $value, int $limit): string
    {
        return mb_substr(trim((string) $value), 0, $limit);
    }

    private function refreshFinalJobStatus(CrebeePublishJob $job): string
    {
        $job->load('items');
        $statuses = $job->items->pluck('status')->map(fn ($status): string => (string) $status)->all();
        $successCount = count(array_filter($statuses, fn (string $status): bool => $status === 'success'));
        $failedCount = count(array_filter($statuses, fn (string $status): bool => $status === 'failed'));
        $total = count($statuses);

        $status = match (true) {
            $total > 0 && $successCount === $total => 'success',
            $total > 0 && $failedCount === $total => 'failed',
            $successCount > 0 && $failedCount > 0 => 'partial_success',
            default => 'publishing',
        };

        $job->forceFill([
            'status' => $status,
            'finished_at' => in_array($status, ['success', 'failed', 'partial_success'], true) ? now() : null,
        ])->save();

        return $status;
    }
}

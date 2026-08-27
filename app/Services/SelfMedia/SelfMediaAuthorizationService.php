<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\SelfMediaAuthSession;
use App\Models\Site;
use App\Services\AiToEarn\AiToEarnClient;
use App\Services\AiToEarn\AiToEarnException;
use Illuminate\Support\Carbon;
use RuntimeException;

class SelfMediaAuthorizationService
{
    public function __construct(
        private readonly AiToEarnClient $client,
        private readonly SelfMediaAccountService $accountService,
    ) {}

    public function start(Admin $admin, Site $site, string $platform, string $redirectUri): SelfMediaAuthSession
    {
        $group = $this->accountService->ensureOwnerGroup($admin, $site);
        $externalGroupId = (string) $group->external_group_id;
        $knownAccountIds = [];
        $knownAccountSnapshotError = '';
        try {
            $knownAccountIds = $this->accountService->remoteAccountIds($platform, $externalGroupId);
        } catch (AiToEarnException $exception) {
            $knownAccountSnapshotError = $exception->getMessage();
        }

        $payload = $this->client->startAuthorization($platform, callbackUrl: $redirectUri, groupId: $externalGroupId);
        $sessionId = trim((string) ($payload['sessionId'] ?? $payload['session_id'] ?? ''));
        $url = trim((string) ($payload['url'] ?? $payload['authorizationUrl'] ?? $payload['authorization_url'] ?? ''));

        if ($sessionId === '' || $url === '') {
            throw new RuntimeException('授权接口没有返回可用的授权链接');
        }

        return SelfMediaAuthSession::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
            'platform' => $platform,
            'external_group_id' => $externalGroupId,
            'session_id' => $sessionId,
            'authorization_url' => $url,
            'status' => 'pending',
            'expires_at' => $this->parseTime($payload['expiresAt'] ?? null),
            'raw_response' => array_merge($payload, [
                'external_group_id' => $externalGroupId,
                'known_account_ids_before_authorization' => $knownAccountIds,
                'known_account_snapshot_error' => $knownAccountSnapshotError,
            ]),
        ]);
    }

    public function refresh(SelfMediaAuthSession $session): SelfMediaAuthSession
    {
        $payload = $this->client->authorizationStatus((string) $session->platform, (string) $session->session_id);
        $status = $this->normalizeStatus($payload['status'] ?? '');
        $syncedAccount = null;

        if ($status === 'authorized' && $session->owner instanceof Admin && $session->site instanceof Site) {
            $externalGroupId = trim((string) $session->external_group_id);
            if ($externalGroupId === '') {
                $externalGroupId = (string) $this->accountService
                    ->ensureOwnerGroup($session->owner, $session->site)
                    ->external_group_id;
            }

            try {
                $syncedAccount = $this->accountService->syncAuthorizedOwnerAccount(
                    $session->owner,
                    $session->site,
                    (string) $session->platform,
                    $this->knownAccountIdsBeforeAuthorization($session),
                    $payload,
                    $externalGroupId,
                );
            } catch (AiToEarnException $exception) {
                throw $exception;
            } catch (RuntimeException $exception) {
                $session->forceFill([
                    'status' => 'failed',
                    'raw_response' => array_merge((array) $session->raw_response, [
                        'status_response' => $payload,
                        'external_group_id' => $externalGroupId,
                        'failure_reason' => $exception->getMessage(),
                    ]),
                ])->save();

                throw $exception;
            }
        }

        $session->forceFill([
            'status' => $status,
            'external_group_id' => $externalGroupId ?? $session->external_group_id,
            'confirmed_at' => $status === 'authorized' ? now() : $session->confirmed_at,
            'confirmed_account_id' => $syncedAccount?->id ?? $session->confirmed_account_id,
            'raw_response' => array_merge((array) $session->raw_response, [
                'status_response' => $payload,
                'external_group_id' => $externalGroupId ?? $session->external_group_id,
            ]),
        ])->save();

        return $session->refresh();
    }

    public function normalizeStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'authorized', 'success', 'succeeded', 'connected', 'confirmed', 'completed', 'done' => 'authorized',
            'failed', 'error', 'cancelled', 'canceled' => 'failed',
            'expired', 'timeout' => 'expired',
            default => 'pending',
        };
    }

    /**
     * @return list<string>
     */
    private function knownAccountIdsBeforeAuthorization(SelfMediaAuthSession $session): array
    {
        return array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get((array) $session->raw_response, 'known_account_ids_before_authorization', []),
        ))));
    }

    private function parseTime(mixed $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

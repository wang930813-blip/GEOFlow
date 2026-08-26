<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\SelfMediaAuthSession;
use App\Models\Site;
use App\Services\AiToEarn\AiToEarnClient;
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
        $payload = $this->client->startAuthorization($platform, $redirectUri);
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
            'session_id' => $sessionId,
            'authorization_url' => $url,
            'status' => 'pending',
            'expires_at' => $this->parseTime($payload['expiresAt'] ?? null),
            'raw_response' => $payload,
        ]);
    }

    public function refresh(SelfMediaAuthSession $session): SelfMediaAuthSession
    {
        $payload = $this->client->authorizationStatus((string) $session->platform, (string) $session->session_id);
        $status = $this->normalizeStatus($payload['status'] ?? '');
        $syncedAccount = null;

        if ($status === 'authorized' && $session->owner instanceof Admin && $session->site instanceof Site) {
            $syncedAccount = $this->accountService
                ->syncOwnerAccounts($session->owner, $session->site, (string) $session->platform)
                ->first();
        }

        $session->forceFill([
            'status' => $status,
            'confirmed_at' => $status === 'authorized' ? now() : $session->confirmed_at,
            'confirmed_account_id' => $syncedAccount?->id ?? $session->confirmed_account_id,
            'raw_response' => array_merge((array) $session->raw_response, [
                'status_response' => $payload,
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

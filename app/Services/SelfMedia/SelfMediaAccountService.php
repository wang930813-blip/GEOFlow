<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\SelfMediaAccount;
use App\Models\Site;
use App\Services\AiToEarn\AiToEarnClient;
use App\Services\AiToEarn\AiToEarnException;
use App\Support\SelfMedia\SelfMediaPlatformCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SelfMediaAccountService
{
    public function __construct(private readonly AiToEarnClient $client) {}

    /**
     * @return array<string,array<string,mixed>>
     */
    public function platformCatalog(): array
    {
        if (! (bool) config('aitoearn.enabled', false) || trim((string) config('aitoearn.api_key', '')) === '') {
            return SelfMediaPlatformCatalog::onlyDomestic(SelfMediaPlatformCatalog::all());
        }

        return Cache::remember('aitoearn:platforms:v2:domestic', now()->addMinutes(10), function (): array {
            try {
                $remote = $this->client->platforms();
            } catch (AiToEarnException) {
                return SelfMediaPlatformCatalog::onlyDomestic(SelfMediaPlatformCatalog::all());
            }

            $fallback = SelfMediaPlatformCatalog::onlyDomestic(SelfMediaPlatformCatalog::all());
            $catalog = [];
            foreach ($remote as $item) {
                $platform = $this->stringValue($item['platform'] ?? '');
                if ($platform === '' || ! SelfMediaPlatformCatalog::isDomestic($platform)) {
                    continue;
                }

                $catalog[$platform] = [
                    'label' => $this->stringValue($item['displayName'] ?? '', ['zh-CN', 'zh_CN', 'zh', 'name', 'label', 'value'])
                        ?: SelfMediaPlatformCatalog::label($platform),
                    'desc' => $this->platformDescription($platform, (array) ($item['contentLimits'] ?? [])),
                    'logo' => (string) ($fallback[$platform]['logo'] ?? ($platform.'.png')),
                    'logo_url' => $this->stringValue($item['logoUrl'] ?? '', ['url', 'src', 'value']),
                    'status' => $this->stringValue($item['status'] ?? 'available', ['value', 'status', 'name']) ?: 'available',
                    'raw' => $item,
                ];
            }

            return $catalog !== [] ? $catalog : $fallback;
        });
    }

    /**
     * @return Collection<int,SelfMediaAccount>
     */
    public function boundAccountsForOwner(Admin $admin, Site $site, ?array $platforms = null): Collection
    {
        return SelfMediaAccount::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('provider', 'aitoearn')
            ->where('status', 'bound')
            ->where('auth_status', 'authorized')
            ->when($platforms !== null, fn ($query) => $query->whereIn('platform', $platforms))
            ->orderBy('platform')
            ->orderByDesc('bound_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int,SelfMediaAccount>
     */
    public function syncOwnerAccounts(Admin $admin, Site $site, ?string $platform = null): Collection
    {
        $accounts = $this->client->accounts($platform)['list'];
        $synced = [];

        foreach ($accounts as $accountPayload) {
            $platformKey = trim((string) ($accountPayload['type'] ?? $accountPayload['platform'] ?? $platform ?? ''));
            $externalAccountId = trim((string) ($accountPayload['id'] ?? $accountPayload['accountId'] ?? ''));
            if ($platformKey === '' || $externalAccountId === '' || ! SelfMediaPlatformCatalog::isDomestic($platformKey)) {
                continue;
            }

            $synced[] = SelfMediaAccount::query()->updateOrCreate([
                'provider' => 'aitoearn',
                'platform' => $platformKey,
                'external_account_id' => $externalAccountId,
                'owner_admin_id' => (int) $admin->id,
            ], [
                'site_id' => (int) $site->id,
                'account_name' => trim((string) ($accountPayload['nickname'] ?? $accountPayload['name'] ?? $externalAccountId)),
                'avatar' => trim((string) ($accountPayload['avatar'] ?? '')),
                'status' => 'bound',
                'auth_status' => $this->normalizeAccountStatus($accountPayload['status'] ?? 'authorized'),
                'bound_at' => now(),
                'last_synced_at' => now(),
                'raw_account' => $accountPayload,
            ]);
        }

        return new Collection($synced);
    }

    public function normalizeAccountStatus(mixed $status): string
    {
        if (is_numeric($status)) {
            return (int) $status === 1 ? 'authorized' : 'unavailable';
        }

        $status = strtolower(trim((string) $status));

        return in_array($status, ['authorized', 'success', 'normal', 'active', 'connected', 'bound'], true)
            ? 'authorized'
            : ($status === '' ? 'authorized' : $status);
    }

    private function platformDescription(string $platform, array $contentLimits): string
    {
        $modes = collect((array) ($contentLimits['modes'] ?? []))
            ->map(fn (mixed $mode): string => match ($this->stringValue($mode, ['value', 'mode', 'name'])) {
                'article', 'article2' => '文章',
                'video' => '视频',
                default => $this->stringValue($mode, ['value', 'mode', 'name']),
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $modes !== []
            ? implode(' / ', $modes)
            : SelfMediaPlatformCatalog::description($platform);
    }

    /**
     * AiToEarn 部分展示字段会返回多语言对象或包装对象，这里统一取可展示的标量字符串。
     *
     * @param  array<int,string>  $preferredKeys
     */
    private function stringValue(mixed $value, array $preferredKeys = []): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $value)) {
                $resolved = $this->stringValue($value[$key]);
                if ($resolved !== '') {
                    return $resolved;
                }
            }
        }

        foreach ($value as $item) {
            $resolved = $this->stringValue($item);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return '';
    }
}

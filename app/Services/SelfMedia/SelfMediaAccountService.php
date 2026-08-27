<?php

namespace App\Services\SelfMedia;

use App\Models\Admin;
use App\Models\SelfMediaAccount;
use App\Models\SelfMediaAccountGroup;
use App\Models\Site;
use App\Services\AiToEarn\AiToEarnClient;
use App\Services\AiToEarn\AiToEarnException;
use App\Support\SelfMedia\SelfMediaPlatformCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

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
                    'auth_supported' => $this->boolValue(data_get($item, 'capabilities.auth.supported'), true),
                    'auth_type' => $this->stringValue($item['authType'] ?? '', ['value', 'type', 'name']),
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

    public function ensureOwnerGroup(Admin $admin, Site $site): SelfMediaAccountGroup
    {
        $existing = SelfMediaAccountGroup::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('provider', 'aitoearn')
            ->whereNotNull('external_group_id')
            ->where('external_group_id', '!=', '')
            ->first();

        if ($existing instanceof SelfMediaAccountGroup) {
            return $existing;
        }

        $groupName = 'gpf-'.Str::lower(Str::random(8)).'-'.now()->format('YmdHis');
        $payload = $this->client->createAccountGroup($groupName);
        $externalGroupId = $this->remoteGroupId($payload);

        if ($externalGroupId === '') {
            throw new RuntimeException('账号分组创建失败：接口没有返回可用的分组 ID');
        }

        return SelfMediaAccountGroup::query()->updateOrCreate([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'provider' => 'aitoearn',
        ], [
            'external_group_id' => $externalGroupId,
            'group_name' => trim((string) ($payload['name'] ?? $groupName)) ?: $groupName,
            'is_default' => (bool) ($payload['isDefault'] ?? $payload['is_default'] ?? false),
            'last_synced_at' => now(),
            'raw_response' => $payload,
        ]);
    }

    /**
     * @return Collection<int,SelfMediaAccount>
     */
    public function syncOwnerAccounts(Admin $admin, Site $site, ?string $platform = null): Collection
    {
        $group = $this->ensureOwnerGroup($admin, $site);
        $externalGroupId = (string) $group->external_group_id;
        $accounts = $this->client->accounts($platform, $externalGroupId)['list'];
        $synced = [];

        foreach ($accounts as $accountPayload) {
            $platformKey = $this->remoteAccountPlatform($accountPayload, $platform);
            $externalAccountId = $this->remoteAccountId($accountPayload);
            if ($platformKey === '' || $externalAccountId === '' || ! SelfMediaPlatformCatalog::isDomestic($platformKey)) {
                continue;
            }

            $accountGroupId = $this->remoteAccountGroupId($accountPayload);
            if ($accountGroupId !== $externalGroupId) {
                continue;
            }

            $synced[] = $this->syncExactOwnerAccount($admin, $site, $accountPayload, $platformKey, $externalGroupId);
        }

        $group->forceFill(['last_synced_at' => now()])->save();

        return new Collection($synced);
    }

    /**
     * @return Collection<int,SelfMediaAccount>
     */
    public function refreshOwnerBoundAccounts(Admin $admin, Site $site): Collection
    {
        $group = $this->ensureOwnerGroup($admin, $site);
        $defaultGroupId = (string) $group->external_group_id;
        $boundAccounts = SelfMediaAccount::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('provider', 'aitoearn')
            ->where('status', 'bound')
            ->orderBy('platform')
            ->orderByDesc('bound_at')
            ->orderByDesc('id')
            ->get();

        if ($boundAccounts->isEmpty()) {
            return new Collection;
        }

        $synced = [];
        foreach ($boundAccounts->groupBy(fn (SelfMediaAccount $account): string => (string) $account->platform.'|'.(trim((string) $account->external_group_id) ?: $defaultGroupId)) as $groupKey => $accounts) {
            [$platform, $externalGroupId] = explode('|', (string) $groupKey, 2);
            $remoteAccounts = $this->remoteAccountsById((string) $platform, $externalGroupId);

            foreach ($accounts as $account) {
                $remote = $remoteAccounts[(string) $account->external_account_id] ?? null;
                if (is_array($remote)) {
                    $synced[] = $this->syncExactOwnerAccount($admin, $site, $remote, (string) $platform, $externalGroupId);

                    continue;
                }

                $account->forceFill([
                    'auth_status' => 'unavailable',
                    'last_synced_at' => now(),
                ])->save();

                $synced[] = $account->refresh();
            }
        }

        return new Collection($synced);
    }

    /**
     * @return list<string>
     */
    public function remoteAccountIds(?string $platform = null, ?string $externalGroupId = null): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $account): string => $this->remoteAccountId($account),
            $this->client->accounts($platform, $externalGroupId)['list'],
        ))));
    }

    /**
     * @param  array<int,string>  $knownAccountIds
     */
    public function syncAuthorizedOwnerAccount(
        Admin $admin,
        Site $site,
        string $platform,
        array $knownAccountIds,
        array $statusPayload = [],
        ?string $externalGroupId = null,
    ): SelfMediaAccount {
        $externalGroupId = trim((string) $externalGroupId);
        if ($externalGroupId === '') {
            $externalGroupId = (string) $this->ensureOwnerGroup($admin, $site)->external_group_id;
        }

        $remoteAccounts = $this->remoteAccountsById($platform, $externalGroupId);
        $candidateIds = $this->candidateAccountIdsFromStatusPayload($statusPayload, $remoteAccounts);

        if ($candidateIds === []) {
            $knownAccountIds = array_values(array_unique(array_filter(array_map('strval', $knownAccountIds))));
            $candidateIds = array_values(array_diff(array_keys($remoteAccounts), $knownAccountIds));
        }

        if ($candidateIds === []) {
            $candidateIds = $this->fallbackUnambiguousAccountIds(array_keys($remoteAccounts));
        }

        if (count($candidateIds) !== 1) {
            throw new RuntimeException('授权已完成，但无法安全识别本次授权账号。为避免账号串绑，请重新授权或联系管理员处理。');
        }

        $externalAccountId = (string) $candidateIds[0];
        $accountPayload = $remoteAccounts[$externalAccountId] ?? null;
        if (! is_array($accountPayload)) {
            throw new RuntimeException('授权已完成，但账号列表中没有找到本次授权账号。请重新授权或联系管理员处理。');
        }

        return $this->syncExactOwnerAccount($admin, $site, $accountPayload, $platform, $externalGroupId);
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

    private function boolValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on', 'supported', 'available' => true,
            '0', 'false', 'no', 'n', 'off', 'unsupported', 'unavailable' => false,
            default => $default,
        };
    }

    /**
     * @param  array<string,mixed>  $accountPayload
     */
    private function syncExactOwnerAccount(Admin $admin, Site $site, array $accountPayload, ?string $platform = null, ?string $externalGroupId = null): SelfMediaAccount
    {
        $platformKey = $this->remoteAccountPlatform($accountPayload, $platform);
        $externalAccountId = $this->remoteAccountId($accountPayload);
        if ($platformKey === '' || $externalAccountId === '' || ! SelfMediaPlatformCatalog::isDomestic($platformKey)) {
            throw new RuntimeException('授权账号缺少有效平台或账号 ID。');
        }

        $externalGroupId = trim((string) ($externalGroupId ?: $this->remoteAccountGroupId($accountPayload)));

        return SelfMediaAccount::query()->updateOrCreate([
            'provider' => 'aitoearn',
            'platform' => $platformKey,
            'external_account_id' => $externalAccountId,
            'owner_admin_id' => (int) $admin->id,
        ], [
            'site_id' => (int) $site->id,
            'external_group_id' => $externalGroupId !== '' ? $externalGroupId : null,
            'account_name' => trim((string) ($accountPayload['nickname'] ?? $accountPayload['name'] ?? $externalAccountId)),
            'avatar' => trim((string) ($accountPayload['avatar'] ?? '')),
            'status' => 'bound',
            'auth_status' => $this->normalizeAccountStatus($accountPayload['status'] ?? 'authorized'),
            'bound_at' => now(),
            'last_synced_at' => now(),
            'raw_account' => $accountPayload,
        ]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function remoteAccountsById(string $platform, ?string $externalGroupId = null): array
    {
        $accounts = [];
        $externalGroupId = trim((string) $externalGroupId);

        foreach ($this->client->accounts($platform, $externalGroupId !== '' ? $externalGroupId : null)['list'] as $accountPayload) {
            $platformKey = $this->remoteAccountPlatform($accountPayload, $platform);
            $externalAccountId = $this->remoteAccountId($accountPayload);
            if ($platformKey !== $platform || $externalAccountId === '' || ! SelfMediaPlatformCatalog::isDomestic($platformKey)) {
                continue;
            }

            $accountGroupId = $this->remoteAccountGroupId($accountPayload);
            if ($externalGroupId !== '' && $accountGroupId !== $externalGroupId) {
                continue;
            }

            $accounts[$externalAccountId] = $accountPayload;
        }

        return $accounts;
    }

    /**
     * @param  array<string,mixed>  $statusPayload
     * @param  array<string,array<string,mixed>>  $remoteAccounts
     * @return list<string>
     */
    private function candidateAccountIdsFromStatusPayload(array $statusPayload, array $remoteAccounts): array
    {
        $ids = [];

        foreach ([
            'accountId',
            'account_id',
            'account.accountId',
            'account.account_id',
            'account.id',
            'channelAccount.accountId',
            'channelAccount.account_id',
            'channelAccount.id',
        ] as $key) {
            $value = trim((string) data_get($statusPayload, $key, ''));
            if ($value !== '') {
                $ids[] = $value;
            }
        }

        foreach (['accounts', 'accountList', 'items'] as $key) {
            foreach ((array) data_get($statusPayload, $key, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $value = $this->remoteAccountId($item);
                if ($value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => array_key_exists($id, $remoteAccounts),
        )));
    }

    /**
     * @param  list<string>  $remoteAccountIds
     * @return list<string>
     */
    private function fallbackUnambiguousAccountIds(array $remoteAccountIds): array
    {
        if (count($remoteAccountIds) !== 1) {
            return [];
        }

        return [(string) $remoteAccountIds[0]];
    }

    /**
     * @param  array<string,mixed>  $accountPayload
     */
    private function remoteAccountId(array $accountPayload): string
    {
        return trim((string) ($accountPayload['id'] ?? $accountPayload['accountId'] ?? $accountPayload['account_id'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $accountPayload
     */
    private function remoteAccountPlatform(array $accountPayload, ?string $fallback = null): string
    {
        return trim((string) ($accountPayload['type'] ?? $accountPayload['platform'] ?? $accountPayload['appAlias'] ?? $fallback ?? ''));
    }

    /**
     * @param  array<string,mixed>  $accountPayload
     */
    private function remoteAccountGroupId(array $accountPayload): string
    {
        foreach ([
            'groupId',
            'group_id',
            'accountGroupId',
            'account_group_id',
            'group.id',
            'accountGroup.id',
        ] as $key) {
            $value = trim((string) data_get($accountPayload, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $groupPayload
     */
    private function remoteGroupId(array $groupPayload): string
    {
        return trim((string) ($groupPayload['id'] ?? $groupPayload['groupId'] ?? $groupPayload['group_id'] ?? ''));
    }
}

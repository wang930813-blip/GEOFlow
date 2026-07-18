<?php

namespace App\Services\MediaDistribution;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\Article;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\MediaSubmission;
use App\Models\PlatformPlan;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MediaSubmissionService
{
    public function __construct(
        private readonly MediaPlatformClientManager $clients,
        private readonly AdminCreditService $credits,
        private readonly AdminResourceQuotaService $quotaService,
        private readonly MediaSubmissionHtmlSanitizer $htmlSanitizer,
        private readonly MediaSubmissionBudgetGuard $budgetGuard,
    ) {}

    public function submit(
        Article $article,
        MediaResource $resource,
        Admin $admin,
        string $remark = '',
        ?int $mcpTokenId = null,
        ?string $mcpConfirmedSalePrice = null,
    ): MediaSubmission {
        if ($article->site_id === null || (int) $article->site_id <= 0) {
            throw new RuntimeException('文章缺少站点归属');
        }
        if ($resource->status !== 'active') {
            throw new RuntimeException('媒体资源不可投稿');
        }
        if (trim((string) $article->content) === '') {
            throw new RuntimeException('文章内容不能为空');
        }

        $this->quotaService->assertSubscriptionActive((int) $admin->id, (int) $article->site_id, $admin);

        if ($mcpTokenId !== null && $mcpTokenId > 0 && $mcpConfirmedSalePrice === null) {
            throw new RuntimeException('MCP 投稿缺少已确认的渠道价格');
        }

        // MCP 投稿必须使用预算预检时的价格快照，避免预检后价格变化导致实际扣费越过调用方确认上限。
        $salePrice = $mcpTokenId !== null && $mcpTokenId > 0
            ? number_format((float) $mcpConfirmedSalePrice, 2, '.', '')
            : $this->salePriceForSite($resource, (int) $article->site_id);
        $usesUnlimitedCredits = $this->usesUnlimitedCredits($admin, (int) $article->site_id);
        if (! $usesUnlimitedCredits) {
            $this->credits->ensureSufficient((int) $admin->id, (int) $article->site_id, $salePrice);
        }

        $submission = DB::transaction(function () use ($article, $resource, $admin, $remark, $salePrice, $mcpTokenId): MediaSubmission {
            $platformId = (int) ($resource->platform_id ?: MediaPlatform::CEYING_MEDIA_1);
            if ($mcpTokenId !== null && $mcpTokenId > 0) {
                $this->budgetGuard->assertDailyBudgetForSubmission(
                    $mcpTokenId,
                    (int) $admin->id,
                    (int) $article->site_id,
                    $salePrice,
                );
            }

            return MediaSubmission::query()->create([
                'site_id' => (int) $article->site_id,
                'owner_admin_id' => (int) $admin->id,
                'mcp_token_id' => $mcpTokenId,
                'article_id' => (int) $article->id,
                'media_resource_id' => (int) $resource->id,
                'platform_id' => $platformId,
                'source_type' => (string) $resource->source_type,
                'agent_order_sn' => $this->agentOrderSn($platformId, (int) $article->site_id),
                'preview_token' => Str::random(48),
                'title_snapshot' => (string) $article->title,
                'content_snapshot' => $this->htmlSanitizer->sanitize((string) $article->content),
                'cost_price_snapshot' => $resource->cost_price,
                'sale_price_snapshot' => $salePrice,
                'points_amount' => $salePrice,
                'status' => 'submitting',
                'remark' => $remark,
                'submitted_by_admin_id' => (int) $admin->id,
            ]);
        });

        $deducted = false;
        try {
            if (! $usesUnlimitedCredits) {
                $this->credits->deductForSubmission($submission, (int) $admin->id, (int) $admin->id);
                $deducted = true;
            }
            $response = $this->clients
                ->forPlatform((int) ($submission->platform_id ?: MediaPlatform::CEYING_MEDIA_1))
                ->submit($submission, $resource, $remark);

            $orderNid = $this->submittedOrderId($submission, $response);
            $submission->forceFill([
                'external_order_nid' => $orderNid,
                'status' => $orderNid !== '' ? 'submitted' : 'failed',
                'submitted_at' => now(),
                'raw_submit_response' => $response,
                'last_error_message' => $orderNid !== '' ? null : '投稿接口未返回订单号',
            ])->save();
        } catch (\Throwable $e) {
            if ($deducted) {
                $this->credits->refundForSubmission($submission, (int) $admin->id, (int) $admin->id);
            }
            $submission->forceFill([
                'status' => 'failed',
                'last_error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        return $submission;
    }

    private function usesUnlimitedCredits(Admin $admin, int $siteId): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }

        $subscription = $this->quotaService->activeSubscriptionForAdmin((int) $admin->id, $siteId);
        if (! $subscription instanceof AdminPlanSubscription) {
            return false;
        }

        $credits = data_get((array) $subscription->entitlements_snapshot, PlatformPlan::RESOURCE_CREDITS);

        return is_array($credits)
            && (bool) ($credits['enabled'] ?? false)
            && (int) ($credits['quota_value'] ?? 0) <= 0;
    }

    public function syncStatus(MediaSubmission $submission): MediaSubmission
    {
        if ((string) $submission->external_order_nid === '') {
            throw new RuntimeException('投稿订单缺少第三方订单号');
        }

        $orderNid = (string) $submission->external_order_nid;
        $response = $this->clients
            ->forPlatform((int) ($submission->platform_id ?: MediaPlatform::CEYING_MEDIA_1))
            ->orderInfo($submission);
        $data = $this->orderInfoData($response, $submission);
        $status = $this->normalizeStatus((string) ($data['status'] ?? $data['order_status'] ?? ''), (int) ($submission->platform_id ?: MediaPlatform::CEYING_MEDIA_1));
        $url = $this->publishedUrlFromOrderData($data);

        $previousStatus = (string) $submission->status;
        $submission->forceFill([
            'status' => $status !== '' ? $status : $submission->status,
            'published_url' => $url !== '' ? $url : $submission->published_url,
            'last_error_message' => (string) ($data['reason'] ?? $data['error'] ?? ''),
            'last_synced_at' => now(),
            'raw_status_response' => $response,
        ])->save();

        if (in_array((string) $submission->status, ['rejected', 'cancelled'], true)
            && ! in_array($previousStatus, ['rejected', 'cancelled'], true)) {
            $adminId = $this->submissionOwnerAdminId($submission);
            if ($adminId > 0) {
                $this->credits->refundForSubmission($submission, $adminId, null, '媒体订单'.$submission->status.'自动退回');
            }
        }

        return $submission;
    }

    /**
     * @return array{synced:int,failed:int}
     */
    public function syncPending(int $limit = 100): array
    {
        $synced = 0;
        $failed = 0;

        MediaSubmission::withoutGlobalScope('current_site')
            ->whereIn('status', ['submitting', 'submitted', 'publishing', 'appealing'])
            ->where('external_order_nid', '<>', '')
            ->orderBy('last_synced_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->each(function (MediaSubmission $submission) use (&$synced, &$failed): void {
                try {
                    $this->syncStatus($submission);
                    $synced++;
                } catch (\Throwable $e) {
                    $failed++;
                    $submission->forceFill([
                        'last_error_message' => $e->getMessage(),
                        'last_synced_at' => now(),
                    ])->save();
                }
            });

        return ['synced' => $synced, 'failed' => $failed];
    }

    public function cancel(MediaSubmission $submission, string $reason): MediaSubmission
    {
        if ((string) $submission->external_order_nid === '') {
            throw new RuntimeException('投稿订单缺少第三方订单号');
        }
        if (in_array($submission->status, ['published', 'cancelled'], true)) {
            throw new RuntimeException('当前订单状态不能取消');
        }

        $response = $this->clients
            ->forPlatform((int) ($submission->platform_id ?: MediaPlatform::CEYING_MEDIA_1))
            ->cancelOrder($submission, $reason);

        $submission->forceFill([
            'status' => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
            'raw_cancel_response' => $response,
        ])->save();
        $adminId = $this->submissionOwnerAdminId($submission);
        if ($adminId > 0) {
            $this->credits->refundForSubmission($submission, $adminId, null, '媒体订单取消退回');
        }

        return $submission;
    }

    public function appeal(MediaSubmission $submission, string $content): MediaSubmission
    {
        if ((string) $submission->external_order_nid === '') {
            throw new RuntimeException('投稿订单缺少第三方订单号');
        }
        if (! in_array($submission->status, ['rejected', 'failed'], true)) {
            throw new RuntimeException('仅失败或拒稿订单可以申诉');
        }

        $response = $this->clients
            ->forPlatform((int) ($submission->platform_id ?: MediaPlatform::CEYING_MEDIA_1))
            ->appeal($submission, $content);

        $submission->forceFill([
            'status' => 'appealing',
            'appeal_content' => $content,
            'appealed_at' => now(),
            'raw_appeal_response' => $response,
        ])->save();

        return $submission;
    }

    private function submissionOwnerAdminId(MediaSubmission $submission): int
    {
        return (int) ($submission->owner_admin_id ?: $submission->submitted_by_admin_id);
    }

    private function salePriceForSite(MediaResource $resource, int $siteId): string
    {
        $sitePrice = MediaResourceSitePrice::query()
            ->where('media_resource_id', (int) $resource->id)
            ->where('site_id', $siteId)
            ->value('sale_price');

        return number_format((float) ($sitePrice ?? $resource->sale_price), 2, '.', '');
    }

    private function normalizeStatus(string $status, int $platformId = MediaPlatform::CEYING_MEDIA_1): string
    {
        $status = strtolower(trim($status));

        if ($platformId === MediaPlatform::CEYING_MEDIA_2) {
            return match ($status) {
                '1' => 'submitted',
                '2', '8' => 'rejected',
                '3', '10' => 'publishing',
                '4', '11', '12' => 'published',
                '5' => 'cancelled',
                '6' => 'appealing',
                '7' => 'rejected',
                '9' => 'failed',
                default => '',
            };
        }

        return match ($status) {
            'published', 'success', 'done', '2', '已发布', '发布成功' => 'published',
            'rejected', 'reject', 'failed', '4', '已退稿', '已拒稿', '退稿', '拒稿' => 'rejected',
            'cancelled', 'canceled', '已取消', '取消' => 'cancelled',
            'appealing', 'after_sale', 'aftersale', '9', '售后中', '申诉中' => 'appealing',
            'publishing', 'processing', '1', '已安排', '发布中' => 'publishing',
            'submitting', '提交中' => 'submitting',
            'submitted', 'pending', '0', '待安排', '已提交', '待发布', '待审核' => 'submitted',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $response
     * @return array<string,mixed>
     */
    private function orderInfoData(array $response, MediaSubmission $submission): array
    {
        $orderNid = (string) $submission->external_order_nid;
        $agentOrderSn = (string) $submission->agent_order_sn;
        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        if (isset($data['status']) || isset($data['order_status'])) {
            return $data;
        }
        if (isset($data[$orderNid]) && is_array($data[$orderNid])) {
            return $data[$orderNid];
        }
        if ($agentOrderSn !== '' && isset($data[$agentOrderSn]) && is_array($data[$agentOrderSn])) {
            return $data[$agentOrderSn];
        }

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowOrderNid = (string) ($row['order_nid'] ?? $row['nid'] ?? $row['order_id'] ?? $row['id'] ?? '');
            $rowAgentSn = (string) ($row['sn'] ?? '');
            if ($rowOrderNid === $orderNid || ($rowAgentSn !== '' && ($rowAgentSn === $orderNid || $rowAgentSn === $agentOrderSn))) {
                return $row;
            }
        }

        $first = reset($data);

        return is_array($first) ? $first : [];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function publishedUrlFromOrderData(array $data): string
    {
        foreach (['url', 'link', 'published_url', 'publish_url', 'article_url', 'content_url', 'news_url', 'show_url'] as $key) {
            $url = trim((string) ($data[$key] ?? ''));
            if ($this->looksLikeUrl($url)) {
                return $url;
            }
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $url = $this->publishedUrlFromOrderData($value);
                if ($url !== '') {
                    return $url;
                }

                continue;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $key = strtolower((string) $key);
            $url = trim((string) $value);
            if (str_contains($key, 'url') && $this->looksLikeUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    private function looksLikeUrl(string $value): bool
    {
        return preg_match('#^https?://#i', trim($value)) === 1;
    }

    private function agentOrderSn(int $platformId, int $siteId): string
    {
        return 'geoflow-'.$platformId.'-'.$siteId.'-'.Str::lower(Str::random(18));
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function submittedOrderId(MediaSubmission $submission, array $response): string
    {
        if ((int) ($submission->platform_id ?: MediaPlatform::CEYING_MEDIA_1) === MediaPlatform::CEYING_MEDIA_2) {
            return (string) data_get($response, 'data.partner_sn', $submission->agent_order_sn);
        }

        return (string) data_get($response, 'data.order_nid', '');
    }
}

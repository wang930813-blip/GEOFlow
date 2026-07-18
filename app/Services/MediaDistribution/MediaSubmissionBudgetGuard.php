<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 16:50
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MediaSubmissionBudgetGuard.php
 *
 * @Description: 在媒体投稿产生订单和扣费前校验 MCP 调用声明的单渠道及请求总预算。
 */

namespace App\Services\MediaDistribution;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\MediaResource;
use App\Models\MediaResourceSitePrice;
use App\Models\MediaSubmission;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class MediaSubmissionBudgetGuard
{
    /**
     * @Name: assertWithinBudget
     *
     * @Description: 使用当前站点实时售价计算全部文章和渠道组合费用，任一价格越界时在投稿前终止请求。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:50:00
     *
     * @UpdateTime: 2026-07-18 16:50:00
     *
     * @Param: Collection<int, MediaResource> $resources 待投稿媒体资源
     *
     * @Param: int $siteId 当前站点编号
     *
     * @Param: int $articleCount 有效文章数量
     *
     * @Param: string $maxUnitPrice 客户端确认的单渠道最高价格
     *
     * @Param: string $maxTotalPrice 客户端确认的本次最高总价
     *
     * @Return: array{actual_total_price: string, max_unit_price: string, max_total_price: string, resource_prices: array<int, string>} 预算核验结果及渠道价格快照
     *
     * @Throws: ApiException 实际单价或总价超过客户端确认预算
     */
    public function assertWithinBudget(
        Collection $resources,
        int $siteId,
        int $articleCount,
        string $maxUnitPrice,
        string $maxTotalPrice,
        array $keyPolicy,
    ): array {
        $maxUnitCents = $this->moneyToCents($maxUnitPrice);
        $maxTotalCents = $this->moneyToCents($maxTotalPrice);
        $keyMaxUnitCents = $this->moneyToCents($keyPolicy['mcp_max_unit_price'] ?? 0);
        $keyMaxTotalCents = $this->moneyToCents($keyPolicy['mcp_max_total_price'] ?? 0);
        $dailyLimitCents = $this->moneyToCents($keyPolicy['mcp_daily_spend_limit'] ?? 0);
        if ($keyMaxUnitCents <= 0 || $keyMaxTotalCents <= 0 || $dailyLimitCents <= 0) {
            throw new ApiException('mcp_spending_policy_required', '当前 MCP Key 未配置消费策略，请重新创建 Key', 403);
        }
        if ($maxUnitCents > $keyMaxUnitCents || $maxTotalCents > $keyMaxTotalCents) {
            throw new ApiException('mcp_key_budget_exceeded', '本次声明预算超过 MCP Key 消费策略', 422, [
                'key_max_unit_price' => $this->centsToMoney($keyMaxUnitCents),
                'key_max_total_price' => $this->centsToMoney($keyMaxTotalCents),
            ]);
        }
        $sitePrices = MediaResourceSitePrice::query()
            ->where('site_id', $siteId)
            ->whereIn('media_resource_id', $resources->pluck('id'))
            ->pluck('sale_price', 'media_resource_id');

        $resourceTotalCents = 0;
        $resourcePrices = [];
        foreach ($resources as $resource) {
            $actualPrice = $sitePrices->get((int) $resource->id, $resource->sale_price);
            $actualCents = $this->moneyToCents($actualPrice);
            if ($actualCents > $maxUnitCents) {
                throw new ApiException('media_unit_budget_exceeded', '媒体渠道实际售价超过已确认的单渠道预算', 422, [
                    'media_resource_id' => (int) $resource->id,
                    'actual_price' => $this->centsToMoney($actualCents),
                    'max_unit_price' => $this->centsToMoney($maxUnitCents),
                ]);
            }

            $resourceTotalCents += $actualCents;
            $resourcePrices[(int) $resource->id] = $this->centsToMoney($actualCents);
        }

        $actualTotalCents = $resourceTotalCents * max(0, $articleCount);
        if ($actualTotalCents > $maxTotalCents) {
            throw new ApiException('media_total_budget_exceeded', '媒体投稿实际总价超过已确认的本次预算', 422, [
                'actual_total_price' => $this->centsToMoney($actualTotalCents),
                'max_total_price' => $this->centsToMoney($maxTotalCents),
            ]);
        }

        return [
            'actual_total_price' => $this->centsToMoney($actualTotalCents),
            'max_unit_price' => $this->centsToMoney($maxUnitCents),
            'max_total_price' => $this->centsToMoney($maxTotalCents),
            'resource_prices' => $resourcePrices,
        ];
    }

    /**
     * @Name: assertDailyBudgetForSubmission
     *
     * @Description: 锁定 MCP Token 后统计当日有效投稿金额，保证并发投稿不能突破 Key 每日消费上限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 17:30:00
     *
     * @UpdateTime: 2026-07-18 17:30:00
     *
     * @Param: int $tokenId MCP Token 编号
     *
     * @Param: int $adminId Token 所属账号编号
     *
     * @Param: int $siteId Token 绑定站点编号
     *
     * @Param: string $nextAmount 本次待创建投稿金额
     *
     * @Return: void
     *
     * @Throws: ApiException Token 策略不可用或累计金额超过每日上限
     */
    public function assertDailyBudgetForSubmission(int $tokenId, int $adminId, int $siteId, string $nextAmount): void
    {
        $token = PersonalAccessToken::query()
            ->where('tokenable_type', Admin::class)
            ->where('tokenable_id', $adminId)
            ->where('site_id', $siteId)
            ->whereKey($tokenId)
            ->lockForUpdate()
            ->first();
        $dailyLimitCents = $token instanceof PersonalAccessToken
            ? $this->moneyToCents($token->mcp_daily_spend_limit)
            : 0;
        if ($dailyLimitCents <= 0) {
            throw new ApiException('mcp_spending_policy_required', '当前 MCP Key 未配置每日消费上限，请重新创建 Key', 403);
        }

        $spent = MediaSubmission::withoutGlobalScopes()
            ->where('mcp_token_id', $tokenId)
            ->where('site_id', $siteId)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereNotIn('status', ['failed', 'rejected', 'cancelled'])
            ->sum('points_amount');
        $spentCents = $this->moneyToCents($spent);
        $nextCents = $this->moneyToCents($nextAmount);
        if ($spentCents + $nextCents > $dailyLimitCents) {
            throw new ApiException('mcp_daily_budget_exceeded', '本次投稿将超过 MCP Key 每日消费上限', 422, [
                'spent_today' => $this->centsToMoney($spentCents),
                'next_amount' => $this->centsToMoney($nextCents),
                'daily_spend_limit' => $this->centsToMoney($dailyLimitCents),
            ]);
        }
    }

    /**
     * @Name: moneyToCents
     *
     * @Description: 将已通过接口数值验证的金额统一转换为整数分，避免预算比较出现浮点误差。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:50:00
     *
     * @UpdateTime: 2026-07-18 16:50:00
     *
     * @Param: mixed $value 金额
     *
     * @Return: int 金额分值
     */
    private function moneyToCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * @Name: centsToMoney
     *
     * @Description: 将整数分格式化为标准两位小数金额。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:50:00
     *
     * @UpdateTime: 2026-07-18 16:50:00
     *
     * @Param: int $cents 金额分值
     *
     * @Return: string 两位小数金额
     */
    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

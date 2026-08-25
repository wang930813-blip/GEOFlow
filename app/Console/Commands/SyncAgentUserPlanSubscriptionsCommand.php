<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Billing\AdminPlanSubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncAgentUserPlanSubscriptionsCommand extends Command
{
    protected $signature = 'geoflow:sync-agent-user-subscriptions
        {--agent-id= : Only sync one agent admin ID}
        {--grant-credits : Grant credit entitlements while creating repaired child subscriptions}';

    protected $description = 'Sync existing agent users to their agent owner current plan subscription.';

    public function handle(AdminPlanSubscriptionService $subscriptions): int
    {
        $agentId = (int) ($this->option('agent-id') ?? 0);
        $grantCredits = (bool) $this->option('grant-credits');

        $agents = Admin::query()
            ->where('role', 'agent_admin')
            ->when($agentId > 0, fn (Builder $query) => $query->whereKey($agentId))
            ->whereHas('accountPlanSubscriptions', fn (Builder $query) => $query
                ->where('mode', 'agent_owner')
                ->activeNow())
            ->orderBy('id')
            ->get(['id', 'username', 'display_name', 'role']);

        if ($agentId > 0 && $agents->isEmpty()) {
            $this->error('未找到有效代理规格，或该账号不是代理。');

            return self::FAILURE;
        }

        $synced = 0;
        foreach ($agents as $agent) {
            $count = $subscriptions->refreshInheritedAgentUsersFromCurrentPlan($agent, null, $grantCredits);
            $synced += $count;
            $this->line(sprintf(
                '代理 %s 同步 %d 个下属用户规格',
                $agent->display_name ?: $agent->username,
                $count
            ));
        }

        $this->info(sprintf('代理用户规格同步完成：处理代理 %d 个，刷新下属用户 %d 个。', $agents->count(), $synced));

        return self::SUCCESS;
    }
}

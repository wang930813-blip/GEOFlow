<?php

namespace App\Console\Commands;

use App\Models\CrebeeAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CrebeeAgentCreateCommand extends Command
{
    protected $signature = 'crebee:agent-create
        {agent_uid : Bridge Agent 唯一标识}
        {--name= : Agent 显示名称}
        {--secret= : 指定 Agent secret；不传则自动生成}
        {--base-url=http://127.0.0.1:3456 : 运维电脑本机 CreBee 地址}
        {--rotate : 已存在时轮换 secret}';

    protected $description = 'Create CreBee Bridge Agent credentials';

    public function handle(): int
    {
        $agentUid = trim((string) $this->argument('agent_uid'));
        if ($agentUid === '') {
            $this->error('agent_uid 不能为空');

            return self::INVALID;
        }

        $agent = CrebeeAgent::query()->firstOrNew(['agent_uid' => $agentUid]);
        if ($agent->exists && ! (bool) $this->option('rotate')) {
            $this->error('Agent 已存在。如需轮换 secret，请加 --rotate');

            return self::FAILURE;
        }

        $secret = trim((string) ($this->option('secret') ?: ''));
        if ($secret === '') {
            $secret = Str::password(40, symbols: false);
        }

        $agent->fill([
            'name' => trim((string) ($this->option('name') ?: '')) ?: $agentUid,
            'secret_hash' => Hash::make($secret),
            'status' => 'active',
            'crebee_base_url' => trim((string) $this->option('base-url')) ?: 'http://127.0.0.1:3456',
        ])->save();

        $this->info('CreBee Bridge Agent 已创建。secret 仅显示本次，请保存到本地 Agent .env。');
        $this->line('CREBEE_AGENT_ID='.$agentUid);
        $this->line('CREBEE_AGENT_SECRET='.$secret);

        return self::SUCCESS;
    }
}

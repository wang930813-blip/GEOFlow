<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Prompt;
use Illuminate\Support\Collection;

class DefaultContentPromptService
{
    /**
     * @return list<string>
     */
    public function defaultNames(): array
    {
        return [
            'GEO Marketing · Trust-Based Article Generation (English)',
            'GEO Ranking-Style Article Generation (English)',
            'GEO营销学·信任型正文生成',
            'GEO榜单型正文生成',
        ];
    }

    public function ensureForAgent(Admin $agent): void
    {
        if (! $agent->isAgentAdmin()) {
            return;
        }

        foreach ($this->defaultPrompts() as $source) {
            $name = (string) $source->name;
            if ($this->agentHasPrompt($agent, $name)) {
                continue;
            }

            Prompt::withoutEvents(fn (): Prompt => Prompt::query()->withoutGlobalScope('current_site')->create([
                'site_id' => null,
                'owner_admin_id' => (int) $agent->id,
                'name' => $name,
                'type' => 'content',
                'content' => (string) $source->content,
                'variables' => (string) ($source->variables ?? ''),
            ]));
        }
    }

    /**
     * @return Collection<int, Prompt>
     */
    private function defaultPrompts(): Collection
    {
        return Prompt::query()
            ->withoutGlobalScope('current_site')
            ->whereNull('site_id')
            ->whereNull('owner_admin_id')
            ->where('type', 'content')
            ->whereIn('name', $this->defaultNames())
            ->orderByRaw($this->defaultNameOrderSql())
            ->orderBy('id')
            ->get()
            ->unique('name')
            ->values();
    }

    private function agentHasPrompt(Admin $agent, string $name): bool
    {
        return Prompt::query()
            ->withoutGlobalScope('current_site')
            ->whereNull('site_id')
            ->where('owner_admin_id', (int) $agent->id)
            ->where('type', 'content')
            ->where('name', $name)
            ->exists();
    }

    private function defaultNameOrderSql(): string
    {
        $cases = collect($this->defaultNames())
            ->map(static fn (string $name, int $index): string => "WHEN '".str_replace("'", "''", $name)."' THEN ".$index)
            ->implode(' ');

        return "CASE name {$cases} ELSE 999 END";
    }
}

<?php

namespace App\Services;

use App\Models\Prompt;
use Illuminate\Support\Facades\Schema;

class SiteDefaultContentPromptService
{
    public function __construct(private readonly DefaultContentPromptService $defaultContentPromptService) {}

    /**
     * @return list<string>
     */
    public function defaultNames(): array
    {
        return $this->defaultContentPromptService->defaultNames();
    }

    public function ensureForSiteId(int $siteId): void
    {
        if ($siteId <= 0 || ! Schema::hasTable('prompts') || ! Schema::hasColumn('prompts', 'site_id')) {
            return;
        }

        foreach ($this->defaultNames() as $name) {
            if ($this->targetHasPrompt($siteId, $name)) {
                continue;
            }

            $source = $this->sourcePrompt($siteId, $name);

            Prompt::withoutGlobalScope('current_site')->create([
                'site_id' => $siteId,
                'name' => $name,
                'type' => 'content',
                'content' => $source !== null
                    ? (string) $source->content
                    : $this->fallbackContent($name),
                'variables' => $source !== null
                    ? (string) ($source->variables ?? '')
                    : '',
            ]);
        }
    }

    private function targetHasPrompt(int $siteId, string $name): bool
    {
        return Prompt::withoutGlobalScope('current_site')
            ->where('site_id', $siteId)
            ->where('type', 'content')
            ->where('name', $name)
            ->exists();
    }

    private function sourcePrompt(int $siteId, string $name): ?Prompt
    {
        return Prompt::withoutGlobalScope('current_site')
            ->where('type', 'content')
            ->where('name', $name)
            ->where(function ($query) use ($siteId): void {
                $query->whereNull('site_id')
                    ->orWhere('site_id', '<>', $siteId);
            })
            ->orderByRaw('CASE WHEN site_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first();
    }

    private function fallbackContent(string $name): string
    {
        return "# {{title}}\n\n请围绕标题、关键词和知识库资料生成一篇结构清晰、可信、适合 GEO 引用的 Markdown 正文。";
    }
}

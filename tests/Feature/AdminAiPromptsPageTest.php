<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Prompt;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAiPromptsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_content_prompts_are_visible(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-admin@example.com',
            'display_name' => 'AI Prompt Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertSee('GEO营销学·信任型正文生成')
            ->assertSee('GEO榜单型正文生成')
            ->assertSee('GEO Marketing · Trust-Based Article Generation (English)')
            ->assertSee('GEO Ranking-Style Article Generation (English)');
    }

    public function test_site_level_default_content_prompts_are_not_shown_as_duplicate_account_prompts(): void
    {
        $admin = Admin::query()->create([
            'username' => 'ai_prompt_dedupe_admin',
            'password' => 'secret-123',
            'email' => 'ai-prompt-dedupe-admin@example.com',
            'display_name' => 'AI Prompt Dedupe Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Legacy Prompt Site',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        Prompt::withoutEvents(fn (): Prompt => Prompt::query()->withoutGlobalScope('current_site')->create([
            'site_id' => null,
            'owner_admin_id' => null,
            'name' => '平台自定义正文提示词',
            'type' => 'content',
            'content' => 'Custom platform content prompt.',
            'variables' => '',
        ]));

        $expectedNames = [
            ...$this->defaultContentPromptNames(),
            '平台自定义正文提示词',
        ];

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ai-prompts'))
            ->assertOk()
            ->assertViewHas('prompts', function (array $prompts) use ($expectedNames): bool {
                $names = collect($prompts)->pluck('name')->values();

                return $names->count() === count($expectedNames)
                    && $names->unique()->count() === count($expectedNames)
                    && $names->sort()->values()->all() === collect($expectedNames)->sort()->values()->all();
            });
    }

    /**
     * @return list<string>
     */
    private function defaultContentPromptNames(): array
    {
        return [
            'GEO Marketing · Trust-Based Article Generation (English)',
            'GEO Ranking-Style Article Generation (English)',
            'GEO营销学·信任型正文生成',
            'GEO榜单型正文生成',
        ];
    }
}

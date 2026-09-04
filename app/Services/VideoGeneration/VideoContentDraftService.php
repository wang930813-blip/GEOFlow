<?php

namespace App\Services\VideoGeneration;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Site;
use App\Support\AiConfigurationScope;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class VideoContentDraftService
{
    private const STYLE_MAP = [
        'question' => '问题型',
        'avoid_pitfall' => '避坑型',
        'how_to_choose' => '怎么选型',
        'comparison' => '对比型',
        'scenario' => '场景型',
        'trend' => '趋势型',
    ];

    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiConfigurationScope $aiConfigurationScope
    ) {}

    /**
     * @return array{keyword_library_id:int,knowledge_base_id:?int,keyword_id:int,keyword:string,candidates:list<array{style:string,style_label:string,subject:string}>}
     */
    public function topicCandidates(Admin $admin, Site $site, int $keywordLibraryId, ?int $knowledgeBaseId = null): array
    {
        $library = $this->findKeywordLibrary($admin, $site, $keywordLibraryId);
        $keyword = $this->randomKeyword($admin, $site, $library);
        $knowledgeBase = $knowledgeBaseId !== null && $knowledgeBaseId > 0
            ? $this->findKnowledgeBase($admin, $site, $knowledgeBaseId)
            : null;

        $prompt = $this->buildTopicPrompt($library, $keyword, $knowledgeBase);
        $content = $this->generateText(
            'video_topic_draft',
            '你是抖音 GEO 短视频选题策划，只输出可解析 JSON。',
            $prompt,
            $admin
        );

        return [
            'keyword_library_id' => (int) $library->id,
            'knowledge_base_id' => $knowledgeBase instanceof KnowledgeBase ? (int) $knowledgeBase->id : null,
            'keyword_id' => (int) $keyword->id,
            'keyword' => (string) $keyword->keyword,
            'candidates' => $this->normalizeTopicCandidates($content, (string) $keyword->keyword),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{subject:string,style:string,style_label:string,script:string,cover_text:string,publish_copy:string}
     */
    public function scriptDraft(Admin $admin, Site $site, array $payload): array
    {
        $keywordLibraryId = (int) ($payload['keyword_library_id'] ?? 0);
        $library = $keywordLibraryId > 0 ? $this->findKeywordLibrary($admin, $site, $keywordLibraryId) : null;
        $knowledgeBaseId = (int) ($payload['knowledge_base_id'] ?? 0);
        $knowledgeBase = $knowledgeBaseId > 0 ? $this->findKnowledgeBase($admin, $site, $knowledgeBaseId) : null;
        $subject = $this->normalizeText((string) ($payload['subject'] ?? ''), 200);
        if ($subject === '') {
            throw new RuntimeException('视频主题不能为空');
        }

        $style = $this->normalizeStyle((string) ($payload['style'] ?? 'question'));
        $keyword = $this->normalizeText((string) ($payload['keyword'] ?? ''), 100);
        $prompt = $this->buildScriptPrompt($subject, $style, $keyword, $library, $knowledgeBase);
        $content = $this->generateText(
            'video_script_draft',
            '你是抖音 GEO 短视频口播脚本编辑，只输出可解析 JSON。',
            $prompt,
            $admin
        );

        return $this->normalizeScriptDraft($content, $subject, $style);
    }

    private function findKeywordLibrary(Admin $admin, Site $site, int $keywordLibraryId): KeywordLibrary
    {
        $query = KeywordLibrary::query()
            ->withoutGlobalScopes()
            ->whereKey($keywordLibraryId)
            ->where('site_id', (int) $site->id);

        if (! $admin->isSuperAdmin()) {
            $query->where('owner_admin_id', (int) $admin->id);
        }

        $library = $query->first();
        if (! $library instanceof KeywordLibrary) {
            throw new RuntimeException('关键词库不存在或无权访问');
        }

        return $library;
    }

    private function findKnowledgeBase(Admin $admin, Site $site, int $knowledgeBaseId): KnowledgeBase
    {
        $query = KnowledgeBase::query()
            ->withoutGlobalScopes()
            ->whereKey($knowledgeBaseId)
            ->where('site_id', (int) $site->id);

        if (! $admin->isSuperAdmin()) {
            $query->where('owner_admin_id', (int) $admin->id);
        }

        $knowledgeBase = $query->first();
        if (! $knowledgeBase instanceof KnowledgeBase) {
            throw new RuntimeException('知识库不存在或无权访问');
        }

        return $knowledgeBase;
    }

    private function randomKeyword(Admin $admin, Site $site, KeywordLibrary $library): Keyword
    {
        $query = Keyword::query()
            ->withoutGlobalScopes()
            ->where('library_id', (int) $library->id)
            ->where('site_id', (int) $site->id);

        if (! $admin->isSuperAdmin()) {
            $query->where('owner_admin_id', (int) $admin->id);
        }

        $keyword = $query->inRandomOrder()->first();
        if (! $keyword instanceof Keyword) {
            throw new RuntimeException('该关键词库暂无关键词，无法自动生成视频主题');
        }

        return $keyword;
    }

    private function resolveGpt55Model(Admin $admin): AiModel
    {
        $model = $this->aiConfigurationScope->applyOwnerIdScope(
            AiModel::query()->withoutGlobalScope('current_site'),
            $this->aiConfigurationScope->ownerAdminIdForConsumer($admin),
            'ai_models.owner_admin_id'
        )
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%gpt-5.5%'])
                    ->orWhereRaw('LOWER(model_id) LIKE ?', ['%gpt-5.5%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%gpt5.5%'])
                    ->orWhereRaw('LOWER(model_id) LIKE ?', ['%gpt5.5%']);
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->first();

        if (! $model instanceof AiModel) {
            throw new RuntimeException('请先在 AI 配置中添加并启用 GPT-5.5 Chat 模型');
        }

        return $model;
    }

    private function generateText(string $slot, string $instructions, string $prompt, Admin $admin): string
    {
        $model = $this->resolveGpt55Model($admin);
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('GPT-5.5 模型 API URL 未配置');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('GPT-5.5 模型 API Key 未配置或无法解密');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider($slot, $driver, $providerUrl, $apiKey);

        try {
            $agent = new MarkdownContentWriterAgent($instructions);
            $response = $agent->prompt($prompt, [], $providerName, (string) ($model->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('GPT-5.5 视频内容生成失败：'.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($content === '') {
            throw new RuntimeException('GPT-5.5 未返回可用的视频内容');
        }

        AiModel::query()->withoutGlobalScope('current_site')->whereKey((int) $model->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
    }

    private function buildTopicPrompt(KeywordLibrary $library, Keyword $keyword, ?KnowledgeBase $knowledgeBase): string
    {
        $context = $this->contextLines($library, $knowledgeBase);
        $styles = collect(self::STYLE_MAP)
            ->map(fn (string $label, string $style): string => $style.'='.$label)
            ->implode('；');

        return <<<PROMPT
请基于关键词库和知识库，生成 6 个抖音 GEO 短视频主题候选。

关键词：{$keyword->keyword}
主题风格必须一一对应：{$styles}

{$context}

要求：
1. 每个主题只回答一个真实用户会搜索的问题，避免企业宣传片口吻。
2. 标题、口播、字幕和发布文案后续要能围绕同一个核心问题展开。
3. 每个主题适合 40-60 秒短视频，长度控制在 12-28 个中文字符。
4. 自然服务于 AI 搜索引用和抖音搜索理解，不机械堆关键词。
5. 只输出 JSON 数组，不要 Markdown，不要解释。

JSON 格式：
[
  {"style":"question","style_label":"问题型","subject":"..."},
  {"style":"avoid_pitfall","style_label":"避坑型","subject":"..."},
  {"style":"how_to_choose","style_label":"怎么选型","subject":"..."},
  {"style":"comparison","style_label":"对比型","subject":"..."},
  {"style":"scenario","style_label":"场景型","subject":"..."},
  {"style":"trend","style_label":"趋势型","subject":"..."}
]
PROMPT;
    }

    private function buildScriptPrompt(
        string $subject,
        string $style,
        string $keyword,
        ?KeywordLibrary $library,
        ?KnowledgeBase $knowledgeBase
    ): string {
        $context = $library instanceof KeywordLibrary
            ? $this->contextLines($library, $knowledgeBase)
            : '暂无关键词库品牌资料。';
        $styleLabel = self::STYLE_MAP[$style] ?? self::STYLE_MAP['question'];

        return <<<PROMPT
请根据用户选择的视频主题，生成一条可直接用于配音/口播的抖音 GEO 短视频脚本。

视频主题：{$subject}
视频风格：{$styleLabel}
关键词：{$keyword}

{$context}

脚本节奏固定为 40-60 秒：
1. 0-3秒：直接抛出主题里的核心问题，不要先说“大家好”。
2. 3-10秒：指出一个常见痛点、误区或反常识。
3. 10-35秒：只讲 2-3 个核心判断点，结构清楚比知识点多更重要。
4. 35-50秒：公司或品牌自然出现 1 次，并和当前主题能力建立关系。
5. 50-60秒：用一句明确结论收尾。

同时给出封面文案和抖音发布文案：
- 封面文案采用知识分享型/编辑型/简洁型。
- 发布文案控制在 60-100 个汉字左右，第一句重新出现核心搜索问题。

只输出 JSON 对象，不要 Markdown，不要解释：
{"subject":"...","style":"{$style}","script":"...","cover_text":"...","publish_copy":"..."}
PROMPT;
    }

    private function contextLines(KeywordLibrary $library, ?KnowledgeBase $knowledgeBase): string
    {
        $lines = [
            '关键词库资料：',
            '- 公司/品牌：'.$this->normalizeText((string) ($library->company_name ?? ''), 200),
            '- 领域关键词：'.$this->normalizeText((string) ($library->domain_keyword ?? ''), 200),
            '- 行业：'.$this->normalizeText((string) ($library->industry ?? ''), 200),
            '- 品牌介绍：'.$this->normalizeText((string) ($library->brand_description ?? $library->description ?? ''), 1200),
        ];

        if ($knowledgeBase instanceof KnowledgeBase) {
            $lines[] = '知识库内容：'.$this->normalizeText((string) ($knowledgeBase->content ?? ''), 3000);
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * @return list<array{style:string,style_label:string,subject:string}>
     */
    private function normalizeTopicCandidates(string $content, string $keyword): array
    {
        $decoded = $this->decodeJson($content);
        $items = is_array($decoded) ? $this->candidateItems($decoded) : $this->lineItems($content);
        $byStyle = [];

        foreach ($items as $item) {
            $style = $this->normalizeStyle((string) ($item['style'] ?? ''));
            $subject = $this->normalizeText((string) ($item['subject'] ?? $item['title'] ?? $item['text'] ?? ''), 80);
            if ($subject === '') {
                continue;
            }

            $byStyle[$style] ??= [
                'style' => $style,
                'style_label' => self::STYLE_MAP[$style],
                'subject' => $subject,
            ];
        }

        foreach (self::STYLE_MAP as $style => $label) {
            if (! isset($byStyle[$style])) {
                $byStyle[$style] = [
                    'style' => $style,
                    'style_label' => $label,
                    'subject' => $this->fallbackSubject($style, $keyword),
                ];
            }
        }

        return collect(array_keys(self::STYLE_MAP))
            ->map(fn (string $style): array => $byStyle[$style])
            ->values()
            ->all();
    }

    /**
     * @return array{subject:string,style:string,style_label:string,script:string,cover_text:string,publish_copy:string}
     */
    private function normalizeScriptDraft(string $content, string $fallbackSubject, string $fallbackStyle): array
    {
        $decoded = $this->decodeJson($content);
        $data = is_array($decoded) ? $decoded : ['script' => $content];
        $style = $this->normalizeStyle((string) ($data['style'] ?? $fallbackStyle));
        $subject = $this->normalizeText((string) ($data['subject'] ?? $fallbackSubject), 200) ?: $fallbackSubject;
        $script = $this->normalizeMultiline((string) ($data['script'] ?? $data['content'] ?? $content), 5000);
        if ($script === '') {
            throw new RuntimeException('GPT-5.5 未返回可用的视频脚本');
        }

        return [
            'subject' => $subject,
            'style' => $style,
            'style_label' => self::STYLE_MAP[$style],
            'script' => $script,
            'cover_text' => $this->normalizeText((string) ($data['cover_text'] ?? ''), 80),
            'publish_copy' => $this->normalizeText((string) ($data['publish_copy'] ?? ''), 300),
        ];
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<array<string,mixed>>
     */
    private function candidateItems(array $decoded): array
    {
        if (isset($decoded['candidates']) && is_array($decoded['candidates'])) {
            $decoded = $decoded['candidates'];
        }

        $items = [];
        foreach ($decoded as $key => $item) {
            if (is_array($item)) {
                $items[] = $item;
            } elseif (is_string($item) || is_numeric($item)) {
                $items[] = ['style' => is_string($key) ? $key : '', 'subject' => (string) $item];
            }
        }

        return $items;
    }

    /**
     * @return list<array{style:string,subject:string}>
     */
    private function lineItems(string $content): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-*]|\d+[.)、])\s*/u', '', $line));
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[:：|]/u', $line, 2);
            $items[] = [
                'style' => is_array($parts) && count($parts) === 2 ? $this->styleFromLabel($parts[0]) : '',
                'subject' => is_array($parts) && count($parts) === 2 ? $parts[1] : $line,
            ];
        }

        return $items;
    }

    private function decodeJson(string $content): mixed
    {
        $content = $this->stripCodeFence($content);
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        if (preg_match('/(\[.*\]|\{.*\})/su', $content, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode((string) $matches[1], true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function normalizeStyle(string $style): string
    {
        $style = trim($style);
        if (isset(self::STYLE_MAP[$style])) {
            return $style;
        }

        return $this->styleFromLabel($style);
    }

    private function styleFromLabel(string $label): string
    {
        $label = trim($label);
        foreach (self::STYLE_MAP as $style => $styleLabel) {
            if ($label === $styleLabel || str_contains($label, $styleLabel)) {
                return $style;
            }
        }

        return 'question';
    }

    private function fallbackSubject(string $style, string $keyword): string
    {
        $keyword = $this->normalizeText($keyword, 40) ?: '这个问题';

        return match ($style) {
            'avoid_pitfall' => $keyword.'别只看表面',
            'how_to_choose' => $keyword.'怎么选更靠谱',
            'comparison' => $keyword.'常见方案怎么比',
            'scenario' => $keyword.'适合哪些场景',
            'trend' => $keyword.'为什么越来越重要',
            default => $keyword.'怎么判断',
        };
    }

    private function stripCodeFence(string $content): string
    {
        $content = trim($content);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $content, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return $content;
    }

    private function normalizeText(string $text, int $limit): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $text = preg_replace('/^[\s"\'`\[\]{}()（）【】<>《》]+|[\s"\'`\[\]{}()（）【】<>《》]+$/u', '', $text) ?? $text;

        return $limit > 0 ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
    }

    private function normalizeMultiline(string $text, int $limit): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return $limit > 0 ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
    }
}

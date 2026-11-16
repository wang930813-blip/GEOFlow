<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 10:59:25
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteArticleDraftService.php
 * @Description: 使用当前站点已配置的对话模型生成官网文章草稿。
 */

namespace App\Services\WebsiteAdmin;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\Site;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class WebsiteArticleDraftService
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @Name: availableModels
     * @Description: 只列出当前站点所有者启用的对话模型，不向页面暴露接口密钥。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 10:59:25
     * @UpdateTime: 2026-11-12 10:59:25
     * @Param Site $site 当前客户站点
     * @Param Admin $owner 站点 GEO 所有者
     * @Return Collection 当前站点可选模型的公开字段
     * @Throws \Throwable 模型配置查询失败
     */
    public function availableModels(Site $site, Admin $owner): Collection
    {
        return $this->availableModelQuery($site, $owner)->get(['id', 'name', 'model_id']);
    }

    /**
     * @Name: generate
     * @Description: 按当前站点资料生成结构化文章草稿，并只接受当前站点已有分类。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:14:40
     * @UpdateTime: 2026-11-12 11:55:15
     * @Param Site $site 当前客户站点
     * @Param Admin $owner 站点 GEO 所有者
     * @Param int $modelId 用户选择的模型记录 ID
     * @Param string $topic 用户提供的文章主题
     * @Param string $requirements 用户提供的写作要求
     * @Return array 已生成的正文及可编辑文章属性
     * @Throws RuntimeException 模型不存在、调用失败或返回结构不完整时抛出
     */
    public function generate(Site $site, Admin $owner, int $modelId, string $topic, string $requirements = ''): array
    {
        $model = $this->availableModelQuery($site, $owner)->whereKey($modelId)->first();

        if (! $model instanceof AiModel) {
            throw new RuntimeException('所选文章生成模型不可用，请重新选择');
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($providerUrl === '' || $apiKey === '') {
            throw new RuntimeException('文章生成模型配置不完整，请联系站点管理员检查接口地址和密钥');
        }

        $settings = SiteSettingsBag::all();
        $siteName = trim((string) ($settings['site_name'] ?? $site->name));
        $description = trim((string) ($settings['site_description'] ?? ''));
        $organizationName = trim((string) ($settings['organization_name'] ?? ''));
        $categories = Category::query()->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $owner->id)
            ->get(['id', 'name']);
        $instructions = '你是客户官网的中文内容编辑。请基于主题与站点已确认资料撰写结构清晰、便于搜索引擎理解的文章。不得编造企业资质、数字、客户案例、产品参数、联系方式、承诺或来源；资料不足时使用审慎表达或明确标注待核实。只返回一个 JSON 对象，正文为 Markdown，不输出 HTML，不声称文章已经发布。';
        $promptParts = [
            '站点名称：'.$siteName,
            '企业名称：'.($organizationName !== '' ? $organizationName : '未提供'),
            '站点介绍：'.($description !== '' ? $description : '未提供'),
            '文章主题：'.$topic,
        ];
        if (trim($requirements) !== '') {
            $promptParts[] = '写作要求：'.trim($requirements);
        }
        $promptParts[] = '可选文章分类：'.json_encode(
            $categories->map(fn (Category $category): array => ['id' => (int) $category->id, 'name' => $category->name])->all(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $promptParts[] = '请输出一个 JSON 对象，字段依次为 title、excerpt、content、meta_description、keywords、category_id。title 是文章标题；excerpt 是文章摘要；content 是包含清晰标题层级、具体说明和结尾总结的 Markdown 正文；meta_description 是不超过 160 个中文字符的搜索摘要；keywords 是逗号分隔的关键词；category_id 只能选可选文章分类中的 ID，不适合时使用 null。各字段均须与主题一致，不得编造未给出的企业事实。不要输出代码围栏或额外说明。';
        $prompt = implode(PHP_EOL.PHP_EOL, $promptParts);

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) $model->model_id);
        $providerName = OpenAiRuntimeProvider::registerProvider('website_article', $driver, $providerUrl, $apiKey);
        $agent = new MarkdownContentWriterAgent($instructions);

        try {
            $response = $agent->prompt($prompt, [], $providerName, (string) $model->model_id);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                '文章生成失败：'.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl),
                0,
                $exception
            );
        }

        $raw = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($raw === '') {
            throw new RuntimeException('模型没有返回文章内容，请调整主题后重试');
        }

        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\s*\z/su', $raw, $matches)) {
            $raw = $matches[1];
        }
        $draft = json_decode($raw, true);
        if (! is_array($draft) || ! is_string($draft['content'] ?? null) || trim($draft['content']) === '') {
            throw new RuntimeException('模型返回的文章格式不完整，请调整主题后重试');
        }

        $categoryId = $draft['category_id'] ?? null;
        $categoryId = (is_int($categoryId) || (is_string($categoryId) && ctype_digit($categoryId)))
            && $categories->contains('id', (int) $categoryId) ? (int) $categoryId : null;
        $fieldLimits = ['title' => 500, 'excerpt' => 2000, 'meta_description' => 320, 'keywords' => 1000];
        $result = ['content' => trim($draft['content']), 'category_id' => $categoryId];
        foreach ($fieldLimits as $field => $limit) {
            $result[$field] = is_string($draft[$field] ?? null)
                ? mb_substr(trim($draft[$field]), 0, $limit)
                : '';
        }
        if ($result['title'] === '' || mb_strlen($result['content']) > 1000000) {
            throw new RuntimeException('模型返回的文章格式不完整，请调整主题后重试');
        }

        return $result;
    }

    /**
     * @Name: availableModelQuery
     * @Description: 统一模型列表与生成请求的站点、所有者、状态和类型过滤条件。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 10:59:25
     * @UpdateTime: 2026-11-12 10:59:25
     * @Param Site $site 当前客户站点
     * @Param Admin $owner 站点 GEO 所有者
     * @Return Builder 当前站点可用对话模型查询
     * @Throws \Throwable 模型查询构建失败
     */
    private function availableModelQuery(Site $site, Admin $owner): Builder
    {
        return AiModel::query()
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $owner->id)
            ->activeStatus()
            ->whereRaw('LOWER(COALESCE(NULLIF(TRIM(model_type), \'\'), \'chat\')) = \'chat\'')
            ->orderBy('failover_priority')
            ->orderBy('id');
    }
}

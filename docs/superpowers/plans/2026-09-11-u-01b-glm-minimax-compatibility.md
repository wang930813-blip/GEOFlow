# U-01b GLM / MiniMax 返回解析兼容实施方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 在当前 GEOFlow 基座中，为通用 AI 结构化输出增加 GLM / MiniMax 常见返回格式兼容，同时保持品牌诊断流程、模型选择、接口契约和现有降级行为不变。

**Architecture:** 新增一个只负责“从模型文本中提取 JSON”的本地支持类，处理 BOM、前置 reasoning、Markdown JSON 代码块、JSON 前后说明文字和完整/截断 JSON。仅将该解析器接入关键词、问题、视频脚本和文章配图计划等结构化解析点；不复制上游文章 AI 质检体系，不改品牌诊断的自定义 HTTP 客户端。

**Tech Stack:** Laravel 12、PHP 8.2、Laravel AI 0.6、PHPUnit 11。

---

## 范围边界

本项只迁移“返回解析兼容”：

- 保留当前 `OpenAiRuntimeProvider::resolveChatDriver()` 的 provider 映射，不新增驱动；
- 不修改 `app/Services/BrandDiagnosis/`；
- 不修改 `app/Http/Controllers/Api/V1/BrandDiagnosis*`、品牌诊断队列和 API 返回字段；
- 不升级 `composer.json`、`composer.lock`、数据库迁移或 Laravel AI 版本；
- 不把原始 AI 返回快照改写为清洗后的内容；
- GLM 的 `thinking` 请求参数和 MiniMax 的 `reasoning_split` 请求参数暂不在本项实现，避免影响当前所有共用 `MarkdownContentWriterAgent` 的模块；如需迁移，另立 U-01c 或独立子项。

## 文件变更清单

- Create: `app/Support/GeoFlow/AiJsonResponseParser.php`
- Create: `tests/Unit/AiJsonResponseParserTest.php`
- Modify: `app/Services/GeoFlow/GeoQuestionVariantService.php`
- Modify: `app/Services/GeoFlow/GeoKeywordSuggestionService.php`
- Modify: `app/Services/VideoGeneration/VideoContentDraftService.php`
- Modify: `app/Services/GeoFlow/AiGeneratedArticleImageService.php`
- Modify: `tests/Unit/GeoKeywordSuggestionServiceTest.php`
- Create: `tests/Unit/GeoQuestionVariantServiceTest.php`

不会修改：

- `app/Services/BrandDiagnosis/DoubaoBrandDiagnosisClient.php`
- `app/Services/BrandDiagnosis/BrandProfileResolver.php`
- `app/Support/GeoFlow/OpenAiRuntimeProvider.php`
- `app/Ai/Agents/MarkdownContentWriterAgent.php`

## Task 1: 新增结构化 AI 返回解析器

**Files:**
- Create: `app/Support/GeoFlow/AiJsonResponseParser.php`
- Test: `tests/Unit/AiJsonResponseParserTest.php`

- [ ] **Step 1: 写失败测试**

测试覆盖以下输入和结果：

```php
public function test_it_decodes_plain_json_object(): void
{
    $this->assertSame(
        ['images' => [['prompt' => 'draw a clean icon']]],
        AiJsonResponseParser::decode('{"images":[{"prompt":"draw a clean icon"}]}')
    );
}

public function test_it_decodes_markdown_json_fence(): void
{
    $this->assertSame(
        ['keywords' => ['AI 搜索', '品牌诊断']],
        AiJsonResponseParser::decode("```json\n{\"keywords\":[\"AI 搜索\",\"品牌诊断\"]}\n```")
    );
}

public function test_it_removes_leading_reasoning_only_for_structured_parsing(): void
{
    $this->assertSame(
        ['questions' => ['如何选择？']],
        AiJsonResponseParser::decode("<think>先分析输出格式</think>\n{\"questions\":[\"如何选择？\"]}")
    );
}

public function test_it_extracts_json_after_leading_and_trailing_explanation(): void
{
    $this->assertSame(
        ['answer' => 'OK'],
        AiJsonResponseParser::decode("下面是结果：\n{\"answer\":\"OK\"}\n以上。")
    );
}

public function test_it_returns_null_for_invalid_or_truncated_json(): void
{
    $this->assertNull(AiJsonResponseParser::decode('{"answer":"未闭合"'));
    $this->assertNull(AiJsonResponseParser::decode('这不是 JSON'));
}
```

- [ ] **Step 2: 运行测试确认失败**

运行：

```text
php artisan test tests/Unit/AiJsonResponseParserTest.php
```

预期：因 `AiJsonResponseParser` 尚不存在而失败。

- [ ] **Step 3: 实现最小解析器**

实现固定入口：

```php
final class AiJsonResponseParser
{
    public static function decode(string $content): mixed
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        $content = preg_replace('/\A(?:<think\b[^>]*>.*?<\/think>\s*)+/is', '', $content) ?? $content;

        $candidates = [$content];
        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $content, $matches)) {
            foreach ($matches[1] ?? [] as $match) {
                $candidates[] = trim((string) $match);
            }
        }

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $start = strpos($content, $open);
            $end = strrpos($content, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $candidates[] = substr($content, $start, $end - $start + 1);
            }
        }

        foreach (array_values(array_unique(array_filter(array_map('trim', $candidates)))) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}
```

实现时必须使用带字符串状态和转义状态的平衡括号扫描，不能用贪婪正则直接截取嵌套 JSON；JSON 字符串中的 `{`、`}`、`[`、`]` 不得被误判为结构边界。

- [ ] **Step 4: 运行测试确认通过**

运行：

```text
php artisan test tests/Unit/AiJsonResponseParserTest.php
```

预期：全部通过。

## Task 2: 接入问题和关键词解析

**Files:**
- Modify: `app/Services/GeoFlow/GeoQuestionVariantService.php`
- Modify: `app/Services/GeoFlow/GeoKeywordSuggestionService.php`
- Test: `tests/Unit/GeoQuestionVariantServiceTest.php`
- Modify: `tests/Unit/GeoKeywordSuggestionServiceTest.php`

- [ ] **Step 1: 增加失败用例**

问题解析应接受 `<think>` 前缀和 JSON 代码块：

```php
public function test_it_parses_question_array_after_reasoning_and_code_fence(): void
{
    $service = app(GeoQuestionVariantService::class);

    $this->assertSame(
        ['如何选择企业 AI 服务？', '企业 AI 服务适合哪些场景？'],
        $service->parseQuestionsForTesting(
            "<think>分析问题类型</think>\n```json\n[\"如何选择企业 AI 服务？\",\"企业 AI 服务适合哪些场景？\"]\n```",
            10
        )
    );
}
```

关键词解析应接受对象包裹的列表：

```php
public function test_it_parses_keyword_object_after_reasoning(): void
{
    $service = app(GeoKeywordSuggestionService::class);

    $this->assertSame(
        ['AI 搜索', '品牌诊断'],
        $service->parseSuggestionsForTesting(
            "<think>整理关键词</think>\n{\"keywords\":[\"AI 搜索\",\"品牌诊断\"]}",
            10
        )
    );
}
```

- [ ] **Step 2: 运行相关测试确认失败**

运行：

```text
php artisan test tests/Unit/GeoKeywordSuggestionServiceTest.php tests/Unit/GeoQuestionVariantServiceTest.php
```

预期：新增格式用例失败，现有格式用例继续通过。

- [ ] **Step 3: 接入解析器但保留现有降级**

`GeoQuestionVariantService::parseQuestions()` 使用 `AiJsonResponseParser::decode()`；数组直接作为候选，关联数组只读取 `questions` 字段，解析失败时继续使用现有按行拆分逻辑。

`GeoKeywordSuggestionService::parseSuggestions()` 使用 `AiJsonResponseParser::decode()`；支持顶层数组、`keywords`、`suggestions`、`items` 字段，解析失败时继续使用现有换行/逗号拆分逻辑。

不得改变：

- 候选数量上限；
- 去重规则；
- 模板回填规则；
- 空结果异常信息；
- AI 模型选择和调用地址。

- [ ] **Step 4: 运行相关测试确认通过**

运行：

```text
php artisan test tests/Unit/GeoKeywordSuggestionServiceTest.php tests/Unit/GeoQuestionVariantServiceTest.php
```

预期：旧格式和新格式全部通过。

## Task 3: 接入视频脚本和文章配图计划解析

**Files:**
- Modify: `app/Services/VideoGeneration/VideoContentDraftService.php`
- Modify: `app/Services/GeoFlow/AiGeneratedArticleImageService.php`
- Test: `tests/Unit/VideoContentDraftServiceTest.php`

- [ ] **Step 1: 保留现有行为并增加结构化返回覆盖**

视频脚本现有 `decodeJson()` 和文章配图现有 `parsePlan()` 都改为先调用：

```php
$decoded = AiJsonResponseParser::decode($content);
if (! is_array($decoded)) {
    return null;
}
```

配图计划继续只接受 `images` 数组，并继续在解析失败时走现有确定性 `fallbackPlan()`；视频脚本继续保留当前字段校验和默认值。

- [ ] **Step 2: 运行视频相关测试**

运行：

```text
php artisan test tests/Unit/VideoContentDraftServiceTest.php
```

预期：现有主题候选、脚本草稿和模型校验测试通过。

- [ ] **Step 3: 检查文章生成回归**

运行：

```text
php artisan test tests/Feature/AdminMaterialsPagesTest.php tests/Feature/BrandDiagnosisDoubaoFlowTest.php
```

预期：文章素材流程和品牌诊断流程通过；品牌诊断请求数量、请求地址、存储字段和快照内容不发生变化。

## Task 4: 全量验证和变更边界检查

**Files:**
- Verify: all files listed above
- Do not modify: all protected brand diagnosis and API files

- [ ] **Step 1: 运行静态检查**

运行：

```text
vendor/bin/pint --test app/Support/GeoFlow/AiJsonResponseParser.php app/Services/GeoFlow/GeoQuestionVariantService.php app/Services/GeoFlow/GeoKeywordSuggestionService.php app/Services/VideoGeneration/VideoContentDraftService.php app/Services/GeoFlow/AiGeneratedArticleImageService.php
```

预期：格式检查通过。

- [ ] **Step 2: 运行完整测试**

运行：

```text
php artisan test
```

预期：全量测试通过，或仅出现执行前已存在且与本项无关的失败；如出现涉及品牌诊断、队列、API 契约的新增失败，立即停止迁移并报告。

- [ ] **Step 3: 检查受保护文件未被改动**

运行：

```text
git diff --name-only
```

预期：输出只包含本方案列出的解析器、通用 AI 解析调用点和测试文件，不包含：

```text
app/Services/BrandDiagnosis/
app/Http/Controllers/Api/V1/BrandDiagnosis*
app/Models/BrandDiagnosis*
database/migrations/
composer.json
composer.lock
```

## 实施中需要再次确认的情况

以下情况不属于本方案默认授权范围；如果实现时不可避免，必须暂停并向用户报告影响后再继续：

1. 需要修改 `OpenAiRuntimeProvider::resolveChatDriver()`；
2. 需要让 `MarkdownContentWriterAgent` 增加全局 provider options；
3. 需要给 `ai_models` 增加字段或迁移；
4. 需要修改品牌诊断的 ChatGPT、Grok、豆包调用链；
5. 需要改变品牌诊断原始响应快照或对外 API 字段；
6. 需要升级 `laravel/ai` 或其他 Composer 依赖。


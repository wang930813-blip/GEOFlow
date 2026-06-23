<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\CrebeeAccount;
use App\Models\Site;
use App\Models\Task;
use App\Services\Crebee\SelfMediaArticlePublishService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use App\Support\Crebee\SelfMediaPlatformCatalog;
use App\Support\CurrentSite;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * 文章管理页（按 bak/admin/articles.php 行为迁移）：
 * - GET 展示列表、筛选、统计与批量操作区
 * - POST 处理批量状态/审核更新与批量删除
 * - create/edit 共用同一 Blade 表单页
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly AdminDataScope $adminDataScope,
    ) {}

    /**
     * 文章管理首页：渲染筛选与列表。
     */
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $filters = $this->buildFilters($request);
        $articles = $this->queryArticles($filters, $admin);
        $isTrashView = (bool) ($filters['trashed'] ?? false);

        return view('admin.articles.index', [
            'pageTitle' => $isTrashView
                ? __('admin.articles.trash.title')
                : __('admin.articles.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'articles' => $articles,
            'stats' => $isTrashView ? $this->loadTrashStats($admin) : $this->loadStats($admin),
            'filters' => $filters,
            'tasks' => $this->loadTaskOptions($admin),
            'categories' => $this->loadCategoryOptions($admin),
            'authors' => $this->loadAuthorOptions($admin),
            'articlesI18n' => $this->articlesI18n(),
            'isTrashView' => $isTrashView,
            'trashI18n' => $this->trashI18n(),
            'articleBatchRoutes' => $this->articleBatchRoutes($isTrashView),
            'selfMediaArticleAccounts' => $isTrashView ? collect() : $this->loadSelfMediaArticleAccounts($request),
            'selfMediaPlatformLabels' => SelfMediaArticlePublishService::articlePlatformLabels(),
            'selfMediaPlatformLogos' => collect(SelfMediaArticlePublishService::articlePlatforms())
                ->mapWithKeys(fn (string $platform): array => [$platform => SelfMediaPlatformCatalog::logoPath($platform)])
                ->all(),
            'canOperateArticles' => ! $admin->isAgentAdmin(),
        ]);
    }

    /**
     * 批量更新发布状态。
     */
    public function batchUpdateStatus(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchUpdateStatus($request, $articleIds);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量更新审核状态。
     */
    public function batchUpdateReview(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchUpdateReview($request, $articleIds);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量删除文章。
     */
    public function batchDelete(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchDelete($articleIds);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量恢复已软删除的文章。
     */
    public function batchRestore(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            $count = Article::onlyTrashed()->whereIn('id', $articleIds)->restore();

            return back()->with('message', __('admin.articles.trash.message.restore_success', ['count' => $count]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.restore_failed'));
        }
    }

    /**
     * 批量永久删除（垃圾箱内）。
     */
    public function batchForceDelete(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            $models = Article::onlyTrashed()->whereIn('id', $articleIds)->get();
            $models->each(function (Article $article): void {
                $article->forceDelete();
            });

            return back()->with('message', __('admin.articles.trash.message.delete_success', ['count' => $models->count()]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.delete_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 清空文章垃圾箱（全部永久删除）。
     */
    public function emptyTrash(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        try {
            $models = Article::onlyTrashed()->get();
            if ($models->isEmpty()) {
                return back()->with('message', __('admin.articles.trash.message.empty_already'));
            }
            $total = $models->count();
            $models->each(function (Article $article): void {
                $article->forceDelete();
            });

            return back()->with('message', __('admin.articles.trash.message.empty_success', ['count' => $total]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.empty_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 恢复单篇已删除文章。
     */
    public function restore(Request $request, int $articleId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $article = Article::onlyTrashed()->whereKey($articleId)->firstOrFail();
        $article->restore();

        return back()->with('message', __('admin.articles.trash.message.restore_success', ['count' => 1]));
    }

    /**
     * 永久删除单篇已删除文章。
     */
    public function forceDelete(Request $request, int $articleId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $article = Article::onlyTrashed()->whereKey($articleId)->firstOrFail();
        $article->forceDelete();

        return back()->with('message', __('admin.articles.trash.message.delete_success', ['count' => 1]));
    }

    /**
     * 下载文章为 Word 文档（.doc）。
     *
     * 输出 Word 兼容的 HTML（带 MSO 命名空间），Word 可直接打开；
     * 不依赖 PHPWord，零额外依赖。包含基础信息 + SEO 设置 + 文章正文。
     */
    public function downloadWord(Request $request, int $articleId): Response
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $query = Article::query()
            ->with(['author:id,name', 'category:id,name'])
            ->whereKey($articleId);
        $this->adminDataScope->applySiteScope($query, $admin);
        $article = $query->firstOrFail();

        $title = (string) $article->title;
        $slug = (string) ($article->slug ?? '');
        $authorName = (string) ($article->author->name ?? '');
        $categoryName = (string) ($article->category->name ?? '');
        $publishedAt = $article->published_at?->format('Y-m-d H:i') ?? '';
        $createdAt = $article->created_at?->format('Y-m-d H:i') ?? '';
        $statusLabel = $this->articleStatusLabel((string) $article->status);
        $reviewLabel = $this->articleReviewLabel((string) $article->review_status);
        $keywords = (string) ($article->keywords ?? '');
        $metaDescription = (string) ($article->meta_description ?? '');
        $excerpt = (string) ($article->excerpt ?? '');
        $contentHtml = $this->normalizeArticleContentHtml((string) $article->content);

        // 站点级 SEO 配置
        $siteSettings = SiteSettingsBag::all();
        $siteName = (string) ($siteSettings['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $titleTemplate = (string) ($siteSettings['seo_title_template'] ?? '{title} - {site_name}');
        $descriptionTemplate = (string) ($siteSettings['seo_description_template'] ?? '{description}');

        $renderedSeoTitle = $this->renderSeoTemplate($titleTemplate, [
            '{title}' => $title,
            '{site_name}' => $siteName,
            '{category}' => $categoryName,
        ]);
        $renderedSeoDescription = $this->renderSeoTemplate($descriptionTemplate, [
            '{description}' => $metaDescription !== '' ? $metaDescription : $excerpt,
            '{site_name}' => $siteName,
            '{keywords}' => $keywords,
        ]);

        $canonicalUrl = $slug !== '' ? rtrim((string) config('app.url'), '/').'/article/'.$slug : '';

        // 基础信息块
        $basicRows = [];
        if ($authorName !== '') {
            $basicRows[] = ['作者', $authorName];
        }
        if ($categoryName !== '') {
            $basicRows[] = ['分类', $categoryName];
        }
        if ($statusLabel !== '') {
            $basicRows[] = ['状态', $statusLabel];
        }
        if ($reviewLabel !== '') {
            $basicRows[] = ['审核', $reviewLabel];
        }
        if ($publishedAt !== '') {
            $basicRows[] = ['发布时间', $publishedAt];
        }
        if ($createdAt !== '') {
            $basicRows[] = ['创建时间', $createdAt];
        }

        // SEO 设置块
        $seoRows = [];
        if ($renderedSeoTitle !== '') {
            $seoRows[] = ['页面标题（SEO）', $renderedSeoTitle];
        }
        if ($renderedSeoDescription !== '') {
            $seoRows[] = ['页面描述（SEO）', $renderedSeoDescription];
        }
        if ($keywords !== '') {
            $seoRows[] = ['关键词', $keywords];
        }
        if ($metaDescription !== '') {
            $seoRows[] = ['Meta Description', $metaDescription];
        }
        if ($excerpt !== '') {
            $seoRows[] = ['摘要', $excerpt];
        }
        if ($slug !== '') {
            $seoRows[] = ['URL Slug', $slug];
        }
        if ($canonicalUrl !== '') {
            $seoRows[] = ['Canonical URL', $canonicalUrl];
        }
        if ($titleTemplate !== '') {
            $seoRows[] = ['标题模板', $titleTemplate];
        }
        if ($descriptionTemplate !== '') {
            $seoRows[] = ['描述模板', $descriptionTemplate];
        }
        if ($siteName !== '') {
            $seoRows[] = ['站点名称', $siteName];
        }

        $basicHtml = $this->buildMetaTableHtml($basicRows);
        $seoHtml = $this->buildMetaTableHtml($seoRows);

        $titleHtml = e($title);
        $generatedAt = now()->format('Y-m-d H:i:s');
        $generatedAtHtml = e($generatedAt);

        $seoSection = $seoHtml !== '' ? '<h2>SEO 设置</h2>'.$seoHtml : '';

        $html = <<<HTML
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="ProgId" content="Word.Document">
<meta name="Generator" content="Microsoft Word 15">
<title>{$titleHtml}</title>
<style>
@page WordSection1 {
    size: 595.3pt 841.9pt;
    margin: 72pt 72pt 72pt 72pt;
}
div.WordSection1 { page: WordSection1; }
body { font-family: "Microsoft YaHei", "PingFang SC", "Helvetica Neue", Arial, sans-serif; font-size: 11pt; line-height: 1.7; color: #1a1a1a; }
h1 { font-size: 22pt; margin: 0 0 12pt; color: #181c24; }
h2 { font-size: 14pt; margin: 18pt 0 8pt; color: #181c24; border-left: 3pt solid #ff6a00; padding-left: 8pt; }
h3 { font-size: 13pt; margin: 14pt 0 8pt; color: #181c24; }
p { margin: 0 0 10pt; }
table.meta { border-collapse: collapse; margin: 0 0 18pt; width: 100%; font-size: 10pt; }
table.meta th { width: 110pt; padding: 5pt 10pt; background: #f5f7fa; color: #5f6b7a; font-weight: 600; border: 1pt solid #dcdfe6; vertical-align: top; }
table.meta td { padding: 5pt 10pt; border: 1pt solid #dcdfe6; color: #181c24; word-break: break-word; }
img { max-width: 480pt; height: auto; }
hr { border: none; border-top: 1pt solid #dcdfe6; margin: 16pt 0; }
ul, ol { margin: 0 0 10pt 24pt; }
li { margin-bottom: 4pt; }
blockquote { margin: 0 0 10pt; padding: 6pt 12pt; border-left: 3pt solid #dcdfe6; color: #5f6b7a; background: #fafbfc; }
pre { margin: 0 0 10pt; padding: 8pt 12pt; background: #f5f7fa; border: 1pt solid #dcdfe6; font-family: "Consolas", "Courier New", monospace; font-size: 10pt; white-space: pre-wrap; word-wrap: break-word; }
code { font-family: "Consolas", "Courier New", monospace; font-size: 10pt; padding: 1pt 4pt; background: #f5f7fa; border: 1pt solid #eef0f3; }
pre code { padding: 0; background: transparent; border: none; }
table { border-collapse: collapse; margin: 0 0 12pt; width: 100%; font-size: 10pt; }
table th, table td { padding: 4pt 8pt; border: 1pt solid #dcdfe6; vertical-align: top; }
table th { background: #f5f7fa; font-weight: 600; color: #181c24; }
.footer { margin-top: 24pt; padding-top: 8pt; border-top: 1pt solid #dcdfe6; font-size: 9pt; color: #86909c; }
</style>
</head>
<body>
<div class="WordSection1">
<h1>{$titleHtml}</h1>
<h2>基础信息</h2>
{$basicHtml}
{$seoSection}
<h2>正文</h2>
{$contentHtml}
<div class="footer">导出时间：{$generatedAtHtml}</div>
</div>
</body>
</html>
HTML;

        $filename = $this->buildDownloadFilename($title, (int) $article->id);

        return response($html, 200, [
            'Content-Type' => 'application/msword; charset=UTF-8',
            'Content-Disposition' => sprintf(
                "attachment; filename=\"%s\"; filename*=UTF-8''%s",
                $filename['ascii'],
                $filename['encoded']
            ),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @param  array<int, array{0:string, 1:string}>  $rows
     */
    private function buildMetaTableHtml(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '<table class="meta">';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><th align="left">'.e($label).'</th><td>'.e($value).'</td></tr>';
        }

        return $html.'</table>';
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function renderSeoTemplate(string $template, array $replacements): string
    {
        if ($template === '') {
            return '';
        }

        return trim(strtr($template, $replacements));
    }

    private function articleStatusLabel(string $status): string
    {
        return match ($status) {
            'published' => '已发布',
            'draft' => '草稿',
            'private' => '私密',
            default => $status,
        };
    }

    private function articleReviewLabel(string $reviewStatus): string
    {
        return match ($reviewStatus) {
            'pending' => '待审核',
            'approved' => '已通过',
            'auto_approved' => '自动通过',
            'rejected' => '已驳回',
            default => $reviewStatus,
        };
    }

    /**
     * 让文章正文在 Word 中尽量可读：
     * - 富文本 HTML（包含成对标签）直接保留
     * - 检测到 Markdown 语法时用 league/commonmark 渲染（带 GFM 表格/任务列表/自动链接）
     * - 纯文本按双换行分段
     */
    private function normalizeArticleContentHtml(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '<p></p>';
        }

        // 已经是 HTML（包含成对标签），直接返回
        if (preg_match('/<\/?(p|div|br|h[1-6]|ul|ol|li|table|img|blockquote|figure)[^>]*>/i', $trimmed) === 1) {
            return $trimmed;
        }

        // 含 Markdown 标记符则按 Markdown 渲染
        if ($this->looksLikeMarkdown($trimmed)) {
            try {
                return $this->renderMarkdown($trimmed);
            } catch (\Throwable) {
                // 渲染失败时 fall through 到纯文本兜底
            }
        }

        // 纯文本：按双换行分段，单换行变 <br>
        $paragraphs = preg_split('/\n{2,}/', $trimmed) ?: [$trimmed];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html .= '<p>'.nl2br(e($paragraph)).'</p>';
        }

        return $html !== '' ? $html : '<p>'.e($trimmed).'</p>';
    }

    /**
     * 启发式：内容里出现常见 Markdown 标记符就走 Markdown 渲染。
     */
    private function looksLikeMarkdown(string $text): bool
    {
        // 标题、列表、引用、代码块、行内代码、加粗/斜体、链接、图片、表格分隔行
        return preg_match(
            '/(^|\n)(#{1,6}\s|>\s|[-*+]\s|\d+\.\s|```|~~~|\|.*\|.*\n[\s|:-]+\|)|`[^`\n]+`|\*\*[^*\n]+\*\*|__[^_\n]+__|!?\[[^\]\n]*\]\([^)\n]+\)/u',
            $text
        ) === 1;
    }

    /**
     * 用 league/commonmark 把 Markdown 渲染为 HTML（GFM 风格）。
     */
    private function renderMarkdown(string $markdown): string
    {
        $config = [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ];
        $environment = new \League\CommonMark\Environment\Environment($config);
        $environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension);
        $environment->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension);

        $converter = new \League\CommonMark\MarkdownConverter($environment);

        return (string) $converter->convert($markdown);
    }

    /**
     * @return array{ascii:string, encoded:string}
     */
    private function buildDownloadFilename(string $title, int $articleId): array
    {
        $safeTitle = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $title) ?? '');
        if ($safeTitle === '') {
            $safeTitle = 'article-'.$articleId;
        }
        // Word 文件名长度限制：保险起见截断 80 字符
        $safeTitle = mb_substr($safeTitle, 0, 80, 'UTF-8');

        $unicodeName = $safeTitle.'.doc';
        $asciiName = 'article-'.$articleId.'.doc';

        return [
            'ascii' => $asciiName,
            'encoded' => rawurlencode($unicodeName),
        ];
    }

    /**
     * 文章创建页：与编辑页共用一个 Blade 模板。
     */
    public function create(Request $request): View
    {
        $this->abortIfAgentAdmin($request);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return view('admin.articles.form', [
            'pageTitle' => __('admin.article_create.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'articleId' => null,
            'articleForm' => null,
            'formOptions' => $this->loadFormOptions($admin),
        ]);
    }

    /**
     * 创建文章：手动写入内容并按统一工作流校正状态。
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $payload = $this->validateArticleForm($request, false);
        $workflowState = ArticleWorkflow::normalizeState(
            $payload['status'],
            $payload['review_status']
        );

        try {
            $article = Article::query()->create([
                'title' => $payload['title'],
                'slug' => ArticleWorkflow::generateUniqueSlug($payload['title']),
                'content' => $payload['content'],
                'excerpt' => $payload['excerpt'] !== '' ? $payload['excerpt'] : mb_substr(strip_tags($payload['content']), 0, 200, 'UTF-8'),
                'cover_image' => trim((string) ($payload['cover_image'] ?? '')),
                'keywords' => $payload['keywords'],
                'meta_description' => $payload['meta_description'],
                'category_id' => (int) $payload['category_id'],
                'author_id' => (int) $payload['author_id'],
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
                'is_ai_generated' => 0,
                'is_hot' => (bool) ($payload['is_hot'] ?? false),
                'is_featured' => (bool) ($payload['is_featured'] ?? false),
            ]);
            if ($workflowState['status'] === 'published') {
                $this->distributionOrchestrator->enqueueForArticle($article);
            }
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(__('admin.article_create.error.create_exception', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => (int) $article->id])
            ->with('message', __('admin.button.create_article'));
    }

    /**
     * 文章编辑页：复用创建页模板并回填现有数据。
     */
    public function edit(Request $request, int $articleId): View|RedirectResponse
    {
        $this->abortIfAgentAdmin($request);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $article = Article::query()
            ->with(['task:id,name', 'author:id,name', 'category:id,name'])
            ->whereKey($articleId)
            ->firstOrFail();

        return view('admin.articles.form', [
            'pageTitle' => __('admin.article_edit.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'articleId' => $articleId,
            'articleForm' => [
                'title' => (string) $article->title,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'cover_image' => (string) ($article->cover_image ?? ''),
                'content' => (string) $article->content,
                'keywords' => (string) ($article->keywords ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
                'status' => (string) $article->status,
                'review_status' => (string) $article->review_status,
                'category_id' => (string) $article->category_id,
                'author_id' => (string) $article->author_id,
                'slug' => (string) $article->slug,
                'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
                'task_name' => (string) ($article->task->name ?? ''),
                'is_hot' => (bool) ($article->is_hot ?? false),
                'is_featured' => (bool) ($article->is_featured ?? false),
            ],
            'formOptions' => $this->loadFormOptions($admin),
        ]);
    }

    /**
     * 更新文章：保持创建/编辑一致的字段校验与状态归一化。
     */
    public function update(Request $request, int $articleId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $payload = $this->validateArticleForm($request, true);
        $article = Article::query()->whereKey($articleId)->firstOrFail();

        $workflowState = ArticleWorkflow::normalizeState(
            $payload['status'],
            $payload['review_status'],
            $article->published_at?->format('Y-m-d H:i:s')
        );

        try {
            $article->fill([
                'title' => $payload['title'],
                'slug' => $payload['title'] === $article->title
                    ? $article->slug
                    : ArticleWorkflow::generateUniqueSlug($payload['title'], (int) $article->id),
                'content' => $payload['content'],
                'excerpt' => $payload['excerpt'] !== '' ? $payload['excerpt'] : mb_substr(strip_tags($payload['content']), 0, 200, 'UTF-8'),
                'cover_image' => trim((string) ($payload['cover_image'] ?? '')),
                'keywords' => $payload['keywords'],
                'meta_description' => $payload['meta_description'],
                'category_id' => (int) $payload['category_id'],
                'author_id' => (int) $payload['author_id'],
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
                'is_hot' => (bool) ($payload['is_hot'] ?? false),
                'is_featured' => (bool) ($payload['is_featured'] ?? false),
            ])->save();
            if ($workflowState['status'] === 'published') {
                $this->distributionOrchestrator->enqueueForArticle($article);
            }
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(__('admin.article_edit.error.update_exception', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => $articleId])
            ->with('message', __('admin.article_edit.message.update_success'));
    }

    /**
     * @return array{
     *     task_id: int,
     *     status: string,
     *     review_status: string,
     *     category_id: int,
     *     author_id: int,
     *     date_from: string,
     *     date_to: string,
     *     search: string,
     *     per_page: int,
     *     trashed: bool
     * }
     */
    private function buildFilters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        $reviewStatus = (string) $request->query('review_status', '');

        if (! in_array($status, ['draft', 'published', 'private'], true)) {
            $status = '';
        }

        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            $reviewStatus = '';
        }

        return [
            'task_id' => max(0, (int) $request->query('task_id', 0)),
            'status' => $status,
            'review_status' => $reviewStatus,
            'category_id' => max(0, (int) $request->query('category_id', 0)),
            'author_id' => max(0, (int) $request->query('author_id', 0)),
            'date_from' => $this->normalizeDateFilter((string) $request->query('date_from', '')),
            'date_to' => $this->normalizeDateFilter((string) $request->query('date_to', '')),
            'search' => trim((string) $request->query('search', '')),
            'per_page' => min(100, max(10, (int) $request->query('per_page', 20) ?: 20)),
            'trashed' => $request->boolean('trashed'),
        ];
    }

    private function normalizeDateFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $matches) !== 1) {
            return '';
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @param  array{
     *     task_id: int,
     *     status: string,
     *     review_status: string,
     *     category_id: int,
     *     author_id: int,
     *     date_from: string,
     *     date_to: string,
     *     search: string,
     *     per_page: int,
     *     trashed: bool
     * }  $filters
     */
    private function queryArticles(array $filters, Admin $admin): LengthAwarePaginator
    {
        $query = ($filters['trashed'] ?? false)
            ? Article::onlyTrashed()
            : Article::query();

        $this->adminDataScope->applySiteScope($query, $admin);

        $query->with([
            'task:id,name,need_review',
            'author:id,name',
            'category:id,name',
        ])->withCount([
            'distributions as distribution_total_count',
            'distributions as distribution_synced_count' => fn ($distributionQuery) => $distributionQuery->where('status', 'synced'),
            'distributions as distribution_failed_count' => fn ($distributionQuery) => $distributionQuery->where('status', 'failed'),
        ]);

        if ($filters['trashed'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderByDesc('created_at');
        }

        if ($filters['task_id'] > 0) {
            $query->where('task_id', $filters['task_id']);
        }

        if ($filters['category_id'] > 0) {
            $query->where('category_id', $filters['category_id']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['review_status'] !== '') {
            $query->where('review_status', $filters['review_status']);
        }

        if ($filters['author_id'] > 0) {
            $query->where('author_id', $filters['author_id']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['search'] !== '') {
            $query->where(function ($subQuery) use ($filters): void {
                $subQuery->where('title', 'like', '%'.$filters['search'].'%')
                    ->orWhere('content', 'like', '%'.$filters['search'].'%');
            });
        }

        return $query->paginate($filters['per_page'])->withQueryString();
    }

    /**
     * 测试环境缺少 articles 表时，返回空分页并保持页面可渲染。
     */
    private function emptyArticlesPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: max(1, (int) request()->query('page', 1)),
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return array{total: int, published: int, draft: int, pending_review: int, today: int}
     */
    private function loadStats(Admin $admin): array
    {
        $baseQuery = Article::query();
        $this->adminDataScope->applySiteScope($baseQuery, $admin);

        return [
            'total' => (clone $baseQuery)->count(),
            'published' => (clone $baseQuery)->where('status', 'published')->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'pending_review' => (clone $baseQuery)->where('review_status', 'pending')->count(),
            'today' => (clone $baseQuery)->whereDate('created_at', Carbon::today())->count(),
        ];
    }

    /**
     * @return array{trashed_total: int}
     */
    private function loadTrashStats(Admin $admin): array
    {
        $query = Article::onlyTrashed();
        $this->adminDataScope->applySiteScope($query, $admin);

        return [
            'trashed_total' => $query->count(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadTaskOptions(Admin $admin): array
    {
        try {
            $query = Task::query()
                ->select(['id', 'name'])
                ->orderBy('name');
            $this->adminDataScope->applySiteScope($query, $admin);

            return $query->get()
                ->map(fn (Task $task): array => [
                    'id' => (int) $task->id,
                    'name' => (string) $task->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadCategoryOptions(Admin $admin): array
    {
        try {
            $query = Category::query()
                ->select(['id', 'name'])
                ->orderBy('name');
            $this->adminDataScope->applySiteScope($query, $admin);

            return $query->get()
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadAuthorOptions(?Admin $admin = null): array
    {
        try {
            $query = Author::query()
                ->select(['id', 'name'])
                ->orderBy('name');

            if ($admin instanceof Admin) {
                $this->adminDataScope->applySiteScope($query, $admin);
            }

            return $query->get()
                ->map(fn (Author $author): array => [
                    'id' => (int) $author->id,
                    'name' => (string) $author->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    private function loadSelfMediaArticleAccounts(Request $request)
    {
        $admin = $request->user('admin');
        $site = app(CurrentSite::class)->get();
        if (! $admin instanceof \App\Models\Admin || ! $site instanceof Site) {
            return collect();
        }

        return CrebeeAccount::query()
            ->select(['id', 'agent_id', 'site_id', 'owner_admin_id', 'platform', 'crebee_account_id', 'account_name', 'avatar', 'status'])
            ->where('site_id', (int) $site->id)
            ->where('owner_admin_id', (int) $admin->id)
            ->where('status', 'bound')
            ->whereIn('platform', SelfMediaArticlePublishService::articlePlatforms())
            ->orderBy('platform')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     categories: array<int, array{id: int, name: string}>,
     *     authors: array<int, array{id: int, name: string}>
     * }
     */
    private function loadFormOptions(?Admin $admin = null): array
    {
        $categories = [];
        $authors = $this->loadAuthorOptions($admin);

        try {
            $query = Category::query()
                ->select(['id', 'name'])
                ->orderBy('name');

            if ($admin instanceof Admin) {
                $this->adminDataScope->applySiteScope($query, $admin);
            }

            $categories = $query->get()
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                ])
                ->all();
        } catch (QueryException) {
            $categories = [];
        }

        return [
            'categories' => $categories,
            'authors' => $authors,
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     excerpt: string,
     *     cover_image: string,
     *     content: string,
     *     keywords: string,
     *     meta_description: string,
     *     category_id: int,
     *     author_id: int,
     *     status: string,
     *     review_status: string
     *     is_hot: bool,
     *     is_featured: bool
     * }
     */
    private function validateArticleForm(Request $request, bool $isEdit): array
    {
        $keyPrefix = $isEdit ? 'admin.article_edit.error' : 'admin.article_create.error';

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'string', 'max:1000'],
            'content' => ['required', 'string'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:draft,published,private'],
            'review_status' => ['required', 'string', 'in:pending,approved,rejected,auto_approved'],
            'is_hot' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
        ], [
            'title.required' => __($keyPrefix.'.title_required'),
            'content.required' => __($keyPrefix.'.content_required'),
            'category_id.required' => __($keyPrefix.'.category_required'),
            'category_id.min' => __($keyPrefix.'.category_required'),
            'author_id.required' => __($keyPrefix.'.author_required'),
            'author_id.min' => __($keyPrefix.'.author_required'),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function extractArticleIds(Request $request): array
    {
        return collect($request->input('article_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchUpdateStatus(Request $request, array $articleIds): RedirectResponse
    {
        $newStatus = (string) $request->input('new_status', '');
        if (! in_array($newStatus, ['draft', 'published', 'private'], true)) {
            return back()->withErrors(__('admin.articles.message.select_status'));
        }

        $articles = Article::query()
            ->select(['id', 'review_status', 'published_at'])
            ->whereIn('id', $articleIds)
            ->get();

        foreach ($articles as $article) {
            $workflowState = ArticleWorkflow::normalizeState(
                $newStatus,
                (string) ($article->review_status ?? 'pending'),
                $article->published_at?->format('Y-m-d H:i:s')
            );

            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);

            if ($workflowState['status'] === 'published') {
                $this->distributionOrchestrator->enqueueForArticle((int) $article->id);
            }
        }

        return back()->with('message', __('admin.articles.message.batch_status_updated', ['count' => count($articleIds)]));
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchUpdateReview(Request $request, array $articleIds): RedirectResponse
    {
        $reviewStatus = (string) $request->input('review_status', '');
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            return back()->withErrors(__('admin.articles.message.select_review'));
        }

        $articles = Article::query()
            ->with(['task:id,need_review'])
            ->select(['id', 'status', 'review_status', 'published_at', 'task_id'])
            ->whereIn('id', $articleIds)
            ->get();

        foreach ($articles as $article) {
            $desiredStatus = (string) ($article->status ?? 'draft');
            $needsReview = (int) ($article->task->need_review ?? 0);
            if (in_array($reviewStatus, ['approved', 'auto_approved'], true) && ($reviewStatus === 'auto_approved' || $needsReview === 0)) {
                $desiredStatus = 'published';
            }

            $workflowState = ArticleWorkflow::normalizeState(
                $desiredStatus,
                $reviewStatus,
                $article->published_at?->format('Y-m-d H:i:s')
            );

            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);

            if ($workflowState['status'] === 'published') {
                $this->distributionOrchestrator->enqueueForArticle((int) $article->id);
            }
        }

        return back()->with('message', __('admin.articles.message.batch_review_updated', ['count' => count($articleIds)]));
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchDelete(array $articleIds): RedirectResponse
    {
        $articles = Article::query()->whereIn('id', $articleIds)->get();
        foreach ($articles as $article) {
            Article::query()->whereKey((int) $article->id)->delete();
        }

        return back()->with('message', __('admin.articles.message.batch_delete_success', ['count' => count($articleIds)]));
    }

    private function abortIfAgentAdmin(Request $request): void
    {
        $admin = $request->user('admin');
        abort_if($admin instanceof Admin && $admin->isAgentAdmin(), 403);
    }

    /**
     * 前端批量栏与快捷动作使用的文案字典。
     *
     * @return array<string, string>
     */
    private function articlesI18n(): array
    {
        return [
            'selectArticles' => __('admin.articles.message.select_articles'),
            'selectAction' => __('admin.articles.message.select_action'),
            'selectStatus' => __('admin.articles.message.select_status'),
            'selectReview' => __('admin.articles.message.select_review'),
            'confirmDeleteSelected' => __('admin.articles.confirm.delete_selected', ['count' => '__COUNT__']),
            'reviewApproved' => __('admin.articles.review.approved'),
            'reviewRejected' => __('admin.articles.review.rejected'),
            'confirmQuickReview' => __('admin.articles.confirm.quick_review', ['action' => '__ACTION__']),
            'confirmDelete' => __('admin.articles.confirm.delete'),
        ];
    }

    /**
     * 垃圾箱视图脚本使用的确认与操作文案。
     *
     * @return array<string, string>
     */
    private function trashI18n(): array
    {
        return [
            'alertSelect' => __('admin.articles.trash.alert_select'),
            'confirmBatchRestore' => __('admin.articles.trash.confirm_batch_restore', ['count' => '__COUNT__']),
            'confirmBatchForceDelete' => __('admin.articles.trash.confirm_batch_delete', ['count' => '__COUNT__']),
            'confirmEmpty' => __('admin.articles.trash.confirm_empty'),
        ];
    }

    /**
     * 批量操作表单提交目标 URL（普通列表与垃圾箱不同）。
     *
     * @return array<string, string>
     */
    private function articleBatchRoutes(bool $isTrashView): array
    {
        if ($isTrashView) {
            return [
                'batch_restore' => route('admin.articles.batch.restore'),
                'batch_force_delete' => route('admin.articles.batch.force-delete'),
            ];
        }

        return [
            'batch_update_status' => route('admin.articles.batch.update-status'),
            'batch_update_review' => route('admin.articles.batch.update-review'),
            'delete_articles' => route('admin.articles.batch.delete'),
        ];
    }
}

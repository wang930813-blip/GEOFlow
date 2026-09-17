<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ArticleFeedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $source = (string) $request->query('source', 'latest');
        if (! in_array($source, ['latest', 'featured', 'hot', 'category'], true)) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'invalid_source',
                    'message' => '资讯来源参数无效。',
                ],
            ], 422);
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min(24, (int) $request->query('limit', 6)));

        $query = Article::query()
            ->with(['category'])
            ->published();

        if ($source === 'featured') {
            if (! Schema::hasColumn('articles', 'is_featured')) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('is_featured', true);
            }
        }

        if ($source === 'hot') {
            if (! Schema::hasColumn('articles', 'is_hot')) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('is_hot', true);
            }
        }

        if ($source === 'category') {
            $categorySlug = trim((string) $request->query('category_slug', ''));
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $categorySlug)) {
                return response()->json([
                    'status' => 'error',
                    'error' => [
                        'code' => 'invalid_category_slug',
                        'message' => '资讯分类参数无效。',
                    ],
                ], 422);
            }

            $query->whereHas('category', static function ($categoryQuery) use ($categorySlug): void {
                $categoryQuery->where('slug', $categorySlug);
            });
        }

        $paginator = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'status' => 'success',
            'data' => [
                'items' => collect($paginator->items())
                    ->filter(static fn (mixed $article): bool => $article instanceof Article)
                    ->map(fn (Article $article): array => $this->summaryPayload($article))
                    ->values()
                    ->all(),
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'limit' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'total_pages' => $paginator->lastPage(),
                    'has_previous' => $paginator->currentPage() > 1,
                    'has_next' => $paginator->hasMorePages(),
                ],
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return $this->notFound();
        }

        $article = Article::query()
            ->with(['category'])
            ->published()
            ->where('slug', $slug)
            ->first();

        if (! $article instanceof Article) {
            return $this->notFound();
        }

        $articlePayload = [
            ...$this->summaryPayload($article),
            'content_html' => trim(ArticleHtmlPresenter::markdownToHtml((string) $article->content)),
        ];

        if ($article->updated_at !== null) {
            $articlePayload['updated_at'] = $article->updated_at->toIso8601String();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'article' => $articlePayload,
            ],
        ]);
    }

    /**
     * @return array{slug:string,title:string,summary:string,category:array{slug:string,name:string},published_at:string,cover_image?:array{src:string,alt:string}}
     */
    private function summaryPayload(Article $article): array
    {
        $payload = [
            'slug' => (string) $article->slug,
            'title' => (string) $article->title,
            'summary' => ArticleHtmlPresenter::cardSummary($article, 160),
            'category' => [
                'slug' => (string) ($article->category?->slug ?? 'updates'),
                'name' => (string) ($article->category?->name ?? '资讯'),
            ],
            'published_at' => ($article->published_at ?? $article->created_at ?? now())->toIso8601String(),
        ];

        $coverImage = trim((string) $article->cover_image);
        if ($coverImage !== '') {
            $payload['cover_image'] = [
                'src' => $coverImage,
                'alt' => (string) $article->title,
            ];
        }

        return $payload;
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'error' => [
                'code' => 'article_not_found',
                'message' => '未找到该资讯内容。',
            ],
        ], 404);
    }
}

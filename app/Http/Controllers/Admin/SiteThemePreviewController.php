<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\ArticleController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PageController;
use App\Support\Site\SitePageUrl;
use App\Support\Site\SiteThemeCatalog;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SiteThemePreviewController extends Controller
{
    public function __construct(
        private readonly SiteThemeCatalog $siteThemeCatalog
    ) {}

    public function show(Request $request, string $theme): View
    {
        if (! in_array($theme, $this->siteThemeCatalog->ids(), true)) {
            throw new NotFoundHttpException;
        }

        $page = $request->query('preview_page', 'home');
        if (! is_string($page) || ! in_array($page, ['home', 'about', 'products', 'news', 'contact', 'article'], true)) {
            throw new NotFoundHttpException;
        }

        $slug = $request->query('slug', '');
        if ($page === 'article' && (! is_string($slug) || trim($slug) === '')) {
            throw new NotFoundHttpException;
        }

        SitePageUrl::markPreview($request, $theme);

        return SiteThemeViewResolver::usingTheme(
            $theme,
            fn (): View => match ($page) {
                'home' => app(HomeController::class)->index($request),
                'about' => app(PageController::class)->about(),
                'products' => app(PageController::class)->products(),
                'news' => app(PageController::class)->news($request),
                'contact' => app(PageController::class)->contact(),
                'article' => app(ArticleController::class)->show(trim($slug)),
            }
        );
    }
}

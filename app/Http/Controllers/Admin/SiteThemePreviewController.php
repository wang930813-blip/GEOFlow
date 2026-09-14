<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\HomeController;
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

        return SiteThemeViewResolver::usingTheme(
            $theme,
            fn (): View => app(HomeController::class)->index($request)
        );
    }
}

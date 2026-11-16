<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 15:12:50
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： SiteThemeViewResolver.php
 * @Description: 解析官网正式主题与只读预览主题对应的 Blade 视图。
 */

namespace App\Support\Site;

use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;

/**
 * 前台主题视图链：优先 {@see resources/views/theme/{themeId}/} 下模板，不存在则回退 {@see resources/views/site/}。
 */
final class SiteThemeViewResolver
{
    /**
     * @Name: activeThemeId
     * @Description: 返回当前请求应渲染的已安装主题；预览参数仅覆盖本次请求，不修改站点设置。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 15:12:50
     * @UpdateTime: 2026-11-11 15:12:50
     * @Return string 当前请求使用的安全主题目录名
     * @Throws 无
     */
    public static function activeThemeId(): string
    {
        $id = trim(SiteSettingsBag::get('active_theme', ''));
        $defaultThemeId = trim((string) config('geoflow.default_theme', ''));
        if ($id === '') {
            $id = $defaultThemeId;
        }

        $request = request();
        if ($request->query->has('theme_preview')) {
            $previewThemeId = trim((string) $request->query('theme_preview', ''));
            if ($previewThemeId === 'default') {
                $id = $defaultThemeId;
            } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $previewThemeId) === 1
                && in_array($previewThemeId, app(SiteThemeCatalog::class)->ids(), true)) {
                $id = $previewThemeId;
            }
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $id) === 1 ? $id : '';
    }

    /**
     * 渲染第一个存在的视图：theme.{themeId}.{template} → site.{template}。
     *
     * @param  array<string, mixed>  $data
     */
    public static function first(string $template, array $data = []): ViewContract
    {
        return View::first(self::candidateViews($template), $data);
    }

    /**
     * @Name: candidateViews
     * @Description: 按当前活动主题解析页面视图；将平台产品列表入口兼容映射到标准主题产品页，再回退到系统默认视图。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 15:12:50
     * @UpdateTime: 2026-11-16 10:52:20
     * @Param string $template 控制器请求的页面视图标识
     * @Return list<string> 按优先级排列的候选视图名称
     * @Throws 无
     */
    public static function candidateViews(string $template): array
    {
        $views = [];
        $themeId = self::activeThemeId();
        if ($themeId !== '') {
            $views[] = 'theme.'.$themeId.'.'.$template;
            if ($template === 'products.index') {
                $views[] = 'theme.'.$themeId.'.products';
            }
        }
        $views[] = 'site.'.$template;

        return $views;
    }
}

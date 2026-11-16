<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-16
 *
 * @Time: 14:40:28
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteDesignController.php
 *
 * @Description: 提供当前站点官网首页可视化编辑配置的查看和保存入口。
 */

namespace App\Http\Controllers\WebsiteAdmin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use App\Support\Site\SiteThemeViewResolver;
use App\Support\Site\WebsiteVisualEditorConfig;
use App\Support\WebsiteAdminContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class WebsiteDesignController extends Controller
{
    /**
     * @Name: index
     *
     * @Description: 展示当前已选主题的首页区块、可编辑文案和同域实时预览，不提供主题切换能力。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Param Request $request 当前网站后台请求
     * @Param WebsiteVisualEditorConfig $editor 当前站点可视化配置服务
     * @Param SiteThemeCatalog $catalog 已安装主题目录
     *
     * @Return View 当前站点首页装修编辑页
     *
     * @Throws \Symfony\Component\HttpKernel\Exception\HttpException 未登录或缺少读取权限时返回 403
     */
    public function index(
        Request $request,
        WebsiteVisualEditorConfig $editor,
        SiteThemeCatalog $catalog
    ): View {
        $context = $this->context($request);
        $this->authorize($context, 'site.design.read');
        $settings = SiteSettingsBag::all();
        $themeId = SiteThemeViewResolver::activeThemeId();
        $theme = collect($catalog->all())->firstWhere('id', $themeId);

        return view('website-admin.design', [
            'pageTitle' => '网站装修',
            'context' => $context,
            'themeId' => $themeId,
            'themeName' => (string) ($theme['name'] ?? '当前主题'),
            'editor' => $editor->state($themeId, $settings),
            'previewUrl' => route('site.home', ['site_editor_preview' => '1']),
        ]);
    }

    /**
     * @Name: save
     *
     * @Description: 验证并保存站点级导航及当前主题的首页字段、区块顺序和显示状态，同时保留其他主题的装修配置。
     *
     * @Url [PUT] /admin/design
     *      登录 是
     *      section_order array 必选 当前主题首页区块的完整顺序
     *      hidden_sections array 可选 需要隐藏的首页区块标识
     *      navigation_order array 必选 顶部导航的完整顺序
     *      hidden_navigation array 可选 需要隐藏的顶部导航标识
     *      navigation_labels array 必选 顶部导航名称
     *      fields array 必选 当前主题首页文案字段
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:40:28
     *
     * @Return RedirectResponse 返回装修编辑页并显示保存结果
     *
     * @Throws ValidationException 区块顺序、文案字段或长度不符合当前主题定义
     * @Throws \Symfony\Component\HttpKernel\Exception\HttpException 未登录或缺少编辑权限时返回 403
     */
    public function save(Request $request, WebsiteVisualEditorConfig $editor): RedirectResponse
    {
        $context = $this->context($request);
        $this->authorize($context, 'site.design.edit');

        $themeId = SiteThemeViewResolver::activeThemeId();
        $schema = $editor->schema($themeId);
        abort_if($schema === [], 422, '当前主题尚未接入可视化编辑。');

        $sectionIds = array_column($schema, 'id');
        $navigationIds = $editor->navigationIds();
        $fieldIds = collect($schema)->flatMap(fn (array $section): array => $section['fields'])->unique()->values()->all();
        $definitions = Arr::only($editor->fieldDefinitions(), $fieldIds);
        $rules = [
            'section_order' => ['required', 'array', 'size:'.count($sectionIds)],
            'section_order.*' => ['required', 'string', 'distinct', Rule::in($sectionIds)],
            'hidden_sections' => ['sometimes', 'array'],
            'hidden_sections.*' => ['required', 'string', 'distinct', Rule::in($sectionIds)],
            'fields' => ['required', 'array'],
            'navigation_order' => ['required', 'array', 'size:'.count($navigationIds)],
            'navigation_order.*' => ['required', 'string', 'distinct', Rule::in($navigationIds)],
            'hidden_navigation' => ['sometimes', 'array'],
            'hidden_navigation.*' => ['required', 'string', 'distinct', Rule::in($navigationIds)],
            'navigation_labels' => ['required', 'array'],
        ];
        foreach ($definitions as $fieldId => $definition) {
            $rules['fields.'.$fieldId] = ['present', 'nullable', 'string', 'max:'.$definition['max']];
        }
        foreach ($navigationIds as $navigationId) {
            $rules['navigation_labels.'.$navigationId] = ['present', 'required', 'string', 'max:40'];
        }

        $validated = $request->validate($rules);
        $sectionOrder = array_values($validated['section_order']);
        if (count(array_unique($sectionOrder)) !== count($sectionIds)
            || array_diff($sectionIds, $sectionOrder) !== []) {
            throw ValidationException::withMessages([
                'section_order' => '首页区块顺序不完整，请刷新页面后重试。',
            ]);
        }
        $navigationOrder = array_values($validated['navigation_order']);
        if (count(array_unique($navigationOrder)) !== count($navigationIds)
            || array_diff($navigationIds, $navigationOrder) !== []) {
            throw ValidationException::withMessages([
                'navigation_order' => '导航顺序不完整，请刷新页面后重试。',
            ]);
        }

        $settings = SiteSettingsBag::all();
        $stored = json_decode((string) ($settings['website_visual_editor'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];
        $themeConfigurations = is_array($stored['themes'] ?? null) ? $stored['themes'] : [];
        if (! is_array($stored['themes'] ?? null) && $stored !== []) {
            $legacyThemeId = trim((string) ($stored['theme_id'] ?? ''));
            if ($legacyThemeId === '' || $legacyThemeId === $themeId) {
                $themeConfigurations[$themeId] = Arr::only($stored, ['section_order', 'hidden_sections', 'fields']);
            }
        }

        $themeStored = is_array($themeConfigurations[$themeId] ?? null) ? $themeConfigurations[$themeId] : [];
        $storedFields = is_array($themeStored['fields'] ?? null) ? $themeStored['fields'] : [];
        $fields = array_replace($storedFields, Arr::only($validated['fields'], $fieldIds));
        $hiddenSections = array_values(array_unique($validated['hidden_sections'] ?? []));
        $value = json_encode([
            'version' => 2,
            'navigation_order' => $navigationOrder,
            'hidden_navigation' => array_values(array_unique($validated['hidden_navigation'] ?? [])),
            'navigation_labels' => Arr::only($validated['navigation_labels'], $navigationIds),
            'themes' => array_replace($themeConfigurations, [
                $themeId => [
                    'section_order' => $sectionOrder,
                    'hidden_sections' => $hiddenSections,
                    'fields' => $fields,
                ],
            ]),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($context, $value): void {
            SiteSetting::query()->updateOrCreate(
                [
                    'site_id' => (int) $context->site->id,
                    'setting_key' => 'website_visual_editor',
                ],
                [
                    'owner_admin_id' => (int) $context->owner->id,
                    'setting_value' => $value,
                ]
            );
        });
        SiteSettingsBag::forget();

        return redirect()->route('website-admin.design')->with('status', '首页装修已保存并生效');
    }

    /**
     * @Name: context
     *
     * @Description: 读取网站后台认证中间件写入的当前站点和角色上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Param Request $request 当前网站后台请求
     *
     * @Return WebsiteAdminContext 已认证的站点后台上下文
     *
     * @Throws \Symfony\Component\HttpKernel\Exception\HttpException 缺少认证上下文时返回 403
     */
    private function context(Request $request): WebsiteAdminContext
    {
        $context = $request->attributes->get('website_admin');
        abort_unless($context instanceof WebsiteAdminContext, 403);

        return $context;
    }

    /**
     * @Name: authorize
     *
     * @Description: 校验网站管理员是否具有指定的首页装修细分权限。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Param WebsiteAdminContext $context 当前站点后台上下文
     * @Param string $permission 所需权限标识
     *
     * @Return void 权限通过后继续执行
     *
     * @Throws \Symfony\Component\HttpKernel\Exception\HttpException 权限不足时返回 403
     */
    private function authorize(WebsiteAdminContext $context, string $permission): void
    {
        abort_unless($context->allows($permission), 403);
    }
}

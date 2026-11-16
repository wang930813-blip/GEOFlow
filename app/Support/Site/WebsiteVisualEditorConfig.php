<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-16
 *
 * @Time: 14:46:46
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteVisualEditorConfig.php
 *
 * @Description: 管理官网可视化编辑器支持的主题区块、字段和站点装修配置。
 */

namespace App\Support\Site;

use Illuminate\Support\Arr;

final class WebsiteVisualEditorConfig
{
    private const NAVIGATION_ITEMS = [
        'home' => '首页',
        'about' => '关于我们',
        'products' => '产品服务',
        'news' => '资讯中心',
        'contact' => '联系我们',
    ];

    private const FIELD_DEFINITIONS = [
        'hero_title' => ['label' => '首屏标题', 'multiline' => false, 'max' => 200],
        'hero_description' => ['label' => '首屏介绍', 'multiline' => true, 'max' => 1000],
        'brand_label_1' => ['label' => '服务标签 1', 'multiline' => false, 'max' => 60],
        'brand_label_2' => ['label' => '服务标签 2', 'multiline' => false, 'max' => 60],
        'brand_label_3' => ['label' => '服务标签 3', 'multiline' => false, 'max' => 60],
        'introduction_title' => ['label' => '介绍标题', 'multiline' => false, 'max' => 200],
        'introduction_content' => ['label' => '介绍内容', 'multiline' => true, 'max' => 5000],
        'services_title' => ['label' => '区块标题', 'multiline' => false, 'max' => 200],
        'services_summary' => ['label' => '区块说明', 'multiline' => true, 'max' => 1000],
        'workflow_title' => ['label' => '区块标题', 'multiline' => false, 'max' => 200],
        'workflow_summary' => ['label' => '区块说明', 'multiline' => true, 'max' => 1000],
        'about_band_title' => ['label' => '区块标题', 'multiline' => false, 'max' => 200],
        'about_band_summary' => ['label' => '区块说明', 'multiline' => true, 'max' => 1000],
        'contact_heading' => ['label' => '区块标题', 'multiline' => false, 'max' => 200],
        'contact_description' => ['label' => '区块说明', 'multiline' => true, 'max' => 1000],
        'news_title' => ['label' => '区块标题', 'multiline' => false, 'max' => 200],
        'news_summary' => ['label' => '区块说明', 'multiline' => true, 'max' => 1000],
    ];

    private const THEME_SECTIONS = [
        'zk-blue-tech-028' => [
            ['id' => 'hero', 'label' => '首屏', 'fields' => ['hero_title', 'hero_description']],
            ['id' => 'workflow', 'label' => '工作方式', 'fields' => ['workflow_title', 'workflow_summary']],
            ['id' => 'introduction', 'label' => '企业介绍', 'fields' => ['introduction_title', 'introduction_content']],
            ['id' => 'services', 'label' => '产品与服务', 'fields' => ['services_title', 'services_summary']],
            ['id' => 'contact', 'label' => '联系引导', 'fields' => ['contact_heading', 'contact_description']],
            ['id' => 'news', 'label' => '资讯内容', 'fields' => ['news_title']],
        ],
        'zk-violet-cloud-029' => [
            ['id' => 'hero', 'label' => '首屏', 'fields' => ['hero_title', 'hero_description']],
            ['id' => 'introduction', 'label' => '品牌概览', 'fields' => ['introduction_title', 'introduction_content']],
            ['id' => 'services', 'label' => '产品与服务', 'fields' => ['services_title', 'services_summary']],
            ['id' => 'about', 'label' => '关于我们', 'fields' => ['about_band_title', 'about_band_summary']],
            ['id' => 'news', 'label' => '资讯内容', 'fields' => ['news_title']],
        ],
        'qcx-black-tech-agency-027' => [
            ['id' => 'hero', 'label' => '首屏', 'fields' => ['hero_title', 'hero_description']],
            ['id' => 'services', 'label' => '服务内容', 'fields' => ['services_title', 'services_summary']],
            ['id' => 'workflow', 'label' => '工作流程', 'fields' => ['workflow_title']],
            ['id' => 'news', 'label' => '资讯内容', 'fields' => ['news_title']],
        ],
        'qcx-patrix-studio-026' => [
            ['id' => 'hero', 'label' => '首屏', 'fields' => ['hero_title', 'hero_description']],
            ['id' => 'brand-strip', 'label' => '服务标签', 'fields' => ['brand_label_1', 'brand_label_2', 'brand_label_3']],
            ['id' => 'services', 'label' => '服务内容', 'fields' => ['services_title', 'services_summary']],
            ['id' => 'workflow', 'label' => '工作方式', 'fields' => ['workflow_title', 'workflow_summary']],
            ['id' => 'news', 'label' => '资讯内容', 'fields' => ['news_title', 'news_summary']],
        ],
        'qcx-midnight-it-015' => [
            ['id' => 'hero', 'label' => '首屏', 'fields' => ['hero_title', 'hero_description']],
            ['id' => 'services', 'label' => '产品服务', 'fields' => ['services_title', 'services_summary']],
            ['id' => 'workflow', 'label' => '服务路径', 'fields' => ['workflow_title', 'workflow_summary']],
            ['id' => 'scenarios', 'label' => '业务场景', 'fields' => ['about_band_title', 'about_band_summary']],
            ['id' => 'news', 'label' => '最新资讯', 'fields' => ['news_title']],
        ],
    ];

    private const THEME_DEFAULTS = [
        'zk-blue-tech-028' => [
            'workflow_title' => '从问题出发，逐步推进',
            'workflow_summary' => '按清晰的工作顺序呈现需求、方案与交付环节。',
            'contact_heading' => '围绕你的业务，展开一次清晰沟通',
            'contact_description' => '先了解服务范围，再通过已公开的联系方式继续交流。',
            'news_title' => '近期资讯',
        ],
        'zk-violet-cloud-029' => [
            'about_band_title' => '关于我们',
            'news_title' => '近期内容',
        ],
        'qcx-black-tech-agency-027' => [
            'services_title' => '让技术真正服务于业务目标',
            'services_summary' => '具体产品与服务内容将在资料确认后展示。',
            'workflow_title' => '从问题出发，逐步交付清晰结果',
            'news_title' => '了解近期技术内容',
        ],
        'qcx-patrix-studio-026' => [
            'brand_label_1' => '策略梳理',
            'brand_label_2' => '产品体验',
            'brand_label_3' => '内容协作',
            'services_title' => '围绕真实工作场景组织服务',
            'services_summary' => '具体产品与服务内容将在资料确认后展示。',
            'news_title' => '了解近期内容',
            'news_summary' => '这里展示平台提供的最新资讯。',
            'workflow_title' => '让每一个阶段都有清楚的下一步',
            'workflow_summary' => '从问题梳理开始，逐步形成方向、方案与可执行的交付节奏。',
        ],
        'qcx-midnight-it-015' => [
            'workflow_title' => "从问题出发，\n让每一步都有方向",
            'workflow_summary' => '技术方案不必从复杂术语开始。先看清当下，再确定轻重缓急，让后续工作有清晰的衔接。',
            'about_band_title' => '让技术融入日常运转',
            'about_band_summary' => '不同的工作场景，对稳定性、安全性和协作方式有不同期待。',
            'news_title' => '近期发布的内容',
        ],
    ];

    /**
     * @Name: schema
     *
     * @Description: 返回指定主题可编辑的首页区块及字段；未适配主题返回空区块，防止编辑器误改主题视图。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Param string $themeId 当前已启用主题标识
     *
     * @Return array<int,array{id:string,label:string,fields:array<int,string>}>
     *
     * @Throws 无
     */
    public function schema(string $themeId): array
    {
        return self::THEME_SECTIONS[$themeId] ?? [];
    }

    /**
     * @Name: state
     *
     * @Description: 合并站点级导航与当前主题独立保存的文案、区块顺序和显示状态；兼容升级前的单主题配置。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:40:28
     *
     * @Param string $themeId 当前已启用主题标识
     * @Param array<string,string> $settings 当前站点设置键值
     *
     * @Return array<string,mixed> 可供管理界面和官网主题使用的装修状态
     *
     * @Throws 无
     */
    public function state(string $themeId, array $settings): array
    {
        $schema = $this->schema($themeId);
        $stored = json_decode((string) ($settings['website_visual_editor'] ?? ''), true);
        $stored = is_array($stored) ? $stored : [];
        $themeConfigurations = is_array($stored['themes'] ?? null) ? $stored['themes'] : null;
        $themeStored = [];
        if ($themeConfigurations !== null) {
            $themeStored = is_array($themeConfigurations[$themeId] ?? null) ? $themeConfigurations[$themeId] : [];
        } else {
            $legacyThemeId = trim((string) ($stored['theme_id'] ?? ''));
            if ($legacyThemeId === '' || $legacyThemeId === $themeId) {
                $themeStored = $stored;
            }
        }

        $storedFields = is_array($themeStored['fields'] ?? null) ? $themeStored['fields'] : [];
        $defaults = $this->defaultFields($themeId, $settings);
        $allowedFields = collect($schema)->flatMap(fn (array $section): array => $section['fields'])->unique()->values()->all();
        $fields = Arr::only($defaults, $allowedFields);

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $storedFields) && is_string($storedFields[$field])) {
                $fields[$field] = $storedFields[$field];
            }
        }

        $sectionIds = array_column($schema, 'id');
        $storedOrder = is_array($themeStored['section_order'] ?? null) ? $themeStored['section_order'] : [];
        $order = array_values(array_unique(array_filter($storedOrder, static fn (mixed $id): bool => is_string($id) && in_array($id, $sectionIds, true))));
        foreach ($sectionIds as $sectionId) {
            if (! in_array($sectionId, $order, true)) {
                $order[] = $sectionId;
            }
        }

        $storedHidden = is_array($themeStored['hidden_sections'] ?? null) ? $themeStored['hidden_sections'] : [];
        $hidden = array_values(array_unique(array_filter($storedHidden, static fn (mixed $id): bool => is_string($id) && in_array($id, $sectionIds, true))));
        $sectionsById = collect($schema)->keyBy('id');
        $sections = array_map(static function (string $id) use ($sectionsById, $hidden): array {
            $section = $sectionsById->get($id);

            return [
                'id' => $id,
                'label' => $section['label'],
                'visible' => ! in_array($id, $hidden, true),
                'fields' => array_map(static fn (string $field): array => [
                    'id' => $field,
                    ...self::FIELD_DEFINITIONS[$field],
                ], $section['fields']),
            ];
        }, $order);

        $storedNavigationOrder = is_array($stored['navigation_order'] ?? null) ? $stored['navigation_order'] : [];
        $navigationOrder = array_values(array_unique(array_filter(
            $storedNavigationOrder,
            static fn (mixed $id): bool => is_string($id) && array_key_exists($id, self::NAVIGATION_ITEMS)
        )));
        foreach (array_keys(self::NAVIGATION_ITEMS) as $navigationId) {
            if (! in_array($navigationId, $navigationOrder, true)) {
                $navigationOrder[] = $navigationId;
            }
        }
        $storedNavigationHidden = is_array($stored['hidden_navigation'] ?? null) ? $stored['hidden_navigation'] : [];
        $hiddenNavigation = array_values(array_unique(array_filter(
            $storedNavigationHidden,
            static fn (mixed $id): bool => is_string($id) && array_key_exists($id, self::NAVIGATION_ITEMS)
        )));
        $storedNavigationLabels = is_array($stored['navigation_labels'] ?? null) ? $stored['navigation_labels'] : [];
        $navigationLabels = self::NAVIGATION_ITEMS;
        foreach ($navigationLabels as $navigationId => $defaultLabel) {
            if (isset($storedNavigationLabels[$navigationId]) && is_string($storedNavigationLabels[$navigationId])) {
                $navigationLabels[$navigationId] = $storedNavigationLabels[$navigationId];
            }
        }
        $navigation = array_map(static fn (string $id): array => [
            'id' => $id,
            'label' => $navigationLabels[$id],
            'visible' => ! in_array($id, $hiddenNavigation, true),
        ], $navigationOrder);

        return [
            'theme_id' => $themeId,
            'supported' => $schema !== [],
            'sections' => $sections,
            'section_order' => $order,
            'hidden_sections' => $hidden,
            'fields' => $fields,
            'navigation' => $navigation,
            'navigation_order' => $navigationOrder,
            'hidden_navigation' => $hiddenNavigation,
            'navigation_labels' => $navigationLabels,
        ];
    }

    /**
     * @Name: fieldDefinitions
     *
     * @Description: 返回可视化编辑器允许提交的字段规则，供后端验证而不接受任意模板变量。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Return array<string,array{label:string,multiline:bool,max:int}>
     *
     * @Throws 无
     */
    public function fieldDefinitions(): array
    {
        return self::FIELD_DEFINITIONS;
    }

    /**
     * @Name: navigationIds
     *
     * @Description: 返回首页、关于、产品、资讯和联系五个固定导航键，确保链接目标始终绑定系统页面。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Return list<string> 固定导航键
     *
     * @Throws 无
     */
    public function navigationIds(): array
    {
        return array_keys(self::NAVIGATION_ITEMS);
    }

    /**
     * @Name: defaultFields
     *
     * @Description: 从当前站点设置和主题默认文案构建编辑表单初始值，初次编辑时完整预览当前首页内容。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Param string $themeId 当前已启用主题标识
     * @Param array<string,string> $settings 当前站点设置键值
     *
     * @Return array<string,string> 标准化后的可编辑字段
     *
     * @Throws 无
     */
    private function defaultFields(string $themeId, array $settings): array
    {
        $siteName = trim((string) ($settings['site_name'] ?? ''));
        $subtitle = trim((string) ($settings['site_subtitle'] ?? ''));
        $description = trim((string) ($settings['site_description'] ?? ''));
        $defaults = self::THEME_DEFAULTS[$themeId] ?? [];

        return [
            'hero_title' => $subtitle !== '' ? $subtitle : $siteName,
            'hero_description' => $description,
            'introduction_title' => trim((string) ($settings['introduction_title'] ?? '')) ?: ($subtitle !== '' ? $subtitle : $siteName),
            'introduction_content' => trim((string) ($settings['introduction_content'] ?? '')) ?: $description,
            'services_title' => trim((string) ($settings['products_services_title'] ?? '')) ?: (string) ($defaults['services_title'] ?? '产品与服务'),
            'services_summary' => $description,
            'workflow_title' => (string) ($defaults['workflow_title'] ?? ''),
            'workflow_summary' => (string) ($defaults['workflow_summary'] ?? ''),
            'about_band_title' => trim((string) ($settings['about_title'] ?? '')) ?: (string) ($defaults['about_band_title'] ?? '关于我们'),
            'about_band_summary' => $description,
            'contact_heading' => (string) ($defaults['contact_heading'] ?? '联系我们'),
            'contact_description' => (string) ($defaults['contact_description'] ?? $description),
            'news_title' => (string) ($defaults['news_title'] ?? '近期资讯'),
            'news_summary' => (string) ($defaults['news_summary'] ?? ''),
        ];
    }
}

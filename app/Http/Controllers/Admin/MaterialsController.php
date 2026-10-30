<?php

/**
 * Created by Codex.
 * @Date: 2026-10-30
 * @Time: 01:13:11
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MaterialsController.php
 * @Description: 管理端素材管理总览及站点统计。
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\Task;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 素材管理首页控制器。
 */
class MaterialsController extends Controller
{
    public function __construct(private readonly AdminDataScope $adminDataScope) {}

    /**
     * 展示素材管理总览页。
     *
     * @UpdateTime: 2026-10-30 13:44:31
     */
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return view('admin.materials.index', [
            'pageTitle' => __('admin.materials.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
            'canManageProtectedWorkflows' => $admin->canManageProtectedWorkflows(),
            'stats' => $this->loadStats($admin),
        ]);
    }

    /**
     * 加载素材管理统计数据。
     *
     * @return array{
     *     keyword_libraries:int,
     *     total_keywords:int,
     *     title_libraries:int,
     *     total_titles:int,
     *     image_libraries:int,
     *     total_images:int,
     *     knowledge_bases:int,
     *     knowledge_usage_count:int,
     *     authors:int
     * }
     */
    private function loadStats(Admin $admin): array
    {
        $knowledgeUsageQuery = Task::query()->where(function (Builder $query): void {
            $query->whereHas('knowledgeBase')
                ->orWhereHas('knowledgeBases');
        });
        $this->adminDataScope->applySiteScope($knowledgeUsageQuery, $admin);

        return [
            'keyword_libraries' => $this->visibleCount(KeywordLibrary::class, $admin),
            'total_keywords' => $this->visibleCount(Keyword::class, $admin),
            'title_libraries' => $this->visibleCount(TitleLibrary::class, $admin),
            'total_titles' => $this->visibleCount(Title::class, $admin),
            'image_libraries' => $this->visibleCount(ImageLibrary::class, $admin),
            'total_images' => $this->visibleCount(Image::class, $admin),
            'knowledge_bases' => $this->visibleCount(KnowledgeBase::class, $admin),
            'knowledge_usage_count' => $knowledgeUsageQuery->count(),
            'authors' => $this->visibleCount(Author::class, $admin),
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function visibleCount(string $modelClass, Admin $admin): int
    {
        /** @var Builder $query */
        $query = $modelClass::query();
        $this->adminDataScope->applySiteScope($query, $admin);

        return $query->count();
    }
}

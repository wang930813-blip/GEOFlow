<?php

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
     */
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return view('admin.materials.index', [
            'pageTitle' => __('admin.materials.page_title'),
            'activeMenu' => 'materials',
            'adminSiteName' => AdminWeb::siteName(),
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
     *     authors:int
     * }
     */
    private function loadStats(Admin $admin): array
    {
        return [
            'keyword_libraries' => $this->visibleCount(KeywordLibrary::class, $admin),
            'total_keywords' => $this->visibleCount(Keyword::class, $admin),
            'title_libraries' => $this->visibleCount(TitleLibrary::class, $admin),
            'total_titles' => $this->visibleCount(Title::class, $admin),
            'image_libraries' => $this->visibleCount(ImageLibrary::class, $admin),
            'total_images' => $this->visibleCount(Image::class, $admin),
            'knowledge_bases' => $this->visibleCount(KnowledgeBase::class, $admin),
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

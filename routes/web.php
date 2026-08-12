<?php

/**
 * Web 路由：前台与 Blade 管理后台（路径见 config/geoflow.admin_base_path，默�?geo_admin）�? */

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminRegistrationController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminWelcomeController;
use App\Http\Controllers\Admin\AgentUserController;
use App\Http\Controllers\Admin\AiModelController;
use App\Http\Controllers\Admin\AiPromptController;
use App\Http\Controllers\Admin\AiSpecialPromptController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\ArticleSelfMediaPublishController;
use App\Http\Controllers\Admin\AuthorController;
use App\Http\Controllers\Admin\B2BWebsiteController;
use App\Http\Controllers\Admin\BrandDiagnosisController;
use App\Http\Controllers\Admin\BrandDiagnosisOfficialLinkController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CrebeeAccountController;
use App\Http\Controllers\Admin\CrebeePublishRecordController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DistributionController;
use App\Http\Controllers\Admin\GeoReportController;
use App\Http\Controllers\Admin\ImageLibraryController;
use App\Http\Controllers\Admin\KeywordLibraryController;
use App\Http\Controllers\Admin\KnowledgeBaseController;
use App\Http\Controllers\Admin\LegacyController;
use App\Http\Controllers\Admin\MaterialsController;
use App\Http\Controllers\Admin\McpServerController;
use App\Http\Controllers\Admin\MediaDistribution\CreditController as MediaDistributionCreditController;
use App\Http\Controllers\Admin\MediaDistribution\ReportController as MediaDistributionReportController;
use App\Http\Controllers\Admin\MediaDistribution\ResourceController as MediaDistributionResourceController;
use App\Http\Controllers\Admin\MediaDistribution\SettingController as MediaDistributionSettingController;
use App\Http\Controllers\Admin\MediaDistribution\SubmissionController as MediaDistributionSubmissionController;
use App\Http\Controllers\Admin\MonitoringCenterController;
use App\Http\Controllers\Admin\PlanSubscriptionController;
use App\Http\Controllers\Admin\PlanUsageController;
use App\Http\Controllers\Admin\PlatformPlanController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SiteContextController;
use App\Http\Controllers\Admin\SiteManagementController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\SnapshotVoucherController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TitleLibraryController;
use App\Http\Controllers\Admin\UrlImportController;
use App\Http\Controllers\Admin\VideoGenerationController;
use App\Http\Controllers\Admin\VideoSelfMediaPublishController;
use App\Http\Controllers\BrandDiagnosisSnapshotController;
use App\Http\Controllers\MediaSubmissionPreviewController;
use App\Http\Controllers\MonitoringReportShareController;
use App\Http\Controllers\Site\ArchiveController;
use App\Http\Controllers\Site\ArticleController as SiteArticleController;
use App\Http\Controllers\Site\CategoryController as SiteCategoryController;
use App\Http\Controllers\Site\HomeController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/brand-diagnosis/snapshot/{token}', [BrandDiagnosisSnapshotController::class, 'show'])
    ->name('brand-diagnosis.snapshot')
    ->where('token', '[A-Za-z0-9]{48}');

Route::get('/media-submission-preview/{submission}/{token}', [MediaSubmissionPreviewController::class, 'show'])
    ->name('media-submission-preview.show')
    ->whereNumber('submission');

Route::get('/monitoring-report/share/{token}', [MonitoringReportShareController::class, 'show'])
    ->name('monitoring-report-share.show')
    ->where('token', '[A-Za-z0-9]+');

Route::middleware(['site.domain', 'site.locale', 'site.view_log'])->group(function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('site.home');
    Route::get('/archive', [ArchiveController::class, 'index'])->name('site.archive');
    Route::get('/archive/{year}/{month}', [ArchiveController::class, 'month'])
        ->name('site.archive.month')
        ->where(['year' => '[0-9]{4}', 'month' => '[0-9]{2}']);
    Route::get('/category/{slug}', [SiteCategoryController::class, 'show'])->name('site.category');
    Route::get('/article/{slug}', [SiteArticleController::class, 'show'])->name('site.article');
});

$adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale'])->group(function () {
    // 通用入口与语言切换
    Route::get('locale/{locale}', [AdminAuthController::class, 'switchLocale'])->name('locale.switch');
    Route::get('snapshot-voucher', [SnapshotVoucherController::class, 'show'])->name('snapshot-voucher.show');

    Route::get('/', function () {
        return Auth::guard('admin')->check()
            ? redirect()->route('admin.dashboard')
            : redirect()->route('admin.login');
    })->name('entry');

    // 访客认证路由
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminAuthController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminAuthController::class, 'login'])->name('login.attempt');
        Route::get('register', [AdminRegistrationController::class, 'show'])->name('register');
        Route::post('register', [AdminRegistrationController::class, 'store'])->middleware('throttle:5,1')->name('register.store');
        Route::get('register/captcha', [AdminRegistrationController::class, 'captcha'])->middleware('throttle:20,1')->name('register.captcha');
    });

    // Protected admin routes
    Route::middleware(['admin.auth', 'admin.site', 'admin.activity'])->group(function () {
        // Session and dashboard
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::post('sites/switch', [SiteContextController::class, 'switch'])->name('sites.switch');
        Route::post('welcome/dismiss', [AdminWelcomeController::class, 'dismiss'])->name('welcome.dismiss');
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('profile', [ProfileController::class, 'index'])->name('profile.index');
        Route::get('b2b-websites', [B2BWebsiteController::class, 'index'])->name('b2b-websites.index');
        Route::post('b2b-websites/{websiteKey}/open', [B2BWebsiteController::class, 'open'])
            ->name('b2b-websites.open')
            ->where('websiteKey', '[A-Za-z0-9_-]+');
        Route::get('geo-reports', [GeoReportController::class, 'index'])->name('geo-reports.index');
        Route::get('monitoring-center', [MonitoringCenterController::class, 'index'])->name('monitoring-center.index');
        Route::post('monitoring-center/share', [MonitoringCenterController::class, 'share'])->name('monitoring-center.share');
        Route::get('brand-diagnosis', [BrandDiagnosisController::class, 'index'])->name('brand-diagnosis.index');
        Route::post('brand-diagnosis', [BrandDiagnosisController::class, 'store'])->name('brand-diagnosis.store');
        Route::get('brand-diagnosis/reusable-questions', [BrandDiagnosisController::class, 'reusableQuestions'])
            ->name('brand-diagnosis.reusable-questions');
        Route::get('brand-diagnosis/open-api', [BrandDiagnosisController::class, 'openApiIndex'])
            ->name('brand-diagnosis.open-api.index')
            ->middleware('admin.super');
        Route::post('brand-diagnosis/{run}/confirm', [BrandDiagnosisController::class, 'confirm'])
            ->name('brand-diagnosis.confirm')
            ->whereNumber('run');
        Route::delete('brand-diagnosis/{run}', [BrandDiagnosisController::class, 'destroy'])
            ->name('brand-diagnosis.destroy')
            ->whereNumber('run');
        Route::get('brand-diagnosis/{run}/report', [BrandDiagnosisController::class, 'report'])
            ->name('brand-diagnosis.report')
            ->whereNumber('run');
        Route::get('brand-diagnosis/{run}/report/download', [BrandDiagnosisController::class, 'downloadReport'])
            ->name('brand-diagnosis.report.download')
            ->whereNumber('run');
        Route::get('brand-diagnosis/{run}/official-links', [BrandDiagnosisOfficialLinkController::class, 'edit'])
            ->name('brand-diagnosis.official-links.edit')
            ->whereNumber('run');
        Route::put('brand-diagnosis/{run}/official-links', [BrandDiagnosisOfficialLinkController::class, 'update'])
            ->name('brand-diagnosis.official-links.update')
            ->whereNumber('run');
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');

        Route::prefix('media-distribution')->name('media-distribution.')->group(function () {
            Route::get('resources', [MediaDistributionResourceController::class, 'index'])->name('resources.index');
            Route::get('submissions', [MediaDistributionSubmissionController::class, 'index'])->name('submissions.index');
            Route::get('submissions/media-resources/search', [MediaDistributionSubmissionController::class, 'searchResources'])->name('submissions.media-resources.search');
            Route::get('submissions/export', [MediaDistributionSubmissionController::class, 'export'])->name('submissions.export');
            Route::post('submissions/bulk', [MediaDistributionSubmissionController::class, 'bulkStore'])->name('submissions.bulk-store');
            Route::post('submissions', [MediaDistributionSubmissionController::class, 'store'])->name('submissions.store');
            Route::get('submissions/{submission}', [MediaDistributionSubmissionController::class, 'show'])->name('submissions.show');
            Route::post('submissions/{submission}/sync', [MediaDistributionSubmissionController::class, 'sync'])->name('submissions.sync');
            Route::post('submissions/{submission}/cancel', [MediaDistributionSubmissionController::class, 'cancel'])->name('submissions.cancel');
            Route::post('submissions/{submission}/appeal', [MediaDistributionSubmissionController::class, 'appeal'])->name('submissions.appeal');
            Route::get('credits', [MediaDistributionCreditController::class, 'index'])->name('credits.index');
            Route::get('credits/export', [MediaDistributionCreditController::class, 'export'])->name('credits.export');
            Route::get('credits/consumption-export', [MediaDistributionCreditController::class, 'consumptionExport'])->name('credits.consumption-export');
            Route::middleware('admin.super')->group(function () {
                Route::get('reports/profit', [MediaDistributionReportController::class, 'profit'])->name('reports.profit');
                Route::get('reports/profit/export', [MediaDistributionReportController::class, 'profitExport'])->name('reports.profit-export');
                Route::post('resources/sync', [MediaDistributionResourceController::class, 'sync'])->name('resources.sync');
                Route::post('resources/price-multiplier', [MediaDistributionResourceController::class, 'updatePriceMultiplier'])->name('resources.price-multiplier');
                Route::post('resources/{resource}/price', [MediaDistributionResourceController::class, 'updatePrice'])->name('resources.price');
                Route::post('resources/{resource}/site-price', [MediaDistributionResourceController::class, 'updateSitePrice'])->name('resources.site-price');
                Route::post('credits/{account}/recharge', [MediaDistributionCreditController::class, 'recharge'])->name('credits.recharge');
                Route::post('credits/{account}/adjust', [MediaDistributionCreditController::class, 'adjust'])->name('credits.adjust');
                Route::get('settings', [MediaDistributionSettingController::class, 'index'])->name('settings.index');
                Route::post('settings', [MediaDistributionSettingController::class, 'update'])->name('settings.update');
            });
        });
        // 任务管理（Blade 新路径）
        Route::prefix('tasks')->name('tasks.')->group(function () {
            Route::get('/', [TaskController::class, 'index'])->name('index');
            Route::post('{taskId}/toggle-status', [TaskController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('{taskId}/delete', [TaskController::class, 'destroyTask'])->name('delete');
            Route::get('create', [TaskController::class, 'create'])->name('create');
            Route::post('create', [TaskController::class, 'store'])->name('store');
            Route::get('{taskId}/edit', [TaskController::class, 'edit'])->name('edit');
            Route::put('{taskId}', [TaskController::class, 'update'])->name('update');
            Route::get('health-check', [TaskController::class, 'healthCheck'])->name('health');
            Route::post('batch/start', [TaskController::class, 'batchAction'])->name('batch');
        });

        // Distribution management
        Route::prefix('distribution')->name('distribution.')->group(function () {
            Route::get('/', [DistributionController::class, 'index'])->name('index');
            Route::get('create', [DistributionController::class, 'create'])->name('create');
            Route::post('create', [DistributionController::class, 'store'])->name('store');
            Route::get('jobs', [DistributionController::class, 'jobs'])->name('jobs');
            Route::get('jobs/{distributionId}/edit', [DistributionController::class, 'editArticle'])->name('article.edit')->whereNumber('distributionId');
            Route::put('jobs/{distributionId}', [DistributionController::class, 'updateArticle'])->name('article.update')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/delete', [DistributionController::class, 'deleteArticle'])->name('article.delete')->whereNumber('distributionId');
            Route::post('jobs/{distributionId}/retry', [DistributionController::class, 'retry'])->name('retry')->whereNumber('distributionId');
            Route::get('{channelId}/edit', [DistributionController::class, 'edit'])->name('edit')->whereNumber('channelId');
            Route::put('{channelId}', [DistributionController::class, 'update'])->name('update')->whereNumber('channelId');
            Route::post('{channelId}/pause', [DistributionController::class, 'pause'])->name('pause')->whereNumber('channelId');
            Route::post('{channelId}/activate', [DistributionController::class, 'activate'])->name('activate')->whereNumber('channelId');
            Route::post('{channelId}/rotate-secret', [DistributionController::class, 'rotateSecret'])->name('rotate-secret')->whereNumber('channelId');
            Route::post('{channelId}/reveal-secret', [DistributionController::class, 'revealSecret'])->name('reveal-secret')->whereNumber('channelId');
            Route::post('{channelId}/download-package', [DistributionController::class, 'downloadPackage'])->name('download-package')->whereNumber('channelId');
            Route::post('{channelId}/sync-settings', [DistributionController::class, 'syncSettings'])->name('sync-settings')->whereNumber('channelId');
            Route::get('{channelId}', [DistributionController::class, 'show'])->name('show')->whereNumber('channelId');
            Route::post('{channelId}/health', [DistributionController::class, 'health'])->name('health')->whereNumber('channelId');
        });

        // 文章管理（Blade 新路径）
        Route::prefix('articles')->name('articles.')->group(function () {
            Route::get('/', [ArticleController::class, 'index'])->name('index');
            Route::post('batch/update-status', [ArticleController::class, 'batchUpdateStatus'])->name('batch.update-status');
            Route::post('batch/update-review', [ArticleController::class, 'batchUpdateReview'])->name('batch.update-review');
            Route::post('batch/delete', [ArticleController::class, 'batchDelete'])->name('batch.delete');
            Route::post('batch/restore', [ArticleController::class, 'batchRestore'])->name('batch.restore');
            Route::post('batch/force-delete', [ArticleController::class, 'batchForceDelete'])->name('batch.force-delete');
            Route::post('trash/empty', [ArticleController::class, 'emptyTrash'])->name('trash.empty');
            Route::get('create', [ArticleController::class, 'create'])->name('create');
            Route::post('create', [ArticleController::class, 'store'])->name('store');
            Route::post('{articleId}/restore', [ArticleController::class, 'restore'])->name('restore')->whereNumber('articleId');
            Route::post('{articleId}/force-delete', [ArticleController::class, 'forceDelete'])->name('force-delete')->whereNumber('articleId');
            Route::get('{articleId}/edit', [ArticleController::class, 'edit'])->name('edit');
            Route::put('{articleId}', [ArticleController::class, 'update'])->name('update');
            Route::post('{articleId}/self-media/publish', [ArticleSelfMediaPublishController::class, 'store'])
                ->name('self-media.publish')
                ->whereNumber('articleId');
            Route::get('{articleId}/download', [ArticleController::class, 'downloadWord'])->name('download')->whereNumber('articleId');
        });

        Route::prefix('video-generations')->name('video-generations.')->group(function () {
            Route::get('/', [VideoGenerationController::class, 'index'])->name('index');
            Route::get('create', [VideoGenerationController::class, 'create'])->name('create');
            Route::post('/', [VideoGenerationController::class, 'store'])->name('store');
            Route::get('{videoGeneration}', [VideoGenerationController::class, 'show'])->name('show')->whereNumber('videoGeneration');
            Route::get('{videoGeneration}/download', [VideoGenerationController::class, 'download'])->name('download')->whereNumber('videoGeneration');
            Route::post('{videoGeneration}/cover', [VideoGenerationController::class, 'updateCover'])->name('cover.update')->whereNumber('videoGeneration');
            Route::delete('{videoGeneration}', [VideoGenerationController::class, 'destroy'])->name('destroy')->whereNumber('videoGeneration');
            Route::post('{videoGeneration}/self-media/publish', [VideoSelfMediaPublishController::class, 'store'])
                ->name('self-media.publish')
                ->whereNumber('videoGeneration');
        });

        // Category management
        Route::prefix('categories')->name('categories.')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])->name('index');
            Route::get('create', [CategoryController::class, 'create'])->name('create');
            Route::post('create', [CategoryController::class, 'store'])->name('store');
            Route::get('{categoryId}/edit', [CategoryController::class, 'edit'])->name('edit');
            Route::put('{categoryId}', [CategoryController::class, 'update'])->name('update');
            Route::post('{categoryId}/delete', [CategoryController::class, 'destroy'])->name('delete');
        });

        // Author management
        Route::prefix('authors')->name('authors.')->group(function () {
            Route::get('/', [AuthorController::class, 'index'])->name('index');
            Route::get('create', [AuthorController::class, 'create'])->name('create');
            Route::post('create', [AuthorController::class, 'store'])->name('store');
            Route::get('{authorId}/edit', [AuthorController::class, 'edit'])->name('edit');
            Route::get('{authorId}/detail', [AuthorController::class, 'detail'])->name('detail');
            Route::put('{authorId}', [AuthorController::class, 'update'])->name('update');
            Route::post('{authorId}/delete', [AuthorController::class, 'destroy'])->name('delete');
        });

        // Keyword library management
        Route::prefix('keyword-libraries')->name('keyword-libraries.')->group(function () {
            Route::get('/', [KeywordLibraryController::class, 'index'])->name('index');
            Route::get('create', [KeywordLibraryController::class, 'create'])->name('create');
            Route::post('create', [KeywordLibraryController::class, 'store'])->name('store');
            Route::get('{libraryId}/edit', [KeywordLibraryController::class, 'edit'])->name('edit');
            Route::get('{libraryId}/detail', [KeywordLibraryController::class, 'detail'])->name('detail');
            Route::get('{libraryId}/inclusion-results/export', [KeywordLibraryController::class, 'exportInclusionResults'])->name('inclusion-results.export');
            Route::get('{libraryId}/inclusion-snapshot', [KeywordLibraryController::class, 'inclusionSnapshot'])->name('inclusion-snapshot');
            Route::post('{libraryId}/inclusion-checks', [KeywordLibraryController::class, 'storeInclusionCheck'])->name('inclusion-checks.store');
            Route::post('{libraryId}/inclusion-runs/{run}/pause', [KeywordLibraryController::class, 'pauseInclusionRun'])->name('inclusion-runs.pause');
            Route::delete('{libraryId}/inclusion-runs/{run}', [KeywordLibraryController::class, 'destroyInclusionRun'])->name('inclusion-runs.destroy');
            Route::post('{libraryId}/keywords', [KeywordLibraryController::class, 'storeKeyword'])->name('keywords.store');
            Route::put('{libraryId}/keywords/{keywordId}', [KeywordLibraryController::class, 'updateKeyword'])->name('keywords.update');
            Route::post('{libraryId}/keywords/suggest', [KeywordLibraryController::class, 'suggestKeywords'])->name('keywords.suggest');
            Route::post('{libraryId}/keywords/bulk-store', [KeywordLibraryController::class, 'bulkStoreKeywords'])->name('keywords.bulk-store');
            Route::post('{libraryId}/keywords/{keywordId}/questions', [KeywordLibraryController::class, 'storeQuestion'])->name('keywords.questions.store');
            Route::put('{libraryId}/keywords/{keywordId}/questions/{questionId}', [KeywordLibraryController::class, 'updateQuestion'])->name('keywords.questions.update');
            Route::delete('{libraryId}/keywords/{keywordId}/questions/{questionId}', [KeywordLibraryController::class, 'destroyQuestion'])->name('keywords.questions.delete');
            Route::post('{libraryId}/keywords/{keywordId}/questions/generate', [KeywordLibraryController::class, 'generateQuestions'])->name('keywords.questions.generate');
            Route::post('{libraryId}/keywords/delete', [KeywordLibraryController::class, 'destroyKeywords'])->name('keywords.delete');
            Route::post('{libraryId}/import', [KeywordLibraryController::class, 'importKeywords'])->name('import');
            Route::put('{libraryId}/detail', [KeywordLibraryController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{libraryId}', [KeywordLibraryController::class, 'update'])->name('update');
            Route::post('{libraryId}/delete', [KeywordLibraryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：标题库管理
        Route::prefix('title-libraries')->name('title-libraries.')->group(function () {
            Route::get('/', [TitleLibraryController::class, 'index'])->name('index');
            Route::get('create', [TitleLibraryController::class, 'create'])->name('create');
            Route::post('create', [TitleLibraryController::class, 'store'])->name('store');
            Route::get('{libraryId}/edit', [TitleLibraryController::class, 'edit'])->name('edit');
            Route::get('{libraryId}/detail', [TitleLibraryController::class, 'detail'])->name('detail');
            Route::post('{libraryId}/titles', [TitleLibraryController::class, 'storeTitle'])->name('titles.store');
            Route::put('{libraryId}/titles/{titleId}', [TitleLibraryController::class, 'updateTitle'])->name('titles.update');
            Route::post('{libraryId}/titles/delete', [TitleLibraryController::class, 'destroyTitles'])->name('titles.delete');
            Route::post('{libraryId}/import', [TitleLibraryController::class, 'importTitles'])->name('import');
            Route::get('{libraryId}/ai-generate', [TitleLibraryController::class, 'aiGenerate'])->name('ai-generate');
            Route::post('{libraryId}/ai-generate', [TitleLibraryController::class, 'generateWithAi'])->name('ai-generate.submit');
            Route::put('{libraryId}', [TitleLibraryController::class, 'update'])->name('update');
            Route::post('{libraryId}/delete', [TitleLibraryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：图片库管理
        Route::prefix('image-libraries')->name('image-libraries.')->group(function () {
            Route::get('/', [ImageLibraryController::class, 'index'])->name('index');
            Route::get('create', [ImageLibraryController::class, 'create'])->name('create');
            Route::post('create', [ImageLibraryController::class, 'store'])->name('store');
            Route::get('{libraryId}/edit', [ImageLibraryController::class, 'edit'])->name('edit');
            Route::get('{libraryId}/detail', [ImageLibraryController::class, 'detail'])->name('detail');
            Route::post('{libraryId}/images/upload', [ImageLibraryController::class, 'uploadImages'])->name('images.upload');
            Route::post('{libraryId}/images/delete', [ImageLibraryController::class, 'destroyImages'])->name('images.delete');
            Route::put('{libraryId}/detail', [ImageLibraryController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{libraryId}', [ImageLibraryController::class, 'update'])->name('update');
            Route::post('{libraryId}/delete', [ImageLibraryController::class, 'destroy'])->name('delete');
        });

        // 素材管理：知识库管理
        Route::prefix('knowledge-bases')->name('knowledge-bases.')->group(function () {
            Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
            Route::get('create', [KnowledgeBaseController::class, 'create'])->name('create');
            Route::post('create', [KnowledgeBaseController::class, 'store'])->name('store');
            Route::get('{knowledgeBaseId}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
            Route::get('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'detail'])->name('detail');
            Route::post('upload', [KnowledgeBaseController::class, 'uploadFile'])->name('upload');
            Route::post('{knowledgeBaseId}/chunks/refresh', [KnowledgeBaseController::class, 'refreshChunks'])->name('chunks.refresh');
            Route::put('{knowledgeBaseId}/detail', [KnowledgeBaseController::class, 'updateFromDetail'])->name('detail.update');
            Route::put('{knowledgeBaseId}', [KnowledgeBaseController::class, 'update'])->name('update');
            Route::post('{knowledgeBaseId}/delete', [KnowledgeBaseController::class, 'destroy'])->name('delete');
        });

        // 业务页面
        Route::get('materials', [MaterialsController::class, 'index'])->name('materials.index');
        Route::get('url-import', [UrlImportController::class, 'index'])->name('url-import');
        Route::post('url-import', [UrlImportController::class, 'store'])->name('url-import.store');
        Route::get('url-import/history', [UrlImportController::class, 'history'])->name('url-import.history');
        Route::post('url-import/history/bulk-delete', [UrlImportController::class, 'bulkDelete'])->name('url-import.bulk-delete');
        Route::post('url-import/{jobId}/run', [UrlImportController::class, 'run'])
            ->name('url-import.run')
            ->whereNumber('jobId');
        Route::get('url-import/{jobId}/status', [UrlImportController::class, 'status'])
            ->name('url-import.status')
            ->whereNumber('jobId');
        Route::post('url-import/{jobId}/commit', [UrlImportController::class, 'commit'])
            ->name('url-import.commit')
            ->whereNumber('jobId');
        Route::get('url-import/{jobId}', [UrlImportController::class, 'show'])
            ->name('url-import.show')
            ->whereNumber('jobId');

        // AI 配置模块（配置器 / 模型 / 提示词）
        Route::middleware('admin.ai_config_manager')->group(function () {
            Route::get('ai-configurator', [LegacyController::class, 'aiConfigurator'])->name('ai.configurator');
            Route::prefix('ai-models')->name('ai-models.')->group(function () {
                Route::get('/', [AiModelController::class, 'index'])->name('index');
                Route::post('create', [AiModelController::class, 'store'])->name('store');
                Route::put('{modelId}', [AiModelController::class, 'update'])->name('update');
                Route::post('{modelId}/test', [AiModelController::class, 'testConnection'])->name('test');
                Route::post('{modelId}/delete', [AiModelController::class, 'destroy'])->name('delete');
                Route::post('default-embedding', [AiModelController::class, 'updateDefaultEmbedding'])->name('default-embedding');
            });
            Route::get('ai-prompts', [AiPromptController::class, 'index'])->name('ai-prompts');
            Route::post('ai-prompts/create', [AiPromptController::class, 'store'])->name('ai-prompts.store');
            Route::put('ai-prompts/{promptId}', [AiPromptController::class, 'update'])->name('ai-prompts.update');
            Route::post('ai-prompts/{promptId}/delete', [AiPromptController::class, 'destroy'])->name('ai-prompts.delete');
            Route::get('ai-special-prompts', [AiSpecialPromptController::class, 'index'])->name('ai-special-prompts');
            Route::post('ai-special-prompts/keyword', [AiSpecialPromptController::class, 'updateKeyword'])->name('ai-special-prompts.keyword');
            Route::post('ai-special-prompts/description', [AiSpecialPromptController::class, 'updateDescription'])->name('ai-special-prompts.description');
        });

        Route::prefix('site-settings')->name('site-settings.')->group(function () {
            Route::get('/', [SiteSettingsController::class, 'index'])->name('index');
            Route::post('/', [SiteSettingsController::class, 'update'])->name('update');
            Route::post('admin-display', [SiteSettingsController::class, 'updateAdminDisplay'])->name('admin-display');
            Route::post('registration', [SiteSettingsController::class, 'updateRegistration'])->name('registration');
            Route::post('theme', [SiteSettingsController::class, 'updateTheme'])->name('theme');
            Route::post('article-detail-ads', [SiteSettingsController::class, 'updateArticleDetailAds'])->name('ads');
            Route::get('sensitive-words', [SecuritySettingsController::class, 'index'])->name('sensitive-words');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])->name('sensitive-words.store');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])
                ->name('sensitive-words.delete')
                ->whereNumber('wordId');
        });
        Route::prefix('security-settings')->name('security-settings.')->group(function () {
            Route::get('/', fn () => redirect()->route('admin.site-settings.sensitive-words'))->name('index');
            Route::post('sensitive-words', [SecuritySettingsController::class, 'storeSensitiveWords'])->name('words.store');
            Route::post('sensitive-words/{wordId}/delete', [SecuritySettingsController::class, 'destroySensitiveWord'])->name('words.delete');
            Route::get('password', [SecuritySettingsController::class, 'editPassword'])->name('password.edit');
            Route::post('password', [SecuritySettingsController::class, 'updatePassword'])->name('password.update');
        });

        Route::prefix('api-tokens')->name('api-tokens.')->group(function () {
            Route::get('/', [ApiTokenController::class, 'index'])->name('index');
            Route::post('/', [ApiTokenController::class, 'store'])->name('store');
            Route::post('{tokenId}/revoke', [ApiTokenController::class, 'revoke'])->name('revoke');
        });

        Route::prefix('mcp-server')->name('mcp-server.')->group(function () {
            Route::get('/', [McpServerController::class, 'index'])->name('index');
            Route::get('skills/ceying-geo-content-operations/download', [McpServerController::class, 'downloadSkill'])
                ->name('skills.download');
            Route::post('keys', [McpServerController::class, 'store'])->name('keys.store');
            Route::post('keys/{keyId}/scopes', [McpServerController::class, 'updateScopes'])
                ->name('keys.scopes')
                ->whereNumber('keyId');
            Route::post('keys/{keyId}/revoke', [McpServerController::class, 'revoke'])
                ->name('keys.revoke')
                ->whereNumber('keyId');
        });
        Route::middleware('admin.agent')->prefix('agent-users')->name('agent-users.')->group(function () {
            Route::get('/', [AgentUserController::class, 'index'])->name('index');
            Route::post('/', [AgentUserController::class, 'store'])->name('store');
            Route::post('{adminId}', [AgentUserController::class, 'update'])->name('update');
            Route::post('{adminId}/toggle-status', [AgentUserController::class, 'toggleStatus'])->name('toggle-status');
        });
        Route::prefix('plan-usages')->name('plan-usages.')->group(function () {
            Route::get('/', [PlanUsageController::class, 'index'])->name('index');
        });
        Route::prefix('crebee-accounts')->name('crebee-accounts.')->group(function () {
            Route::get('/', [CrebeeAccountController::class, 'index'])->name('index');
            Route::post('requests', [CrebeeAccountController::class, 'storeRequest'])->name('requests.store');
            Route::post('requests/{bindRequest}/processing', [CrebeeAccountController::class, 'markRequestProcessing'])
                ->name('requests.processing')
                ->whereNumber('bindRequest');
            Route::post('requests/{bindRequest}/fail', [CrebeeAccountController::class, 'failRequest'])
                ->name('requests.fail')
                ->whereNumber('bindRequest');
            Route::post('{account}/bind', [CrebeeAccountController::class, 'bind'])
                ->name('bind')
                ->whereNumber('account');
            Route::post('{account}/unbind', [CrebeeAccountController::class, 'unbind'])
                ->name('unbind')
                ->whereNumber('account');
        });
        Route::get('crebee-publish-records', [CrebeePublishRecordController::class, 'index'])
            ->name('crebee-publish-records.index');
        Route::prefix('sites/manage')->name('sites.manage.')->group(function () {
            Route::get('/', [SiteManagementController::class, 'index'])->name('index');
            Route::post('/', [SiteManagementController::class, 'store'])->name('store');
            Route::post('{site}', [SiteManagementController::class, 'update'])->name('update');
            Route::post('{site}/toggle-status', [SiteManagementController::class, 'toggleStatus'])->name('toggle-status');
            Route::post('{site}/delete', [SiteManagementController::class, 'destroy'])->name('destroy');
        });
        // Super admin routes
        Route::middleware('admin.super')->group(function () {
            Route::prefix('platform-plans')->name('platform-plans.')->group(function () {
                Route::get('/', [PlatformPlanController::class, 'index'])->name('index');
                Route::post('/', [PlatformPlanController::class, 'store'])->name('store');
                Route::get('{plan}', [PlatformPlanController::class, 'show'])->name('show');
                Route::get('{plan}/edit', [PlatformPlanController::class, 'edit'])->name('edit');
                Route::post('{plan}', [PlatformPlanController::class, 'update'])->name('update');
                Route::post('{plan}/delete', [PlatformPlanController::class, 'destroy'])->name('destroy');
            });
            Route::prefix('plan-subscriptions')->name('plan-subscriptions.')->group(function () {
                Route::get('/', [PlanSubscriptionController::class, 'index'])->name('index');
                Route::post('/', [PlanSubscriptionController::class, 'store'])->name('store');
            });
            Route::prefix('admin-users')->name('admin-users.')->group(function () {
                Route::get('/', [AdminUserController::class, 'index'])->name('index');
                Route::post('create', [AdminUserController::class, 'store'])->name('store');
                Route::post('{adminId}/update', [AdminUserController::class, 'update'])->name('update');
                Route::post('{adminId}/toggle-status', [AdminUserController::class, 'toggleStatus'])->name('toggle-status');
                Route::post('{adminId}/delete', [AdminUserController::class, 'destroy'])->name('delete');
            });
            Route::get('admin-activity-logs', [AdminActivityLogController::class, 'index'])->name('admin-activity-logs');

        });
    });
});

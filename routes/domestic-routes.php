<?php

/**
 * Created by Codex.
 * @Date: 2026-10-29
 * @Time: 12:29:04
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： domestic-routes.php
 * @Description: 保留策影GEO国内版业务入口，并与上游平台路由并行注册。
 */

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/brand-diagnosis/snapshot/{token}', [\App\Http\Controllers\BrandDiagnosisSnapshotController::class, 'show'])
    ->name('brand-diagnosis.snapshot')
    ->where('token', '[A-Za-z0-9]{48}');

Route::get('/media-submission-preview/{submission}/{token}', [\App\Http\Controllers\MediaSubmissionPreviewController::class, 'show'])
    ->name('media-submission-preview.show')
    ->whereNumber('submission');

Route::get('/monitoring-report/share/{token}', [\App\Http\Controllers\MonitoringReportShareController::class, 'show'])
    ->name('monitoring-report-share.show')
    ->where('token', '[A-Za-z0-9]+');

Route::get('/product-cases', [\App\Http\Controllers\ProductCaseController::class, 'index'])->name('product-cases.index');
Route::get('/product-cases/{slug}', [\App\Http\Controllers\ProductCaseController::class, 'show'])
    ->name('product-cases.show')
    ->where('slug', '[A-Za-z0-9_-]+');

Route::middleware(['site.domain', 'site.locale', 'site.view_log'])->group(function (): void {
    Route::get('/news', [\App\Http\Controllers\Site\PageController::class, 'news'])->name('site.news');
    Route::get('/products', [\App\Http\Controllers\Site\PageController::class, 'products'])->name('site.products');
    Route::get('/contact', [\App\Http\Controllers\Site\PageController::class, 'contact'])->name('site.contact');
    Route::get('/api/articles', [\App\Http\Controllers\Site\ArticleFeedController::class, 'index'])->name('site.api.articles.index');
    Route::get('/api/articles/{slug}', [\App\Http\Controllers\Site\ArticleFeedController::class, 'show'])
        ->name('site.api.articles.show')
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
});

$adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

Route::prefix($adminPrefix)->name('admin.')->middleware(['admin.locale'])->group(function (): void {
    Route::get('snapshot-voucher', [\App\Http\Controllers\Admin\SnapshotVoucherController::class, 'show'])->name('snapshot-voucher.show');
    Route::get('product-case-library', [\App\Http\Controllers\ProductCaseController::class, 'index'])->name('product-case-library.index');
    Route::get('product-case-library/{slug}', [\App\Http\Controllers\ProductCaseController::class, 'show'])
        ->name('product-case-library.show')
        ->where('slug', '[A-Za-z0-9_-]+');
    Route::match(['get', 'post'], 'crebee-accounts/aitoearn/authorizations/callback', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'handleAiToEarnAuthorizationCallback'])
        ->withoutMiddleware([ValidateCsrfToken::class])
        ->name('crebee-accounts.aitoearn.authorizations.callback');

    Route::middleware('guest:admin')->group(function (): void {
        Route::get('register', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'show'])->name('register');
        Route::post('register', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('register.store');
        Route::get('register/captcha', [\App\Http\Controllers\Admin\AdminRegistrationController::class, 'captcha'])
            ->middleware('throttle:20,1')
            ->name('register.captcha');
    });

    Route::middleware(['admin.auth', 'admin.site', 'admin.activity'])->group(function (): void {
        Route::post('sites/switch', [\App\Http\Controllers\Admin\SiteContextController::class, 'switch'])->name('sites.switch');
        Route::get('profile', [\App\Http\Controllers\Admin\ProfileController::class, 'index'])->name('profile.index');
        Route::get('b2b-websites', [\App\Http\Controllers\Admin\B2BWebsiteController::class, 'index'])->name('b2b-websites.index');
        Route::post('b2b-websites/{websiteKey}/open', [\App\Http\Controllers\Admin\B2BWebsiteController::class, 'open'])
            ->name('b2b-websites.open')
            ->where('websiteKey', '[A-Za-z0-9_-]+');
        Route::get('geo-reports', [\App\Http\Controllers\Admin\GeoReportController::class, 'index'])->name('geo-reports.index');

        Route::prefix('articles')->name('articles.')->group(function (): void {
            Route::get('{articleId}/download', [\App\Http\Controllers\Admin\ArticleController::class, 'downloadWord'])
                ->whereNumber('articleId')
                ->name('download');
            Route::post('{articleId}/self-media/publish', [\App\Http\Controllers\Admin\ArticleSelfMediaPublishController::class, 'store'])
                ->whereNumber('articleId')
                ->name('self-media.publish');
        });

        Route::prefix('media-distribution')->name('media-distribution.')->group(function (): void {
            Route::get('resources', [\App\Http\Controllers\Admin\MediaDistribution\ResourceController::class, 'index'])->name('resources.index');
            Route::get('submissions', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'index'])->name('submissions.index');
            Route::get('submissions/media-resources/search', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'searchResources'])->name('submissions.media-resources.search');
            Route::get('submissions/export', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'export'])->name('submissions.export');
            Route::post('submissions/bulk', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'bulkStore'])->name('submissions.bulk-store');
            Route::post('submissions', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'store'])->name('submissions.store');
            Route::get('submissions/{submission}', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'show'])->name('submissions.show');
            Route::post('submissions/{submission}/sync', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'sync'])->name('submissions.sync');
            Route::post('submissions/{submission}/cancel', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'cancel'])->name('submissions.cancel');
            Route::post('submissions/{submission}/appeal', [\App\Http\Controllers\Admin\MediaDistribution\SubmissionController::class, 'appeal'])->name('submissions.appeal');
            Route::get('credits', [\App\Http\Controllers\Admin\MediaDistribution\CreditController::class, 'index'])->name('credits.index');
            Route::get('credits/export', [\App\Http\Controllers\Admin\MediaDistribution\CreditController::class, 'export'])->name('credits.export');
            Route::get('credits/consumption-export', [\App\Http\Controllers\Admin\MediaDistribution\CreditController::class, 'consumptionExport'])->name('credits.consumption-export');
            Route::middleware('admin.super')->group(function (): void {
                Route::get('reports/profit', [\App\Http\Controllers\Admin\MediaDistribution\ReportController::class, 'profit'])->name('reports.profit');
                Route::get('reports/profit/export', [\App\Http\Controllers\Admin\MediaDistribution\ReportController::class, 'profitExport'])->name('reports.profit-export');
                Route::post('resources/sync', [\App\Http\Controllers\Admin\MediaDistribution\ResourceController::class, 'sync'])->name('resources.sync');
                Route::post('resources/price-multiplier', [\App\Http\Controllers\Admin\MediaDistribution\ResourceController::class, 'updatePriceMultiplier'])->name('resources.price-multiplier');
                Route::post('resources/{resource}/price', [\App\Http\Controllers\Admin\MediaDistribution\ResourceController::class, 'updatePrice'])->name('resources.price');
                Route::post('resources/{resource}/site-price', [\App\Http\Controllers\Admin\MediaDistribution\ResourceController::class, 'updateSitePrice'])->name('resources.site-price');
                Route::post('credits/{account}/recharge', [\App\Http\Controllers\Admin\MediaDistribution\CreditController::class, 'recharge'])->name('credits.recharge');
                Route::post('credits/{account}/adjust', [\App\Http\Controllers\Admin\MediaDistribution\CreditController::class, 'adjust'])->name('credits.adjust');
                Route::get('settings', [\App\Http\Controllers\Admin\MediaDistribution\SettingController::class, 'index'])->name('settings.index');
                Route::post('settings', [\App\Http\Controllers\Admin\MediaDistribution\SettingController::class, 'update'])->name('settings.update');
            });
        });

        Route::prefix('keyword-libraries')->name('keyword-libraries.')->group(function (): void {
            Route::get('{libraryId}/inclusion-results/export', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'exportInclusionResults'])->name('inclusion-results.export');
            Route::get('{libraryId}/inclusion-snapshot', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'inclusionSnapshot'])->name('inclusion-snapshot');
            Route::post('{libraryId}/inclusion-checks', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'storeInclusionCheck'])->name('inclusion-checks.store');
            Route::post('{libraryId}/inclusion-runs/{run}/pause', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'pauseInclusionRun'])->name('inclusion-runs.pause');
            Route::delete('{libraryId}/inclusion-runs/{run}', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'destroyInclusionRun'])->name('inclusion-runs.destroy');
            Route::put('{libraryId}/keywords/{keywordId}', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'updateKeyword'])->name('keywords.update');
            Route::post('{libraryId}/keywords/suggest', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'suggestKeywords'])->name('keywords.suggest');
            Route::post('{libraryId}/keywords/bulk-store', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'bulkStoreKeywords'])->name('keywords.bulk-store');
            Route::post('{libraryId}/keywords/{keywordId}/questions', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'storeQuestion'])->name('keywords.questions.store');
            Route::put('{libraryId}/keywords/{keywordId}/questions/{questionId}', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'updateQuestion'])->name('keywords.questions.update');
            Route::delete('{libraryId}/keywords/{keywordId}/questions/{questionId}', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'destroyQuestion'])->name('keywords.questions.delete');
            Route::post('{libraryId}/keywords/{keywordId}/questions/generate', [\App\Http\Controllers\Admin\KeywordLibraryController::class, 'generateQuestions'])->name('keywords.questions.generate');
        });

        Route::prefix('title-libraries')->name('title-libraries.')->group(function (): void {
            Route::put('{libraryId}/titles/{titleId}', [\App\Http\Controllers\Admin\TitleLibraryController::class, 'updateTitle'])->name('titles.update');
        });

        Route::post('url-import/history/bulk-delete', [\App\Http\Controllers\Admin\UrlImportController::class, 'bulkDelete'])->name('url-import.bulk-delete');

        Route::prefix('site-settings')->name('site-settings.')->group(function (): void {
            Route::post('admin-display', [\App\Http\Controllers\Admin\SiteSettingsController::class, 'updateAdminDisplay'])->name('admin-display');
            Route::post('registration', [\App\Http\Controllers\Admin\SiteSettingsController::class, 'updateRegistration'])->name('registration');
        });

        Route::get('security-settings/password', [\App\Http\Controllers\Admin\SecuritySettingsController::class, 'editPassword'])
            ->name('security-settings.password.edit');

        Route::get('monitoring-center', [\App\Http\Controllers\Admin\MonitoringCenterController::class, 'index'])->name('monitoring-center.index');
        Route::post('monitoring-center/share', [\App\Http\Controllers\Admin\MonitoringCenterController::class, 'share'])->name('monitoring-center.share');

        Route::get('brand-diagnosis', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'index'])->name('brand-diagnosis.index');
        Route::post('brand-diagnosis', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'store'])->name('brand-diagnosis.store');
        Route::get('brand-diagnosis/reusable-questions', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'reusableQuestions'])->name('brand-diagnosis.reusable-questions');
        Route::get('brand-diagnosis/open-api', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'openApiIndex'])->middleware('admin.super')->name('brand-diagnosis.open-api.index');
        Route::post('brand-diagnosis/{run}/confirm', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'confirm'])->whereNumber('run')->name('brand-diagnosis.confirm');
        Route::delete('brand-diagnosis/{run}', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'destroy'])->whereNumber('run')->name('brand-diagnosis.destroy');
        Route::get('brand-diagnosis/{run}/report', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'report'])->whereNumber('run')->name('brand-diagnosis.report');
        Route::get('brand-diagnosis/{run}/report/download', [\App\Http\Controllers\Admin\BrandDiagnosisController::class, 'downloadReport'])->whereNumber('run')->name('brand-diagnosis.report.download');
        Route::get('brand-diagnosis/{run}/official-links', [\App\Http\Controllers\Admin\BrandDiagnosisOfficialLinkController::class, 'edit'])->whereNumber('run')->name('brand-diagnosis.official-links.edit');
        Route::put('brand-diagnosis/{run}/official-links', [\App\Http\Controllers\Admin\BrandDiagnosisOfficialLinkController::class, 'update'])->whereNumber('run')->name('brand-diagnosis.official-links.update');

        Route::prefix('video-generations')->name('video-generations.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'index'])->name('index');
            Route::get('create', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'create'])->name('create');
            Route::post('topic-candidates', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'topicCandidates'])->name('topic-candidates');
            Route::post('script-draft', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'scriptDraft'])->name('script-draft');
            Route::post('/', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'store'])->name('store');
            Route::get('{videoGeneration}', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'show'])->whereNumber('videoGeneration')->name('show');
            Route::get('{videoGeneration}/download', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'download'])->whereNumber('videoGeneration')->name('download');
            Route::post('{videoGeneration}/cover', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'updateCover'])->whereNumber('videoGeneration')->name('cover.update');
            Route::delete('{videoGeneration}', [\App\Http\Controllers\Admin\VideoGenerationController::class, 'destroy'])->whereNumber('videoGeneration')->name('destroy');
            Route::post('{videoGeneration}/self-media/publish', [\App\Http\Controllers\Admin\VideoSelfMediaPublishController::class, 'store'])->whereNumber('videoGeneration')->name('self-media.publish');
        });

        Route::prefix('mcp-server')->name('mcp-server.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Admin\McpServerController::class, 'index'])->name('index');
            Route::get('skills/ceying-geo-content-operations/download', [\App\Http\Controllers\Admin\McpServerController::class, 'downloadSkill'])->name('skills.download');
            Route::post('keys', [\App\Http\Controllers\Admin\McpServerController::class, 'store'])->name('keys.store');
            Route::post('keys/{keyId}/scopes', [\App\Http\Controllers\Admin\McpServerController::class, 'updateScopes'])->whereNumber('keyId')->name('keys.scopes');
            Route::post('keys/{keyId}/revoke', [\App\Http\Controllers\Admin\McpServerController::class, 'revoke'])->whereNumber('keyId')->name('keys.revoke');
        });

        Route::get('crebee-accounts', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'index'])->name('crebee-accounts.index');
        Route::prefix('crebee-accounts')->name('crebee-accounts.')->group(function (): void {
            Route::post('aitoearn/authorizations', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'startAiToEarnAuthorization'])->name('aitoearn.authorizations.start');
            Route::post('aitoearn/auth-sessions/{session}/sync', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'refreshAiToEarnAuthorization'])->whereNumber('session')->name('aitoearn.auth-sessions.sync');
            Route::post('aitoearn/accounts/sync', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'syncAiToEarnAccounts'])->name('aitoearn.accounts.sync');
            Route::post('aitoearn/accounts/{account}/unbind', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'unbindAiToEarnAccount'])->whereNumber('account')->name('aitoearn.accounts.unbind');
            Route::post('requests', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'storeRequest'])->name('requests.store');
            Route::post('requests/{bindRequest}/processing', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'markRequestProcessing'])->whereNumber('bindRequest')->name('requests.processing');
            Route::post('requests/{bindRequest}/fail', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'failRequest'])->whereNumber('bindRequest')->name('requests.fail');
            Route::post('{account}/bind', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'bind'])->whereNumber('account')->name('bind');
            Route::post('{account}/unbind', [\App\Http\Controllers\Admin\CrebeeAccountController::class, 'unbind'])->whereNumber('account')->name('unbind');
        });
        Route::get('crebee-publish-records', [\App\Http\Controllers\Admin\CrebeePublishRecordController::class, 'index'])->name('crebee-publish-records.index');
        Route::get('plan-usages', [\App\Http\Controllers\Admin\PlanUsageController::class, 'index'])->name('plan-usages.index');
        Route::prefix('manual-publish-stats')->name('manual-publish-stats.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Admin\ManualPublishStatController::class, 'index'])->name('index');
            Route::middleware('admin.super')->group(function (): void {
                Route::post('/', [\App\Http\Controllers\Admin\ManualPublishStatController::class, 'store'])->name('store');
                Route::delete('{manual_publish_stat}', [\App\Http\Controllers\Admin\ManualPublishStatController::class, 'destroy'])
                    ->whereNumber('manual_publish_stat')
                    ->name('destroy');
            });
        });
        Route::middleware('admin.agent')->prefix('agent-users')->name('agent-users.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Admin\AgentUserController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\AgentUserController::class, 'store'])->name('store');
            Route::post('{adminId}', [\App\Http\Controllers\Admin\AgentUserController::class, 'update'])->whereNumber('adminId')->name('update');
            Route::post('{adminId}/toggle-status', [\App\Http\Controllers\Admin\AgentUserController::class, 'toggleStatus'])->whereNumber('adminId')->name('toggle-status');
        });

        Route::prefix('sites/manage')->name('sites.manage.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Admin\SiteManagementController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\SiteManagementController::class, 'store'])->name('store');
            Route::post('{site}', [\App\Http\Controllers\Admin\SiteManagementController::class, 'update'])->whereNumber('site')->name('update');
            Route::post('{site}/toggle-status', [\App\Http\Controllers\Admin\SiteManagementController::class, 'toggleStatus'])->whereNumber('site')->name('toggle-status');
            Route::post('{site}/delete', [\App\Http\Controllers\Admin\SiteManagementController::class, 'destroy'])->whereNumber('site')->name('destroy');
        });

        Route::middleware('admin.super')->group(function (): void {
            Route::post('product-cases/{product_case}/toggle-status', [\App\Http\Controllers\Admin\ProductCaseController::class, 'toggleStatus'])
                ->whereNumber('product_case')
                ->name('product-cases.toggle-status');
            Route::resource('product-cases', \App\Http\Controllers\Admin\ProductCaseController::class)->except(['show']);
            Route::prefix('management-tools')->name('management-tools.')->group(function (): void {
                Route::get('/', [\App\Http\Controllers\Admin\ManagementToolController::class, 'index'])->name('index');
                Route::get('product-case-import', [\App\Http\Controllers\Admin\ManagementToolController::class, 'productCaseImport'])->name('product-case-import.create');
                Route::post('product-case-import', [\App\Http\Controllers\Admin\ManagementToolController::class, 'storeProductCaseImport'])->name('product-case-import.store');
                Route::get('product-case-import/{importId}', [\App\Http\Controllers\Admin\ManagementToolController::class, 'showProductCaseImport'])->whereNumber('importId')->name('product-case-import.show');
                Route::get('product-case-import/{importId}/status', [\App\Http\Controllers\Admin\ManagementToolController::class, 'productCaseImportStatus'])->whereNumber('importId')->name('product-case-import.status');
                Route::get('product-cases', [\App\Http\Controllers\Admin\ManagementProductCaseController::class, 'index'])->name('product-cases.index');
                Route::get('product-cases/{productCase}/edit', [\App\Http\Controllers\Admin\ManagementProductCaseController::class, 'edit'])->whereNumber('productCase')->name('product-cases.edit');
                Route::put('product-cases/{productCase}', [\App\Http\Controllers\Admin\ManagementProductCaseController::class, 'update'])->whereNumber('productCase')->name('product-cases.update');
                Route::post('product-cases/{productCase}/toggle-status', [\App\Http\Controllers\Admin\ManagementProductCaseController::class, 'toggleStatus'])->whereNumber('productCase')->name('product-cases.toggle-status');
                Route::delete('product-cases/{productCase}', [\App\Http\Controllers\Admin\ManagementProductCaseController::class, 'destroy'])->whereNumber('productCase')->name('product-cases.destroy');
            });
            Route::prefix('platform-plans')->name('platform-plans.')->group(function (): void {
                Route::get('/', [\App\Http\Controllers\Admin\PlatformPlanController::class, 'index'])->name('index');
                Route::post('/', [\App\Http\Controllers\Admin\PlatformPlanController::class, 'store'])->name('store');
                Route::get('{plan}', [\App\Http\Controllers\Admin\PlatformPlanController::class, 'show'])->name('show');
                Route::get('{plan}/edit', [\App\Http\Controllers\Admin\PlatformPlanController::class, 'edit'])->name('edit');
                Route::post('{plan}', [\App\Http\Controllers\Admin\PlatformPlanController::class, 'update'])->name('update');
                Route::post('{plan}/delete', [\App\Http\Controllers\Admin\PlatformPlanController::class, 'destroy'])->name('destroy');
            });
            Route::prefix('plan-subscriptions')->name('plan-subscriptions.')->group(function (): void {
                Route::get('/', [\App\Http\Controllers\Admin\PlanSubscriptionController::class, 'index'])->name('index');
                Route::post('/', [\App\Http\Controllers\Admin\PlanSubscriptionController::class, 'store'])->name('store');
            });
        });
    });
});

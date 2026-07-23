<?php

/**
 * GEOFlow REST API 路由（Laravel 默认挂载在 /api 前缀下，本文件内为 v1 子路径）。
 *
 * 中间件：api.request_id 注入/透传 X-Request-Id；api.auth 校验 Bearer；
 * api.scope:* 校验 Sanctum token abilities。幂等写操作在控制器内按 route_key 处理。
 *
 * @see bak/api/v1/index.php 遗留单入口对照
 */

use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandDiagnosisController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CrebeeAgentController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MaterialController;
use App\Http\Controllers\Api\V1\MediaResourceController;
use App\Http\Controllers\Api\V1\MediaSubmissionController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

// 实际路径形如：/api/v1/...
Route::prefix('v1')
    ->middleware(['api.request_id'])
    ->group(function (): void {
        // 公开：管理员登录，返回 API Token（无需 Bearer）
        Route::post('auth/login', [AuthController::class, 'login']);

        Route::middleware(['throttle:machine-api', 'brand-diagnosis.api-key'])->group(function (): void {
            Route::post('brand-diagnoses', [BrandDiagnosisController::class, 'store']);
            Route::get('brand-diagnoses/{taskKey}', [BrandDiagnosisController::class, 'show']);
        });

        // 需有效 Token + 对应 scope
        Route::middleware(['throttle:machine-api', 'api.auth', 'throttle:api-token'])->group(function (): void {
            // 机器凭证自检：独立 GEO MCP 服务使用，不要求额外业务 scope
            Route::get('auth/me', [AuthController::class, 'me'])->middleware('api.scope:mcp:connect');

            // catalog:read — 下拉元数据（模型、提示词、库、作者、分类等）
            Route::get('catalog', [CatalogController::class, 'show'])->middleware('api.scope:catalog:read');

            // tasks:* — 任务 CRUD、启停、入队、子 Job 列表
            Route::get('tasks', [TaskController::class, 'index'])->middleware('api.scope:tasks:read');
            Route::post('tasks', [TaskController::class, 'store'])->middleware('api.scope:tasks:write');
            Route::get('tasks/{task}', [TaskController::class, 'show'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:read');
            Route::patch('tasks/{task}', [TaskController::class, 'update'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/start', [TaskController::class, 'start'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/stop', [TaskController::class, 'stop'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/enqueue', [TaskController::class, 'enqueue'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::get('tasks/{task}/jobs', [TaskController::class, 'jobs'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:read');

            // jobs:read — 单条 task_runs 执行记录
            Route::get('jobs/{job}', [JobController::class, 'show'])
                ->whereNumber('job')
                ->middleware('api.scope:jobs:read');

            // materials:* — 后台素材库 CRUD 与库内条目管理
            Route::get('materials', [MaterialController::class, 'summary'])->middleware('api.scope:materials:read');
            Route::get('materials/{type}', [MaterialController::class, 'index'])->middleware('api.scope:materials:read');
            Route::post('materials/{type}', [MaterialController::class, 'store'])->middleware('api.scope:materials:write');
            Route::get('materials/{type}/{id}', [MaterialController::class, 'show'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:read');
            Route::patch('materials/{type}/{id}', [MaterialController::class, 'update'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::delete('materials/{type}/{id}', [MaterialController::class, 'destroy'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::get('materials/{type}/{id}/items', [MaterialController::class, 'items'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:read');
            Route::post('materials/{type}/{id}/items', [MaterialController::class, 'storeItem'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::delete('materials/{type}/{id}/items', [MaterialController::class, 'destroyItems'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');

            // articles:* — 文章 CRUD、审核、发布、软删
            Route::get('articles', [ArticleController::class, 'index'])->middleware('api.scope:articles:read');
            Route::post('articles', [ArticleController::class, 'store'])->middleware('api.scope:articles:write');
            Route::get('articles/{article}', [ArticleController::class, 'show'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:read');
            Route::patch('articles/{article}', [ArticleController::class, 'update'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:write');
            Route::post('articles/{article}/review', [ArticleController::class, 'review'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:publish');
            Route::post('articles/{article}/publish', [ArticleController::class, 'publish'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:publish');
            Route::post('articles/{article}/trash', [ArticleController::class, 'trash'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:write');

            Route::get('media/resources', [MediaResourceController::class, 'index'])->middleware('api.scope:media:read');
            Route::get('media/resources/{resource}', [MediaResourceController::class, 'show'])
                ->whereNumber('resource')
                ->middleware('api.scope:media:read');
            Route::get('media/submissions', [MediaSubmissionController::class, 'index'])->middleware('api.scope:media:read');
            Route::post('media/submissions', [MediaSubmissionController::class, 'store'])
                ->middleware(['api.scope:media:submit', 'throttle:mcp-paid-write']);
            Route::get('media/submissions/{submission}', [MediaSubmissionController::class, 'show'])
                ->whereNumber('submission')
                ->middleware('api.scope:media:read');
            Route::post('media/submissions/{submission}/sync', [MediaSubmissionController::class, 'sync'])
                ->whereNumber('submission')
                ->middleware('api.scope:media:sync');
            Route::post('media/submissions/{submission}/cancel', [MediaSubmissionController::class, 'cancel'])
                ->whereNumber('submission')
                ->middleware('api.scope:media:sync');
            Route::post('media/submissions/{submission}/appeal', [MediaSubmissionController::class, 'appeal'])
                ->whereNumber('submission')
                ->middleware('api.scope:media:sync');
        });

        Route::prefix('crebee-agent')
            ->middleware(['crebee.agent'])
            ->group(function (): void {
                Route::post('heartbeat', [CrebeeAgentController::class, 'heartbeat']);
                Route::post('accounts/sync', [CrebeeAgentController::class, 'syncAccounts']);
                Route::get('jobs/next', [CrebeeAgentController::class, 'nextJob']);
                Route::post('jobs/{job}/accepted', [CrebeeAgentController::class, 'accepted'])
                    ->whereNumber('job');
                Route::post('jobs/{job}/events', [CrebeeAgentController::class, 'events'])
                    ->whereNumber('job');
                Route::post('jobs/{job}/finished', [CrebeeAgentController::class, 'finished'])
                    ->whereNumber('job');
                Route::post('jobs/{job}/failed', [CrebeeAgentController::class, 'failed'])
                    ->whereNumber('job');
            });
    });

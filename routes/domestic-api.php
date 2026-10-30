<?php

/**
 * Created by Codex.
 * @Date: 2026-10-29
 * @Time: 10:42:45
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： domestic-api.php
 * @Description: 恢复策影GEO国内版品牌诊断、MCP和自媒体代理 API。
 */

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.request_id'])->group(function (): void {
    Route::middleware(['brand-diagnosis.lookup-api-key'])->group(function (): void {
        Route::get('brand-diagnoses/search', [\App\Http\Controllers\Api\V1\BrandDiagnosisLookupController::class, 'search']);
        Route::get('brand-diagnoses/search/status/{lookupId}', [\App\Http\Controllers\Api\V1\BrandDiagnosisLookupController::class, 'status'])
            ->where('lookupId', '[A-Za-z0-9_]+');
    });

    Route::middleware(['throttle:machine-api', 'brand-diagnosis.api-key'])->group(function (): void {
        Route::post('brand-diagnoses', [\App\Http\Controllers\Api\V1\BrandDiagnosisController::class, 'store']);
        Route::get('brand-diagnoses/{taskKey}', [\App\Http\Controllers\Api\V1\BrandDiagnosisController::class, 'show']);
    });

    Route::prefix('mcp')->middleware([
        'throttle:machine-api',
        'api.auth',
        'throttle:api-token',
        'api.scope:mcp:connect',
    ])->group(function (): void {
        Route::prefix('brand-diagnoses')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\Api\V1\McpBrandDiagnosisController::class, 'index'])->middleware('api.scope:brand-diagnoses:read');
            Route::post('/', [\App\Http\Controllers\Api\V1\McpBrandDiagnosisController::class, 'store'])->middleware('api.scope:brand-diagnoses:write');
            Route::get('{run}', [\App\Http\Controllers\Api\V1\McpBrandDiagnosisController::class, 'show'])->whereNumber('run')->middleware('api.scope:brand-diagnoses:read');
            Route::post('{run}/confirm', [\App\Http\Controllers\Api\V1\McpBrandDiagnosisController::class, 'confirm'])->whereNumber('run')->middleware('api.scope:brand-diagnoses:write');
        });
        Route::post('articles/{article}/publish', [\App\Http\Controllers\Api\V1\McpArticlePublicationController::class, 'publish'])
            ->whereNumber('article')->middleware('api.scope:articles:site-publish');
        Route::get('videos', [\App\Http\Controllers\Api\V1\McpVideoGenerationController::class, 'index'])->middleware('api.scope:videos:read');
        Route::post('videos', [\App\Http\Controllers\Api\V1\McpVideoGenerationController::class, 'store'])->middleware(['api.scope:videos:write', 'throttle:mcp-paid-write']);
        Route::get('videos/{video}', [\App\Http\Controllers\Api\V1\McpVideoGenerationController::class, 'show'])->whereNumber('video')->middleware('api.scope:videos:read');
    });

    Route::prefix('crebee-agent')->middleware(['crebee.agent'])->group(function (): void {
        Route::post('heartbeat', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'heartbeat']);
        Route::post('accounts/sync', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'syncAccounts']);
        Route::get('jobs/next', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'nextJob']);
        Route::post('jobs/{job}/accepted', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'accepted'])->whereNumber('job');
        Route::post('jobs/{job}/events', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'events'])->whereNumber('job');
        Route::post('jobs/{job}/finished', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'finished'])->whereNumber('job');
        Route::post('jobs/{job}/failed', [\App\Http\Controllers\Api\V1\CrebeeAgentController::class, 'failed'])->whereNumber('job');
    });
});

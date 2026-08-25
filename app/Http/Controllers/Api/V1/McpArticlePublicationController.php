<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-08-05
 *
 * @Time: 21:05:15
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpArticlePublicationController.php
 *
 * @Description: 提供 MCP Key 专用的文章站内发布接口，发布时自动通过审核且不复用后台审核权限。
 */

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpArticlePublicationController extends BaseApiController
{
    /**
     * 发布文章到 GEO 用户站点
     * 将当前 MCP Key 所属账号和绑定站点内未被拒绝的文章自动标记为通过并发布，不执行媒体投稿。
     *
     * @Url [POST] /api/v1/mcp/articles/{article}/publish
     *      登录 是
     *      article int 必选 当前账号未被拒绝的文章编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 21:05:15
     *
     * @UpdateTime: 2026-08-05 21:12:19
     *
     * @Return JsonResponse 已发布文章详情
     *
     * @Throws \App\Exceptions\ApiException 文章不存在、已被拒绝、账号或站点越权
     */
    public function publish(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /mcp/articles/{id}/publish');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success(
            $request,
            $articles->publishArticleFromMcp($article),
            200,
            'POST /mcp/articles/{id}/publish',
        );
    }
}

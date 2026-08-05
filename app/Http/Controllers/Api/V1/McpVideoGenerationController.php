<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-08-05
 *
 * @Time: 18:12:22
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpVideoGenerationController.php
 *
 * @Description: 提供 MCP Key 专用的视频生成任务创建、列表查询和详情查询接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\VideoGenerationJob;
use App\Services\Api\IdempotencyService;
use App\Services\VideoGeneration\VideoGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

class McpVideoGenerationController extends BaseApiController
{
    /**
     * 查询视频生成任务列表
     * 分页查询 MCP Key 所属账号在绑定站点内创建的视频生成任务。
     *
     * @Url [GET] /api/v1/mcp/videos
     *      登录 是
     *
     *      分页参数：
     *      page int 可选 页码，默认 1
     *      per_page int 可选 每页数量，范围 1 至 100，默认 20
     *
     *      筛选参数：
     *      status string 可选 视频生成任务状态
     *      search string 可选 视频标题或主题关键词
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Return JsonResponse 当前账号和站点的视频生成任务分页结果
     *
     * @Throws ApiException MCP Key 账号或绑定站点上下文无效
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 20)));
        $status = trim((string) $request->query('status', ''));
        $search = trim((string) $request->query('search', ''));

        $query = $this->videoQuery($request)
            ->when($status !== '', fn ($builder) => $builder->where('status', mb_substr($status, 0, 32, 'UTF-8')))
            ->when($search !== '', function ($builder) use ($search): void {
                $keyword = mb_substr($search, 0, 120, 'UTF-8');
                $builder->where(function ($nested) use ($keyword): void {
                    $nested
                        ->where('title', 'like', '%'.$keyword.'%')
                        ->orWhere('subject', 'like', '%'.$keyword.'%');
                });
            })
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $videos = $query->forPage($page, $perPage)->get();

        return $this->success($request, [
            'items' => $videos->map(fn (VideoGenerationJob $video): array => $this->videoPayload($video))->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * 创建视频生成任务
     * 使用现有视频生成服务创建当前账号和站点的视频任务，并按生成数量扣减视频生成额度。
     *
     * @Url [POST] /api/v1/mcp/videos
     *      登录 是
     *      subject string 必选 视频主题，最长 500 个字符
     *      script string 可选 视频脚本，最长 10000 个字符
     *      terms string 可选 视频素材关键词，最长 1000 个字符
     *      negative_terms string 可选 排除关键词，最长 1000 个字符
     *      video_source string 可选 素材来源，允许 pexels、pixabay、local
     *      video_aspect string 可选 视频比例，允许 9:16、16:9、1:1
     *      video_count int 可选 生成数量，范围 1 至 5
     *      cover_image string 可选 封面图地址，最长 1000 个字符
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Return JsonResponse 新建视频生成任务摘要和额度影响
     *
     * @Throws ApiException 参数、权限、套餐额度或幂等请求无效
     */
    public function store(Request $request, VideoGenerationService $service): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /mcp/videos');
        if ($cached !== null) {
            return $cached;
        }

        $payload = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'script' => ['nullable', 'string', 'max:10000'],
            'terms' => ['nullable', 'string', 'max:1000'],
            'negative_terms' => ['nullable', 'string', 'max:1000'],
            'video_source' => ['nullable', 'string', 'in:pexels,pixabay,local'],
            'video_aspect' => ['nullable', 'string', 'in:9:16,16:9,1:1'],
            'video_count' => ['nullable', 'integer', 'min:1', 'max:5'],
            'cover_image' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = $this->admin($request);
        $this->assertCanOperateVideos($admin);
        $site = $this->site($request);

        try {
            $video = $service->create($admin, $site, $payload);
        } catch (RuntimeException $exception) {
            throw new ApiException('video_generation_failed', $this->publicBusinessMessage($exception->getMessage()), 422);
        } catch (Throwable $exception) {
            report($exception);
            throw new ApiException('video_generation_failed', '视频生成任务创建失败，请稍后重试', 422);
        }

        return $this->success($request, [
            'video' => $this->videoPayload($video),
            'billing' => [
                'resource_key' => PlatformPlan::RESOURCE_VIDEO_GENERATIONS,
                'amount' => (int) $video->video_count,
                'quota_consumed' => ! $admin->isSuperAdmin(),
            ],
        ], 201, 'POST /mcp/videos');
    }

    /**
     * 获取视频生成任务详情
     * 查询单个视频生成任务的状态、进度、生成结果和封面信息。
     *
     * @Url [GET] /api/v1/mcp/videos/{video}
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      video int 必选 视频生成任务编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Return JsonResponse 视频生成任务详情
     *
     * @Throws ApiException 视频任务不存在或不属于当前账号和站点
     */
    public function show(Request $request, int $video): JsonResponse
    {
        return $this->success($request, [
            'video' => $this->videoPayload($this->findVideo($request, $video)),
        ]);
    }

    /**
     * @Name: admin
     *
     * @Description: 从 API Token 中间件建立的管理员 Guard 读取并复核 MCP Key 所属账号，防止跨请求上下文污染。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: Admin MCP Key 所属有效管理员
     *
     * @Throws: ApiException 管理员上下文不存在或与 Token 不一致
     */
    private function admin(Request $request): Admin
    {
        $context = $this->auth($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin || (int) $admin->id !== (int) $context->auditAdminId) {
            throw new ApiException('admin_not_available', 'Token 所属账号不存在或已停用', 403);
        }

        return $admin;
    }

    /**
     * @Name: site
     *
     * @Description: 从 API Token 鉴权上下文读取绑定站点，并复核站点仍处于可用状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: Site MCP Key 绑定站点
     *
     * @Throws: ApiException MCP Key 未绑定有效站点
     */
    private function site(Request $request): Site
    {
        $siteId = (int) ($this->auth($request)->siteId ?? 0);
        if ($siteId <= 0) {
            throw new ApiException('mcp_site_required', 'MCP Key 必须绑定有效站点', 403);
        }

        $site = Site::query()->whereKey($siteId)->where('status', 'active')->first();
        if (! $site instanceof Site) {
            throw new ApiException('site_not_available', 'Token 绑定的站点不存在或已停用', 403);
        }

        return $site;
    }

    /**
     * @Name: assertCanOperateVideos
     *
     * @Description: 写入类视频能力仅允许用户侧账号执行，代理管理员不能通过 MCP 绕过后台操作限制。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Admin $admin 当前 MCP Key 所属管理员
     *
     * @Return: void
     *
     * @Throws: ApiException 当前账号不允许执行视频写操作
     */
    private function assertCanOperateVideos(Admin $admin): void
    {
        if ($admin->isAgentAdmin()) {
            throw new ApiException('video_operation_forbidden', '代理管理员不能执行用户侧视频操作', 403);
        }
    }

    /**
     * @Name: videoQuery
     *
     * @Description: 构造按 MCP Key 所属账号和绑定站点隔离的视频生成任务查询，所有读写入口都必须复用该边界。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Return: \Illuminate\Database\Eloquent\Builder<VideoGenerationJob> 已隔离的视频查询构造器
     *
     * @Throws: ApiException MCP Key 账号或绑定站点上下文无效
     */
    private function videoQuery(Request $request)
    {
        return VideoGenerationJob::query()
            ->where('site_id', (int) $this->site($request)->id)
            ->where('owner_admin_id', (int) $this->admin($request)->id);
    }

    /**
     * @Name: findVideo
     *
     * @Description: 在当前 MCP Key 隔离范围内查询视频生成任务，不存在或越权时统一返回不存在。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: Request $request 当前 API 请求
     *
     * @Param: int $videoId 视频生成任务编号
     *
     * @Return: VideoGenerationJob 当前账号可访问的视频生成任务
     *
     * @Throws: ApiException 视频任务不存在或不属于当前账号和站点
     */
    private function findVideo(Request $request, int $videoId): VideoGenerationJob
    {
        $video = $this->videoQuery($request)->whereKey($videoId)->first();
        if (! $video instanceof VideoGenerationJob) {
            throw new ApiException('video_not_found', '视频生成任务不存在', 404);
        }

        return $video;
    }

    /**
     * @Name: videoPayload
     *
     * @Description: 序列化视频生成任务的公开字段，隐藏上游任务编号、请求载荷和内部响应，避免向 MCP 客户端泄露内部实现细节。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: VideoGenerationJob $video 视频生成任务模型
     *
     * @Return: array<string, mixed> MCP 可返回的视频任务数据
     */
    private function videoPayload(VideoGenerationJob $video): array
    {
        return [
            'id' => (int) $video->id,
            'site_id' => (int) $video->site_id,
            'title' => (string) $video->title,
            'subject' => (string) $video->subject,
            'script' => (string) $video->script,
            'video_source' => (string) $video->video_source,
            'video_aspect' => (string) $video->video_aspect,
            'video_count' => (int) $video->video_count,
            'cover_image' => (string) $video->cover_image,
            'status' => (string) $video->status,
            'progress' => (int) $video->progress,
            'first_video_url' => $video->firstVideoUrl(),
            'videos' => $this->stringList((array) $video->videos),
            'combined_videos' => $this->stringList((array) $video->combined_videos),
            'failure_reason' => $this->publicBusinessMessage((string) $video->failure_reason),
            'started_at' => $video->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $video->finished_at?->format('Y-m-d H:i:s'),
            'created_at' => $video->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $video->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @Name: stringList
     *
     * @Description: 将上游返回的视频地址列表规范化为非空字符串数组，过滤非法或空值，保持响应结构稳定。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: array<int|string, mixed> $items 原始视频地址列表
     *
     * @Return: array<int, string> 规范化后的视频地址列表
     */
    private function stringList(array $items): array
    {
        return collect($items)
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * @Name: publicBusinessMessage
     *
     * @Description: 输出可给用户和 Agent 阅读的业务错误信息，过滤空字符串并避免暴露内部异常上下文。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-08-05 18:12:22
     *
     * @UpdateTime: 2026-08-05 18:12:22
     *
     * @Param: string $message 原始业务错误信息
     *
     * @Return: string 可公开展示的错误信息
     */
    private function publicBusinessMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }

        $allowedMessages = [
            '视频生成服务未开启',
            '当前账号规格额度不足，请联系平台升级或续费',
            '当前账号规格不包含该功能',
            '当前规格已到期，请联系平台续费',
        ];

        return in_array($message, $allowedMessages, true) ? $message : '视频服务暂时不可用，请稍后重试';
    }
}

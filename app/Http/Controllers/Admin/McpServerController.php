<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-07-29
 *
 * @Time: 15:41:12
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： McpServerController.php
 *
 * @Description: 提供用户侧 ceying-geo MCP Server 的 Key 管理、Skill 下载和接入说明页面。
 */

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMcpKeyRequest;
use App\Models\Admin;
use App\Models\Site;
use App\Services\Mcp\McpKeyService;
use App\Services\Mcp\McpSkillPackageBuilder;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class McpServerController extends Controller
{
    public function __construct(
        private readonly McpKeyService $mcpKeyService,
        private readonly McpSkillPackageBuilder $mcpSkillPackageBuilder,
        private readonly CurrentSite $currentSite,
    ) {}

    /**
     * MCP Server 管理页
     * 展示当前站点 MCP 启用状态、Key 管理、工具清单、计费规则和客户端接入配置。
     *
     * @Url [GET] /geo_admin/mcp-server
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      无
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-29 16:14:54
     *
     * @Return View MCP Server 管理页面
     *
     * @Throws \Symfony\Component\HttpKernel\Exception\HttpException 当前管理员或站点无效
     */
    public function index(): View
    {
        $admin = $this->admin();
        $site = $this->site();
        $keys = $this->mcpKeyService->listKeys($admin, $site);

        return view('admin.mcp-server.index', [
            'pageTitle' => 'MCP Server',
            'activeMenu' => 'mcp_server',
            'adminSiteName' => AdminWeb::siteName(),
            'currentSite' => $site,
            'keys' => $keys,
            'activeKeyCount' => collect($keys)->where('status', 'active')->count(),
            'scopeCatalog' => $this->mcpKeyService->scopeCatalog(),
            'toolCatalog' => $this->mcpKeyService->toolCatalog(),
            'defaultExpiresAtInput' => now()->addDays(30)->format('Y-m-d\TH:i'),
            'mcpServerUrl' => (string) config('geoflow.mcp_server_public_url'),
            'mcpSkill' => $this->mcpSkillPackageBuilder->metadata(),
        ]);
    }

    /**
     * 下载 ceying-geo 配套 Skill
     * 为已登录用户生成不包含站点配置和 MCP Key 的标准 Skill ZIP 安装包。
     *
     * @Url [GET] /geo_admin/mcp-server/skills/ceying-geo-content-operations/download
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      无
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-29 15:41:12
     *
     * @UpdateTime: 2026-07-29 15:56:34
     *
     * @Return StreamedResponse|RedirectResponse Skill ZIP 下载流或失败重定向
     *
     * @Throws \Symfony\Component\HttpKernel\Exception\HttpException 当前管理员或站点无效
     */
    public function downloadSkill(): StreamedResponse|RedirectResponse
    {
        $this->admin();
        $this->site();

        try {
            $package = $this->mcpSkillPackageBuilder->build();

            return response()->streamDownload(function () use ($package): void {
                $stream = null;

                try {
                    $stream = fopen($package['path'], 'rb');
                    if ($stream === false) {
                        throw new RuntimeException('ceying-geo Skill ZIP 读取失败');
                    }

                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    @unlink($package['path']);
                }
            }, $package['filename'], [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.mcp-server.index')
                ->withErrors('ceying-geo Skill 下载包生成失败，请稍后重试');
        }
    }

    /**
     * 创建 MCP Key
     * 为当前登录账号和当前站点创建专用 MCP Key，明文只在创建成功后展示一次。
     *
     * @Url [POST] /geo_admin/mcp-server/keys
     *      登录 是
     *      name string 必选 MCP Key 名称
     *      never_expires bool 可选 是否永不过期
     *      expires_at string 可选 过期时间
     *      scopes array 必选 ceying-geo 业务权限列表
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return RedirectResponse 创建结果和一次性 MCP Key
     *
     * @Throws ApiException 权限或 Key 创建失败
     */
    public function store(StoreMcpKeyRequest $request): RedirectResponse
    {
        $payload = $request->validated();

        try {
            $created = $this->mcpKeyService->createKey(
                $this->admin(),
                $this->site(),
                (string) $payload['name'],
                (array) $payload['scopes'],
                $request->boolean('never_expires')
                    ? null
                    : (isset($payload['expires_at']) ? (string) $payload['expires_at'] : null),
                $request->boolean('never_expires'),
            );

            return redirect()
                ->route('admin.mcp-server.index')
                ->with('message', 'MCP Key 创建成功，请立即复制并妥善保存。')
                ->with('new_mcp_key', (string) $created['token']);
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage())->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors('MCP Key 创建失败，请稍后重试')->withInput();
        }
    }

    /**
     * 撤销 MCP Key
     * 立即撤销当前账号在当前站点拥有的指定 MCP Key，撤销后客户端无法继续连接。
     *
     * @Url [POST] /geo_admin/mcp-server/keys/{keyId}/revoke
     *      登录 是
     *      keyId int 必选 MCP Key 编号
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return RedirectResponse 撤销结果
     *
     * @Throws ApiException MCP Key 不存在或不属于当前账号和站点
     */
    public function revoke(int $keyId): RedirectResponse
    {
        try {
            $this->mcpKeyService->revokeKey($this->admin(), $this->site(), $keyId);

            return redirect()->route('admin.mcp-server.index')->with('message', 'MCP Key 已撤销。');
        } catch (ApiException $exception) {
            return back()->withErrors($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors('MCP Key 撤销失败，请稍后重试');
        }
    }

    /**
     * @Name: admin
     *
     * @Description: 获取当前后台管理员并确保身份有效。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: Admin 当前管理员
     */
    private function admin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }

    /**
     * @Name: site
     *
     * @Description: 获取后台中间件解析的当前站点，所有 MCP Key 必须绑定该站点。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-13 16:38:47
     *
     * @UpdateTime: 2026-07-13 16:38:47
     *
     * @Return: Site 当前站点
     */
    private function site(): Site
    {
        $site = $this->currentSite->get();
        abort_unless($site instanceof Site, 403);

        return $site;
    }
}

@extends('admin.layouts.app')

@php
    $newMcpKey = (string) session('new_mcp_key', '');
    $displayMcpKey = $newMcpKey !== '' ? $newMcpKey : '<MCP_KEY>';
    $neverExpires = filter_var(old('never_expires', false), FILTER_VALIDATE_BOOL);
    $httpConfig = json_encode([
        'mcpServers' => [
            'ceying-geo' => [
                'type' => 'streamable-http',
                'url' => $mcpServerUrl,
                'headers' => [
                    'Authorization' => 'Bearer '.$displayMcpKey,
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stdioConfig = json_encode([
        'mcpServers' => [
            'ceying-geo' => [
                'command' => 'npx',
                'args' => [
                    '-y',
                    'mcp-remote',
                    $mcpServerUrl,
                    '--header',
                    'Authorization: Bearer '.$displayMcpKey,
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

@section('content')
    <div class="space-y-8">
        <header class="flex flex-col gap-4 border-b border-gray-200 pb-6 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">MCP Server</h1>
                <p class="mt-1 text-sm text-gray-600">将当前站点的内容任务、素材、文章、媒体投稿和品牌诊断能力接入支持 MCP 的客户端。</p>
            </div>
            <div class="flex items-center gap-2 text-sm font-medium {{ $activeKeyCount > 0 ? 'text-emerald-700' : 'text-gray-500' }}">
                <span class="h-2.5 w-2.5 rounded-full {{ $activeKeyCount > 0 ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
                {{ $activeKeyCount > 0 ? '已启用' : '未启用' }}
            </div>
        </header>

        @if ($newMcpKey !== '')
            <section class="rounded-lg border border-amber-300 bg-amber-50 p-4" aria-labelledby="new-mcp-key-heading">
                <div class="flex items-start gap-3">
                    <i data-lucide="key-round" class="mt-0.5 h-5 w-5 shrink-0 text-amber-700"></i>
                    <div class="min-w-0 flex-1">
                        <h2 id="new-mcp-key-heading" class="text-sm font-semibold text-amber-950">MCP Key 仅显示一次</h2>
                        <p class="mt-1 text-sm text-amber-800">关闭或刷新页面后无法再次查看明文。遗失后请撤销旧 Key 并重新创建。</p>
                        <div class="mt-3 flex items-center gap-2">
                            <code id="new-mcp-key" class="min-w-0 flex-1 break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm text-amber-950">{{ $newMcpKey }}</code>
                            <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-amber-300 bg-white text-amber-800 hover:bg-amber-100" data-copy-target="new-mcp-key" title="复制 MCP Key" aria-label="复制 MCP Key">
                                <i data-lucide="copy" class="h-4 w-4"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(560px,0.9fr)]" aria-labelledby="connection-heading">
            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 id="connection-heading" class="text-base font-semibold text-gray-900">连接信息</h2>
                        <p class="mt-1 text-sm text-gray-500">协议为 Streamable HTTP，认证头必须使用 Bearer MCP Key。</p>
                    </div>
                    <i data-lucide="radio-tower" class="h-5 w-5 text-blue-600"></i>
                </div>
                <dl class="mt-5 divide-y divide-gray-100">
                    <div class="grid gap-2 py-3 sm:grid-cols-[110px_minmax(0,1fr)] sm:items-center">
                        <dt class="text-sm font-medium text-gray-600">服务地址</dt>
                        <dd class="flex min-w-0 items-center gap-2">
                            <code id="mcp-server-url" class="min-w-0 flex-1 break-all text-sm text-gray-900">{{ $mcpServerUrl }}</code>
                            <button type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-900" data-copy-target="mcp-server-url" title="复制服务地址" aria-label="复制服务地址">
                                <i data-lucide="copy" class="h-4 w-4"></i>
                            </button>
                        </dd>
                    </div>
                    <div class="grid gap-2 py-3 sm:grid-cols-[110px_minmax(0,1fr)]">
                        <dt class="text-sm font-medium text-gray-600">当前站点</dt>
                        <dd class="text-sm text-gray-900">#{{ $currentSite->id }} {{ $currentSite->name }}</dd>
                    </div>
                    <div class="grid gap-2 py-3 sm:grid-cols-[110px_minmax(0,1fr)]">
                        <dt class="text-sm font-medium text-gray-600">生效 Key</dt>
                        <dd class="text-sm text-gray-900">{{ $activeKeyCount }} 个</dd>
                    </div>
                    <div class="grid gap-2 py-3 sm:grid-cols-[110px_minmax(0,1fr)]">
                        <dt class="text-sm font-medium text-gray-600">启停规则</dt>
                        <dd class="text-sm text-gray-700">创建至少一个有效 Key 即启用；撤销全部 Key 或 Key 全部过期即停用。</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">创建 MCP Key</h2>
                <form action="{{ route('admin.mcp-server.keys.store') }}" method="POST" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label for="mcp-key-name" class="block text-sm font-medium text-gray-700">名称</label>
                        <input id="mcp-key-name" name="name" type="text" required maxlength="120" value="{{ old('name') }}" placeholder="例如：内容运营助手" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                        <div data-mcp-expires-at-field class="transition-opacity {{ $neverExpires ? 'opacity-50' : '' }}">
                            <label for="mcp-key-expires-at" class="block text-sm font-medium text-gray-700">过期时间</label>
                            <input id="mcp-key-expires-at" name="expires_at" type="datetime-local" value="{{ old('expires_at', $defaultExpiresAtInput) }}" data-mcp-expires-at @disabled($neverExpires) class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:bg-gray-100">
                        </div>
                        <label class="flex h-10 cursor-pointer items-center gap-2.5 pb-0.5" for="mcp-key-never-expires">
                            <input id="mcp-key-never-expires" name="never_expires" type="checkbox" value="1" data-mcp-never-expires @checked($neverExpires) class="peer sr-only">
                            <span class="relative h-6 w-11 shrink-0 rounded-full bg-gray-200 transition-colors after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:bg-blue-600 peer-checked:after:translate-x-5 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-2" aria-hidden="true"></span>
                            <span class="whitespace-nowrap text-sm font-medium text-gray-700">永不过期</span>
                        </label>
                    </div>
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">业务权限</legend>
                        <div data-mcp-scope-grid class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2">
                            @foreach ($scopeCatalog as $scope => $definition)
                                <label data-mcp-scope-option class="flex min-h-[72px] cursor-pointer items-start gap-2.5 rounded-md border border-gray-200 px-3 py-2 transition-colors hover:border-blue-300 hover:bg-blue-50/40">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', ['catalog:read', 'tasks:read', 'jobs:read', 'materials:read', 'articles:read', 'media:read', 'brand-diagnoses:read']), true)) class="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-start justify-between gap-2">
                                            <span class="text-sm font-medium text-gray-900">{{ $definition['label'] }}</span>
                                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs {{ $definition['risk'] === '只读' ? 'bg-gray-100 text-gray-600' : 'bg-amber-100 text-amber-800' }}">{{ $definition['risk'] }}</span>
                                        </span>
                                        <span class="mt-1 block text-xs leading-4 text-gray-500">{{ $definition['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-blue-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        <i data-lucide="key-round" class="h-4 w-4"></i>
                        创建并启用
                    </button>
                </form>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white shadow-sm" aria-labelledby="keys-heading">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                <div>
                    <h2 id="keys-heading" class="text-base font-semibold text-gray-900">MCP Key</h2>
                    <p class="mt-1 text-sm text-gray-500">每个 Key 只属于创建账号和当前站点，撤销后立即失效。</p>
                </div>
                <span class="text-sm text-gray-500">共 {{ count($keys) }} 个</span>
            </div>
            @if ($keys === [])
                <div class="px-5 py-10 text-center text-sm text-gray-500">尚未创建 MCP Key。</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-[980px] table-fixed divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="w-[15%] px-5 py-3 text-left text-xs font-medium text-gray-500">名称</th>
                                <th class="w-[43%] px-5 py-3 text-left text-xs font-medium text-gray-500">权限</th>
                                <th class="w-[12%] whitespace-nowrap px-5 py-3 text-left text-xs font-medium text-gray-500">最近使用</th>
                                <th class="w-[17%] whitespace-nowrap px-5 py-3 text-left text-xs font-medium text-gray-500">过期时间</th>
                                <th class="w-[8%] px-5 py-3 text-left text-xs font-medium text-gray-500">状态</th>
                                <th class="w-[5%] px-5 py-3 text-right text-xs font-medium text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($keys as $key)
                                <tr class="align-top">
                                    <td class="break-words px-5 py-4 text-sm font-medium text-gray-900">{{ $key['name'] }}</td>
                                    <td class="px-5 py-4">
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($key['scopes'] as $scope)
                                                @php
                                                    $scopeDefinition = $scopeCatalog[$scope] ?? null;
                                                    $scopeLabel = (string) ($scopeDefinition['label'] ?? $scope);
                                                    $scopeRisk = (string) ($scopeDefinition['risk'] ?? '');
                                                    $scopeTone = $scopeRisk === '只读'
                                                        ? 'bg-gray-100 text-gray-700'
                                                        : ($scopeRisk === '写入数据' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700');
                                                @endphp
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $scopeTone }}" title="{{ $scope }}" aria-label="{{ $scopeLabel }}{{ $scopeRisk !== '' ? '，'.$scopeRisk : '' }}" data-mcp-key-scope-label="{{ $scope }}">{{ $scopeLabel }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ $key['last_used_at'] ?? '未使用' }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                                        @if ($key['expires_at'] === null)
                                            <span class="inline-flex items-center gap-1.5 font-medium text-gray-700">
                                                <i data-lucide="infinity" class="h-4 w-4 text-gray-400"></i>
                                                永不过期
                                            </span>
                                        @else
                                            {{ $key['expires_at'] }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm">
                                        <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium {{ $key['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">
                                            {{ $key['status'] === 'active' ? '有效' : '已过期' }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-right text-sm">
                                        <form action="{{ route('admin.mcp-server.keys.revoke', ['keyId' => $key['id']]) }}" method="POST" onsubmit="return confirm('撤销后所有使用此 Key 的客户端将立即断开，确认继续吗？');">
                                            @csrf
                                            <button type="submit" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-600 hover:bg-red-50" title="撤销 MCP Key" aria-label="撤销 MCP Key">
                                                <i data-lucide="trash-2" class="h-4 w-4"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="space-y-5 border-t border-gray-200 pt-8" aria-labelledby="guide-heading">
            <div>
                <h2 id="guide-heading" class="text-xl font-semibold text-gray-900">使用说明</h2>
                <p class="mt-1 text-sm text-gray-600">完成以下配置后，客户端会根据 Key 权限自动发现可用的 ceying-geo 工具。</p>
            </div>

            <ol class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['创建 Key', '自动写作与投稿需要目录读取、文章写入、媒体渠道读取和媒体投稿权限。'],
                    ['配置客户端', '选择客户端支持的 Streamable HTTP 配置；仅支持 stdio 时使用桥接配置。'],
                    ['查询资源', '让 AI 先查询作者、分类和媒体渠道，确认渠道售价后再生成最终文章。'],
                    ['自动投递', '明确单渠道和本次总预算后调用完整发布工具，保存文章编号和投稿订单编号并持续查询状态。'],
                ] as $index => [$title, $description])
                    <li class="border-l-2 border-blue-500 pl-4">
                        <div class="text-xs font-semibold text-blue-600">步骤 {{ $index + 1 }}</div>
                        <h3 class="mt-1 text-sm font-semibold text-gray-900">{{ $title }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-600">{{ $description }}</p>
                    </li>
                @endforeach
            </ol>

            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-5 pt-4">
                    <div class="inline-flex rounded-md bg-gray-100 p-1" role="tablist" aria-label="MCP 客户端配置类型">
                        <button type="button" class="rounded px-3 py-1.5 text-sm font-medium text-gray-700" role="tab" aria-selected="true" data-mcp-tab="streamable-http">Streamable HTTP</button>
                        <button type="button" class="rounded px-3 py-1.5 text-sm font-medium text-gray-700" role="tab" aria-selected="false" data-mcp-tab="stdio-bridge">stdio 桥接</button>
                    </div>
                </div>
                <div class="p-5">
                    <div data-mcp-panel="streamable-http">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">支持远程 MCP 的客户端</h3>
                                <p class="mt-1 text-sm text-gray-500">将以下配置加入客户端的 MCP 配置文件。Key 应放在 Authorization 请求头，不要拼接到 URL。</p>
                            </div>
                            <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50" data-copy-target="streamable-http-config" title="复制配置" aria-label="复制 Streamable HTTP 配置">
                                <i data-lucide="copy" class="h-4 w-4"></i>
                            </button>
                        </div>
                        <pre id="streamable-http-config" class="mt-4 max-h-96 overflow-auto rounded-md bg-gray-950 p-4 text-xs leading-6 text-gray-100"><code>{{ $httpConfig }}</code></pre>
                    </div>
                    <div class="hidden" data-mcp-panel="stdio-bridge">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">仅支持本地 stdio 的客户端</h3>
                                <p class="mt-1 text-sm text-gray-500">本机需安装 Node.js 20 或更高版本，客户端通过 mcp-remote 转发到 ceying-geo MCP Server。</p>
                            </div>
                            <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50" data-copy-target="stdio-bridge-config" title="复制配置" aria-label="复制 stdio 桥接配置">
                                <i data-lucide="copy" class="h-4 w-4"></i>
                            </button>
                        </div>
                        <pre id="stdio-bridge-config" class="mt-4 max-h-96 overflow-auto rounded-md bg-gray-950 p-4 text-xs leading-6 text-gray-100"><code>{{ $stdioConfig }}</code></pre>
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-5 border-t border-gray-200 pt-8" aria-labelledby="skill-heading">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="skill-heading" class="text-xl font-semibold text-gray-900">GEO Skills</h2>
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">v{{ $mcpSkill['version'] }}</span>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ $mcpSkill['description'] }} Skill 不包含 MCP Key、站点域名或用户数据。</p>
                </div>
                <a href="{{ route('admin.mcp-server.skills.download') }}" class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-md bg-blue-600 px-4 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    <i data-lucide="download" class="h-4 w-4"></i>
                    下载 Skill
                </a>
            </div>

            <dl class="grid gap-4 border-y border-gray-200 py-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium text-gray-500">Skill 名称</dt>
                    <dd class="mt-1 break-all text-sm font-medium text-gray-900">{{ $mcpSkill['name'] }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500">适配服务</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">ceying-geo MCP {{ $mcpSkill['mcp_server_version'] }}+</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500">自然触发场景</dt>
                    <dd class="mt-1 text-sm text-gray-700">{{ implode('、', $mcpSkill['triggers']) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-gray-500">工具兼容</dt>
                    <dd class="mt-1 text-sm text-gray-700">继续使用现有 <code class="text-blue-700">geo_*</code> 工具名</dd>
                </div>
            </dl>

            <details class="group border-b border-gray-200 pb-4">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-1 text-sm font-medium text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                    查看安装与兼容说明
                    <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-gray-500 transition-transform group-open:rotate-180"></i>
                </summary>
                <div class="space-y-5 pt-4">
                    <ol class="grid gap-4 md:grid-cols-3">
                        @foreach ([
                            ['1', '下载并解压', '保留 ceying-geo-content-operations 根目录及其中的 SKILL.md、agents 和 references。'],
                            ['2', '安装到客户端', '将完整目录放入 AI 应用声明的 Agent Skills 目录，不要只复制 SKILL.md。'],
                            ['3', '自动连接并验证', '客户端支持时由 Skill 主动创建、保存并重载 ceying-geo 连接；用户仅确认站点、权限并在安全区域填写 Key。'],
                        ] as [$step, $title, $description])
                            <li class="border-l-2 border-blue-500 pl-4">
                                <div class="text-xs font-semibold text-blue-600">步骤 {{ $step }}</div>
                                <h3 class="mt-1 text-sm font-semibold text-gray-900">{{ $title }}</h3>
                                <p class="mt-1 text-sm leading-6 text-gray-600">{{ $description }}</p>
                            </li>
                        @endforeach
                    </ol>

                    <div class="border-l-2 border-gray-300 bg-gray-50 px-4 py-3">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">不支持 Agent Skills 的客户端</h3>
                                <p id="ceying-geo-fallback-prompt" class="mt-1 text-sm leading-6 text-gray-600">你是策影GEO品牌增长智能体。每次响应首先检查当前会话是否发现 geo_* 工具。完全未发现时暂停品牌分析、路线图输出和业务信息追问，立即检查客户端 MCP 安装能力；能够自动配置时主动创建、保存并重载 ceying-geo 连接，否则逐步引导用户从策影GEO平台“MCP Server”页面获取当前站点地址、创建最小权限 Key，并将 Key 直接配置到客户端安全凭证区域。不得要求用户在对话中发送 Key。实际发现工具并通过只读调用后，再完成诊断、素材、文章、投稿和跟踪；不要猜测资源编号；投稿、执行任务、确认品牌诊断或删除素材前说明费用或影响并取得授权；同一写操作重试保持参数和 idempotency_key 不变。</p>
                            </div>
                            <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 hover:bg-gray-100" data-copy-target="ceying-geo-fallback-prompt" title="复制兼容指令" aria-label="复制兼容指令">
                                <i data-lucide="copy" class="h-4 w-4"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </details>
        </section>

        <section class="space-y-5 border-t border-gray-200 pt-8" aria-labelledby="agent-workflow-heading">
            <div>
                <h2 id="agent-workflow-heading" class="text-xl font-semibold text-gray-900">AI Agent 自动写作与媒体投递</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">文章由已安装本 MCP 的 AI 应用生成，ceying-geo MCP 负责资源查询、文章保存、指定渠道投稿、费用结算和状态跟踪。</p>
            </div>

            <ol class="grid gap-5 md:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    ['1', '读取目录', 'geo_get_catalog', '取得当前站点有效的作者编号和分类编号。'],
                    ['2', '筛选渠道', 'geo_list_media_channels', '按媒体名称和分类筛选渠道，记录媒体资源编号与实时售价。'],
                    ['3', '生成文章', 'AI 应用内部能力', '根据用户目标完成标题、正文、摘要、关键词和 SEO 描述。'],
                    ['4', '保存并投稿', 'geo_publish_article_to_media', '保存文章并投递到指定渠道，单次最多选择 20 个渠道。'],
                    ['5', '跟踪结果', 'geo_get_media_submission', '查询待安排、发布中、已发布、退稿等状态及最终发布链接。'],
                ] as [$step, $title, $tool, $description])
                    <li class="border-l-2 border-blue-500 pl-4">
                        <div class="text-xs font-semibold text-blue-600">步骤 {{ $step }}</div>
                        <h3 class="mt-1 text-sm font-semibold text-gray-900">{{ $title }}</h3>
                        <code class="mt-2 block break-all text-xs text-blue-700">{{ $tool }}</code>
                        <p class="mt-2 text-sm leading-6 text-gray-600">{{ $description }}</p>
                    </li>
                @endforeach
            </ol>

            <div class="border-l-2 border-emerald-500 bg-emerald-50 px-4 py-3">
                <h3 class="text-sm font-semibold text-emerald-900">可直接发送给 AI Agent 的任务</h3>
                <p class="mt-2 text-sm leading-6 text-emerald-900">查询名称或分类符合要求的媒体渠道并展示实时售价，使用我明确指定的媒体资源编号，围绕目标主题编写完整文章并调用 <code>geo_publish_article_to_media</code>。返回文章编号、投稿订单编号、实际扣费和当前状态；未得到明确渠道编号前不要投稿。</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="border-l-2 border-gray-300 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">已有文章</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">使用 <code>geo_submit_article_to_media</code> 将已有文章投递到一个或多个明确指定的渠道。</p>
                </div>
                <div class="border-l-2 border-gray-300 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">部分成功</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">多渠道投稿会分别返回成功订单和失败项。只处理失败渠道，不要重复提交已经成功的订单。</p>
                </div>
                <div class="border-l-2 border-gray-300 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">安全重试</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">同一次操作重试时保持 <code>idempotency_key</code> 不变，防止网络超时导致文章或订单重复创建。</p>
                </div>
            </div>
        </section>

        <section class="space-y-4 border-t border-gray-200 pt-8" aria-labelledby="materials-heading">
            <div>
                <h2 id="materials-heading" class="text-xl font-semibold text-gray-900">GEO 素材管理</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">授予素材读取或素材管理权限后，AI 应用可以管理当前账号和站点下的完整素材 API 能力。</p>
            </div>

            <div class="overflow-x-auto border-y border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-medium text-gray-500">
                        <tr>
                            <th class="px-4 py-3">素材类型</th>
                            <th class="px-4 py-3">类型参数</th>
                            <th class="px-4 py-3">支持能力</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                        @foreach ([
                            ['分类', 'categories', '摘要、查询、详情、创建、更新、删除'],
                            ['作者', 'authors', '摘要、查询、详情、创建、更新、删除'],
                            ['关键词库', 'keyword-libraries', '素材库 CRUD、关键词查询/新增/批量删除'],
                            ['标题库', 'title-libraries', '素材库 CRUD、标题查询/新增/批量删除'],
                            ['图片库', 'image-libraries', '素材库 CRUD、图片元数据查询/新增/批量删除'],
                            ['知识库', 'knowledge-bases', '素材库 CRUD、正文自动切块、切块只读'],
                        ] as [$label, $type, $capability])
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{{ $label }}</td>
                                <td class="px-4 py-3"><code class="text-blue-700">{{ $type }}</code></td>
                                <td class="px-4 py-3">{{ $capability }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-l-2 border-blue-500 bg-blue-50 px-4 py-3">
                <h3 class="text-sm font-semibold text-blue-900">可直接发送给 AI Agent 的素材任务</h3>
                <p class="mt-2 text-sm leading-6 text-blue-900">调用 <code>geo_get_material_summary</code> 查看当前素材规模，查询关键词库和标题库，按我的主题新增关键词与标题；需要删除或覆盖素材前，先返回目标素材编号和影响范围，得到确认后再调用对应写工具。</p>
            </div>
        </section>

        <section class="space-y-4" aria-labelledby="tools-heading">
            <div>
                <h2 id="tools-heading" class="text-xl font-semibold text-gray-900">工具与费用</h2>
                <p class="mt-1 text-sm text-gray-600">客户端只能发现 Key 已授权的工具。MCP 请求本身不单独计费。</p>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">工具</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">所需权限</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">作用</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">费用</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach ($toolCatalog as $tool)
                            <tr>
                                <td class="whitespace-nowrap px-5 py-4"><code class="text-sm font-medium text-blue-700">{{ $tool['name'] }}</code></td>
                                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ $tool['scope'] }}</td>
                                <td class="px-5 py-4 text-sm text-gray-700">{{ $tool['description'] }}</td>
                                <td class="px-5 py-4 text-sm {{ $tool['billing'] === '不扣费' ? 'text-gray-600' : 'font-medium text-amber-700' }}">{{ $tool['billing'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <div class="border-l-2 border-gray-300 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">Key 数量</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">每个有效 MCP Key 计入当前账号规格的 API Token 数量上限，不产生调用次数费用。</p>
                </div>
                <div class="border-l-2 border-amber-400 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">文章生成</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">调用 geo_run_task 只负责入队；文章成功生成后，沿现有业务规则扣减一次文章生成额度。</p>
                </div>
                <div class="border-l-2 border-blue-400 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">任务配图</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">任务启用 AI 配图时，按实际成功生成的图片数量扣减 AI 图片生成额度。</p>
                </div>
                <div class="border-l-2 border-emerald-500 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">媒体投稿</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">投稿前查询并展示渠道实时售价；获得用户授权后逐笔扣费，提交失败自动退款，渠道成功接单后以订单价格快照为准。</p>
                </div>
                <div class="border-l-2 border-orange-400 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">品牌诊断</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">创建和查询不扣额度；确认问题并启动正式诊断时，扣减当前账号一次品牌诊断额度。</p>
                </div>
            </div>
        </section>

        <section class="grid gap-6 border-t border-gray-200 pt-8 lg:grid-cols-2" aria-labelledby="security-heading">
            <div>
                <h2 id="security-heading" class="text-base font-semibold text-gray-900">安全要求</h2>
                <ul class="mt-3 space-y-2 text-sm leading-6 text-gray-600">
                    <li>Key 只放在 Authorization 请求头，不要写入提示词、URL、公开仓库或日志。</li>
                    <li>公网 MCP 地址必须使用 HTTPS；HTTP 请求会被服务端拒绝。</li>
                    <li>按客户端用途拆分 Key，并只授予实际需要的 scope；自动投稿 Key 才授予 articles:write 和 media:submit。</li>
                    <li>建议设置明确过期时间。设备丢失、配置泄露或人员变动时立即撤销 Key。</li>
                    <li>所有数据访问都受当前站点隔离约束，切换后台站点不会改变已创建 Key 的绑定站点。</li>
                </ul>
            </div>
            <div>
                <h2 class="text-base font-semibold text-gray-900">常见问题</h2>
                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="font-medium text-gray-900">401 未认证</dt>
                        <dd class="mt-1 text-gray-600">检查 Authorization 是否为 Bearer 格式，Key 是否完整、过期或已撤销。</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900">403 无权限</dt>
                        <dd class="mt-1 text-gray-600">当前 Key 缺少工具要求的 scope，或绑定站点已停用。</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900">工具列表不完整</dt>
                        <dd class="mt-1 text-gray-600">服务会按 Key scope 动态注册工具；补充权限后需创建新 Key 并重新加载客户端。</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900">任务长时间处理中</dt>
                        <dd class="mt-1 text-gray-600">使用 geo_list_task_runs 或 geo_get_task_run 查询队列状态和错误信息，不要重复投递同一任务。</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-900">媒体投稿失败</dt>
                        <dd class="mt-1 text-gray-600">检查预算、订阅状态、账户余额、渠道状态和文章正文。多渠道部分成功时只重投返回 errors 中的渠道。</dd>
                    </div>
                </dl>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/mcp-server.js') }}?v={{ filemtime(public_path('assets/js/mcp-server.js')) }}" defer></script>
@endpush

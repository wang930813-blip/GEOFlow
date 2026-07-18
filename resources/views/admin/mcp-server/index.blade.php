@extends('admin.layouts.app')

@php
    $newMcpKey = (string) session('new_mcp_key', '');
    $displayMcpKey = $newMcpKey !== '' ? $newMcpKey : '<MCP_KEY>';
    $httpConfig = json_encode([
        'mcpServers' => [
            'geoflow' => [
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
            'geoflow' => [
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
                <p class="mt-1 text-sm text-gray-600">将当前站点的 GEO 任务、执行记录和文章能力接入支持 MCP 的客户端。</p>
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

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.72fr)]" aria-labelledby="connection-heading">
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

            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">创建 MCP Key</h2>
                <form action="{{ route('admin.mcp-server.keys.store') }}" method="POST" class="mt-5 space-y-5">
                    @csrf
                    <div>
                        <label for="mcp-key-name" class="block text-sm font-medium text-gray-700">名称</label>
                        <input id="mcp-key-name" name="name" type="text" required maxlength="120" value="{{ old('name') }}" placeholder="例如：内容运营助手" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="mcp-key-expires-at" class="block text-sm font-medium text-gray-700">过期时间</label>
                        <input id="mcp-key-expires-at" name="expires_at" type="datetime-local" value="{{ old('expires_at', $defaultExpiresAtInput) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <fieldset class="space-y-3 border-l-2 border-amber-400 pl-4">
                        <legend class="text-sm font-medium text-gray-700">媒体投稿消费策略</legend>
                        <p class="text-xs leading-5 text-gray-500">授予媒体投稿权限时必须填写，且单渠道上限不得高于单次上限，单次上限不得高于每日上限。</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="block text-sm text-gray-700">
                                单渠道上限
                                <input name="mcp_max_unit_price" type="number" min="0.01" max="999999.99" step="0.01" value="{{ old('mcp_max_unit_price') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </label>
                            <label class="block text-sm text-gray-700">
                                单次总额上限
                                <input name="mcp_max_total_price" type="number" min="0.01" max="9999999.99" step="0.01" value="{{ old('mcp_max_total_price') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </label>
                            <label class="block text-sm text-gray-700">
                                每日上限
                                <input name="mcp_daily_spend_limit" type="number" min="0.01" max="9999999.99" step="0.01" value="{{ old('mcp_daily_spend_limit') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </label>
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">业务权限</legend>
                        <div class="mt-2 space-y-2">
                            @foreach ($scopeCatalog as $scope => $definition)
                                <label class="flex cursor-pointer items-start gap-3 rounded-md border border-gray-200 px-3 py-2.5 hover:border-blue-300 hover:bg-blue-50/40">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', ['catalog:read', 'tasks:read', 'jobs:read', 'articles:read', 'media:read']), true)) class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="min-w-0">
                                        <span class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                            {{ $definition['label'] }}
                                            <span class="rounded-full px-2 py-0.5 text-xs {{ $definition['risk'] === '只读' ? 'bg-gray-100 text-gray-600' : 'bg-amber-100 text-amber-800' }}">{{ $definition['risk'] }}</span>
                                        </span>
                                        <span class="mt-0.5 block text-xs leading-5 text-gray-500">{{ $definition['description'] }}</span>
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
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">名称</th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">权限</th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">消费策略</th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">最近使用</th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">过期时间</th>
                                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500">状态</th>
                                <th class="px-5 py-3 text-right text-xs font-medium text-gray-500">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($keys as $key)
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm font-medium text-gray-900">{{ $key['name'] }}</td>
                                    <td class="px-5 py-4 text-xs text-gray-600">{{ implode(', ', $key['scopes']) }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-xs text-gray-600">
                                        @if ($key['mcp_daily_spend_limit'] !== null)
                                            单渠道 {{ $key['mcp_max_unit_price'] }} / 单次 {{ $key['mcp_max_total_price'] }} / 每日 {{ $key['mcp_daily_spend_limit'] }}
                                        @else
                                            不允许付费投稿
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ $key['last_used_at'] ?? '未使用' }}</td>
                                    <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ $key['expires_at'] ?? '长期有效' }}</td>
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
                <p class="mt-1 text-sm text-gray-600">完成以下配置后，客户端会根据 Key 权限自动发现可用的 GEO 工具。</p>
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
                                <p class="mt-1 text-sm text-gray-500">本机需安装 Node.js 20 或更高版本，客户端通过 mcp-remote 转发到 GEO MCP Server。</p>
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

        <section class="space-y-5 border-t border-gray-200 pt-8" aria-labelledby="agent-workflow-heading">
            <div>
                <h2 id="agent-workflow-heading" class="text-xl font-semibold text-gray-900">AI Agent 自动写作与媒体投递</h2>
                <p class="mt-1 text-sm leading-6 text-gray-600">文章由已安装本 MCP 的 AI 应用生成，GEO MCP 负责资源查询、文章保存、指定渠道投稿、费用结算和状态跟踪。</p>
            </div>

            <ol class="grid gap-5 md:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    ['1', '读取目录', 'geo_get_catalog', '取得当前站点有效的作者编号和分类编号。'],
                    ['2', '筛选渠道', 'geo_list_media_channels', '按媒体名称、分类和预算筛选渠道，记录媒体资源编号与售价。'],
                    ['3', '生成文章', 'AI 应用内部能力', '根据用户目标完成标题、正文、摘要、关键词和 SEO 描述。'],
                    ['4', '确认预算并投稿', 'geo_publish_article_to_media', '提交 max_unit_price 和 max_total_price 后保存文章并投递，最多选择 20 个渠道。'],
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
                <p class="mt-2 text-sm leading-6 text-emerald-900">查询名称或分类符合要求的媒体渠道并汇总实时售价，向我确认最高单渠道价格和本次总预算；确认后将这两个金额分别作为 <code>max_unit_price</code>、<code>max_total_price</code>，围绕目标主题编写完整文章并调用 <code>geo_publish_article_to_media</code>。返回文章编号、投稿订单编号、实际扣费和当前状态；未得到明确渠道编号及预算前不要投稿。</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="border-l-2 border-gray-300 pl-4">
                    <h3 class="text-sm font-semibold text-gray-900">已有文章</h3>
                    <p class="mt-1 text-sm leading-6 text-gray-600">使用 <code>geo_submit_article_to_media</code> 将已有文章投递到新渠道，同样必须提交单渠道和总预算。</p>
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
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
                    <p class="mt-1 text-sm leading-6 text-gray-600">投稿前按实时售价强制校验单渠道和本次总预算；通过后逐笔扣费，提交失败自动退款，渠道成功接单后以订单价格快照为准。</p>
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

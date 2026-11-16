@extends('website-admin.layout')

@section('content')
<div class='wa-page-heading'>
    <div>
        <span class='wa-eyebrow'>系统管理</span>
        <h1>插件列表</h1>
        <p>查看已安装插件的版本、作者和启用状态。</p>
    </div>
</div>

<div class='wa-plugin-list'>
    @forelse ($plugins as $plugin)
        <section class='wa-panel wa-plugin-card' aria-labelledby='wa-plugin-{{ $plugin['id'] }}'>
            <div class='wa-plugin-heading'>
                <div>
                    <h2 id='wa-plugin-{{ $plugin['id'] }}'>{{ $plugin['manifest']['name'] }}</h2>
                    <p>{{ $plugin['manifest']['description'] }}</p>
                </div>
            </div>
            <dl class='wa-plugin-meta'>
                <div><dt>版本号</dt><dd>{{ $plugin['manifest']['version'] }}</dd></div>
                <div><dt>作者</dt><dd>{{ $plugin['manifest']['author'] }}</dd></div>
                <div><dt>安装状态</dt><dd><span class='wa-plugin-install-state'>{{ $plugin['installed'] ? '已安装' : '未安装' }}</span></dd></div>
                <div><dt>唯一标识</dt><dd><code>{{ $plugin['id'] }}</code></dd></div>
                <div><dt>启用状态</dt><dd><span class='wa-plugin-state-label {{ $plugin['enabled'] ? 'is-enabled' : 'is-disabled' }}'>{{ $plugin['enabled'] ? '已启用' : '未启用' }}</span></dd></div>
            </dl>
            @if ($plugin['configureUrl'])
                <div class='wa-plugin-actions'>
                    <a class='wa-button wa-button-primary' href='{{ $plugin['configureUrl'] }}'>配置插件</a>
                </div>
            @endif
        </section>
    @empty
        <div class='wa-panel wa-empty-state'>当前系统没有可用插件。</div>
    @endforelse
</div>
@endsection

<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： header.blade.php
 * @Description: 输出绑定公共站点名称的响应式主导航。
 */
?>
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="{{ \App\Support\Site\SitePageUrl::to('home') }}" aria-label="返回首页">
            @if(!empty(data_get($themeData, 'site.logo.src')))
                <img class="brand-image" src="{{ data_get($themeData, 'site.logo.src') }}" alt="{{ data_get($themeData, 'site.logo.alt', $themeData['site']['brand_name']) }}">
            @else
                <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            @endif
            <span class="brand-name">{{ $themeData['site']['brand_name'] }}</span>
        </a>
        <button class="menu-toggle" type="button" aria-label="打开导航" aria-expanded="false" aria-controls="site-nav" data-menu-toggle>
            <span></span><span></span><span></span>
        </button>
        <nav id="site-nav" class="site-nav" aria-label="主导航" data-site-nav>
            @foreach(data_get($themeData, 'navigation.items', []) as $navigationItem)
                <a href="{{ $navigationItem['url'] }}" data-site-nav-item="{{ $navigationItem['id'] }}" @if($navigationItem['active']) aria-current="page" @endif><span data-site-nav-label>{{ $navigationItem['label'] }}</span></a>
            @endforeach
            @foreach($navPages ?? [] as $pageItem)
                <a href="{{ route('site.page', $pageItem->slug) }}" data-site-nav-item="page-{{ $pageItem->slug }}"><span data-site-nav-label>{{ $pageItem->title }}</span></a>
            @endforeach
        </nav>
    </div>
</header>

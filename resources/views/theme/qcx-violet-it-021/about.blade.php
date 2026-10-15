@extends('theme.qcx-violet-it-021.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $about = $themeData['about'];
    $activeNav = 'about';
    $pageTitle = $themeData['seo']['about']['title'];
    $pageDescription = $themeData['seo']['about']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('about');
@endphp
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">关于我们</p><h1>{{ $about['title'] }}</h1><p class="lead">{{ $about['summary'] }}</p></div></section>
<section class="page-content"><div class="shell split-section"><div class="about-panel"><div class="about-panel-content"><p class="eyebrow">服务原则</p><h2>让每一项技术投入都能被理解和使用</h2><p>正式品牌信息、主体介绍和业务边界由公共站点数据提供。</p></div></div><div class="prose">{!! $about['content'] !!}</div></div></section>
<section class="section section-soft"><div class="shell feature-layout"><div><p class="eyebrow">工作重点</p><h2>把沟通、规划和维护放在同一个节奏里</h2><ul class="check-list"><li>信息表达清楚，减少理解成本</li><li>方案边界明确，方便团队协作</li><li>技术安排可检查，也方便后续调整</li></ul></div><div class="feature-art"><div class="dashboard-art" aria-hidden="true"></div></div></div></section>
@endsection


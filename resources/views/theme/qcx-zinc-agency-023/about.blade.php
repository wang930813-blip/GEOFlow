@extends('theme.qcx-zinc-agency-023.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($about = $themeData['about'])
@php($activeNav = 'about')
@php($pageTitle = $themeData['seo']['about']['title'])
@php($pageDescription = $themeData['seo']['about']['description'])
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('about'))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">关于我们</p><h1>{{ $about['title'] }}</h1><p class="lead">{{ $about['summary'] }}</p></div></section>
<section class="page-content"><div class="shell split-section"><div class="dark-panel"><div class="dark-panel-content"><p class="eyebrow">服务原则</p><h2>让每一项技术投入都能被理解和使用</h2><p>正式品牌信息、主体介绍和业务边界由公共站点数据提供。</p></div></div><div class="prose">{!! $about['content'] !!}</div></div></section>
<section class="section section-soft"><div class="shell metric-layout"><div><p class="eyebrow">工作重点</p><h2>沟通、规划和维护保持同一个节奏</h2><ul class="check-list"><li>信息表达清楚，减少理解成本</li><li>方案边界明确，方便团队协作</li><li>技术安排可检查，也方便后续调整</li></ul></div><div class="metric-art"><div class="metric-screen"><div class="metric-chart"><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div></div></div></div></section>
@endsection

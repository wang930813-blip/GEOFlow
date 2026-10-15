@extends('theme.qcx-expomarketing-016.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'about')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">关于</p><h1>从内容价值出发，建立可持续的品牌影响力</h1><p class="lead">我们相信增长不是一次活动，而是不断理解用户、表达价值和优化触达的过程。</p></div></section>
<section class="detail-section"><div class="detail-media"><img src="{{ asset('themes/qcx-expomarketing-016/assets/about.png') }}" alt="品牌策略协作插画" width="655" height="645"></div><div class="detail-copy"><p class="eyebrow">方法</p><h2>让品牌表达更清楚，也更容易被记住</h2><p>围绕定位、内容、传播、复盘四个环节组织信息，让服务能力呈现得更清晰。</p><div class="detail-list"><span>减少空泛口号</span><span>突出服务价值</span><span>保留真实动态内容</span></div></div></section>
@endsection

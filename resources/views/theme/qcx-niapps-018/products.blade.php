@extends('theme.qcx-niapps-018.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'products')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">服务</p><h1>围绕增长目标的数字服务</h1><p class="lead">不使用行动按钮，只用清晰卡片呈现服务模块。</p></div></section>
<section class="page-content"><div class="item-grid">@foreach(['产品规划','体验设计','增长分析','项目交付'] as $service)<article class="item-card"><span class="service-index">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><h2>{{ $service }}</h2><p>围绕真实业务信息组织内容，支持项目长期增长。</p></article>@endforeach</div></section>
@endsection

@extends('theme.qcx-expomarketing-016.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'products')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">服务</p><h1>围绕品牌增长的核心服务</h1><p class="lead">用清楚的分工呈现能力，不加入未经确认的价格、套餐和承诺。</p></div></section>
<section class="page-content"><div class="item-grid">@foreach(['数字营销','社交媒体运营','内容创作','网页设计','数据复盘','品牌表达'] as $service)<article class="item-card"><img src="{{ asset('themes/qcx-expomarketing-016/assets/check.svg') }}" alt="" width="28" height="28"><h2>{{ $service }}</h2><p>围绕真实业务信息组织内容，支持品牌长期增长。</p></article>@endforeach</div></section>
@endsection

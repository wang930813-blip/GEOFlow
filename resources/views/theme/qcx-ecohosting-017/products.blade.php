@extends('theme.qcx-ecohosting-017.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'products')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">套餐</p><h1>围绕网站运行的托管能力</h1><p class="lead">不展示未经确认的价格，只呈现可复用的服务能力模块。</p></div></section>
<section class="page-content"><div class="item-grid">@foreach(['云主机托管','安全防护','性能优化','备份恢复'] as $index => $service)<article class="item-card"><img src="{{ asset('themes/qcx-ecohosting-017/assets/services' . (($index % 4) + 1) . '.svg') }}" alt=""><h2>{{ $service }}</h2><p>帮助网站服务保持稳定、清晰和可维护。</p></article>@endforeach</div></section>
@endsection

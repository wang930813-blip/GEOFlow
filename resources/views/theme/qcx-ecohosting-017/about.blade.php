@extends('theme.qcx-ecohosting-017.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'about')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">帮助</p><h1>让托管服务更容易理解和管理</h1><p class="lead">把运行状态、安全策略和日常维护说明清楚，帮助团队减少沟通成本。</p></div></section>
<section class="detail-section"><div class="detail-media"><img src="{{ asset('themes/qcx-ecohosting-017/assets/about1.png') }}" alt="托管服务帮助插画" width="630" height="568"></div><div class="detail-copy"><p class="eyebrow">服务方式</p><h2>稳定托管来自清晰的流程</h2><p>从上线准备到日常运维，每个环节都应该有明确的责任、状态和记录。</p><div class="detail-list"><span>统一管理服务配置</span><span>持续关注运行指标</span><span>重要变更留痕复盘</span></div></div></section>
@endsection

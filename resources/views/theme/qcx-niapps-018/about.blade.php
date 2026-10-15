@extends('theme.qcx-niapps-018.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav = 'about')
@section('content')
<section class="page-hero"><div class="page-header"><p class="eyebrow">关于我们</p><h1>把数字项目做得更清晰、更可执行</h1><p class="lead">Niapps 风格强调清楚的服务表达、醒目的重点强调和项目化的增长路径。</p></div></section>
<section class="detail-section"><div class="detail-media"><img src="{{ asset('themes/qcx-niapps-018/assets/hero-panel.png') }}" alt="数字化协作界面插画" width="680" height="520"></div><div class="detail-copy"><p class="eyebrow">工作方式</p><h2>围绕目标建立服务结构</h2><p>从用户理解到方案设计，从项目推进到数据复盘，每一步都保持可沟通、可追踪、可优化。</p><div class="detail-list"><span>服务内容清楚分层</span><span>项目过程可持续复盘</span><span>重点信息醒目呈现</span></div></div></section>
@endsection

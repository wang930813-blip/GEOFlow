@extends('theme.qcx-scientech-019.layout')
@php($themeData=\App\Support\Site\Template01Data::fromView(get_defined_vars())) @php($activeNav='contact')
@section('content')<section class="page-hero"><div class="page-header"><p class="eyebrow">联系信息</p><h1>保持数据项目沟通清晰</h1><p class="lead">这里只展示已确认公开信息，不以行动按钮、媒体演示或演示表单代替。</p></div></section><section class="page-content contact-details"><dl><div><dt>公开邮箱</dt><dd>暂无公开联系方式。</dd></div><div><dt>办公地点</dt><dd>暂无公开地点信息。</dd></div><div><dt>服务时间</dt><dd>暂无公开服务时间。</dd></div></dl></section>@endsection

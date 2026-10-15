@extends('theme.qcx-zinc-agency-023.layout')
@php($themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars()))
@php($contact = $themeData['contact'])
@php($activeNav = 'contact')
@php($pageTitle = $themeData['seo']['contact']['title'])
@php($pageDescription = $themeData['seo']['contact']['description'])
@php($canonicalUrl = \App\Support\Site\SitePageUrl::to('contact'))
@section('content')
<section class="page-hero"><div class="shell"><p class="eyebrow">联系方式</p><h1>{{ $contact['title'] }}</h1><p class="lead">{{ $contact['summary'] }}</p></div></section>
<section class="page-content"><div class="shell"><div class="contact-grid"><div class="contact-card"><dl><dt>电话</dt><dd>{{ $contact['phone'] ?? '暂无公开联系方式。' }}</dd></dl></div><div class="contact-card"><dl><dt>邮箱</dt><dd>{{ $contact['email'] ?? '暂无公开联系方式。' }}</dd></dl></div><div class="contact-card"><dl><dt>地址</dt><dd>{{ $contact['address'] ?? '暂无公开联系方式。' }}</dd></dl></div><div class="contact-card"><dl><dt>服务时间</dt><dd>{{ $contact['business_hours'] ?? '暂无公开联系方式。' }}</dd></dl></div></div></div></section>
@endsection

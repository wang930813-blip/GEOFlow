@extends('theme.qcx-yango-it-013.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $contact = $themeData['contact'];
    $activeNav = 'contact';
    $pageTitle = $themeData['seo']['contact']['title'];
    $pageDescription = $themeData['seo']['contact']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('contact');
@endphp

@section('content')
<section class="page-content">
    <div class="page-header">
        <p class="eyebrow">联系信息</p>
        <h1>{{ $contact['title'] }}</h1>
        <p class="lead">{{ $contact['summary'] }}</p>
    </div>
    <dl class="contact-list">
        <div class="contact-row"><dt>电话</dt><dd>{{ $contact['phone'] ?: '暂无公开联系方式。' }}</dd></div>
        <div class="contact-row"><dt>邮箱</dt><dd>{{ $contact['email'] ?: '暂无公开联系方式。' }}</dd></div>
        <div class="contact-row"><dt>地址</dt><dd>{{ $contact['address'] ?: '暂无公开联系方式。' }}</dd></div>
        <div class="contact-row"><dt>服务时间</dt><dd>{{ $contact['business_hours'] ?: '暂无公开联系方式。' }}</dd></div>
    </dl>
</section>
@endsection

@extends('theme.qcx-midnight-it-015.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $contact = $themeData['contact'];
    $activeNav = 'contact';
    $pageTitle = $themeData['seo']['contact']['title'];
    $pageDescription = $themeData['seo']['contact']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('contact');
@endphp
@section('content')
<section class="page-banner"><p class="eyebrow">联系我们</p><h1>{{ $contact['title'] }}</h1><p class="lead">{{ $contact['summary'] }}</p></section>
<section class="page-content contact-details" aria-labelledby="contact-details-title"><h2 id="contact-details-title">联系信息</h2>
    <dl>
        <div><dt>电话</dt><dd>{{ $contact['phone'] ?: '暂无公开联系方式。' }}</dd></div>
        <div><dt>邮箱</dt><dd>{{ $contact['email'] ?: '暂无公开联系方式。' }}</dd></div>
        <div><dt>地址</dt><dd>{{ $contact['address'] ?: '暂无公开联系方式。' }}</dd></div>
        <div><dt>服务时间</dt><dd>{{ $contact['business_hours'] ?: '暂无公开联系方式。' }}</dd></div>
    </dl>
</section>
@endsection


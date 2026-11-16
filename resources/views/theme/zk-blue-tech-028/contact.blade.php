@extends('theme.zk-blue-tech-028.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $contact = data_get($themeData, 'contact', []);
    $phone = trim((string) data_get($contact, 'phone', ''));
    $email = trim((string) data_get($contact, 'email', ''));
    $phoneHref = preg_replace('/[^0-9+]/', '', $phone);
@endphp

@section('seo_title', data_get($themeData, 'seo.contact.title', data_get($contact, 'title')))
@section('seo_description', data_get($themeData, 'seo.contact.description', data_get($contact, 'summary')))

@section('body')
    @include('theme.zk-blue-tech-028.partials.header', ['themeData' => $themeData, 'activeNav' => 'contact'])
    <main id="main-content">
        <section class="page-hero"><div class="section-shell page-hero-inner"><p class="eyebrow eyebrow-light">联系我们</p><h1>{{ data_get($contact, 'title') }}</h1><p class="page-lead">{{ data_get($contact, 'summary') }}</p><span class="page-hero-code" aria-hidden="true">CONTACT</span></div><div class="page-hero-grid" aria-hidden="true"></div></section>
        <section class="contact-section section-shell"><div class="contact-copy"><p class="eyebrow">沟通方式</p><h2>公开信息一览</h2><p class="contact-intro">请通过已确认并公开的渠道联系。</p></div><dl class="contact-list">
            <div><dt>电话</dt><dd>@if($phone)<a href="tel:{{ $phoneHref }}">{{ $phone }}</a>@else暂无公开联系方式。@endif</dd></div>
            <div><dt>邮箱</dt><dd>@if($email)<a href="mailto:{{ $email }}">{{ $email }}</a>@else暂无公开联系方式。@endif</dd></div>
            <div><dt>地址</dt><dd>{{ data_get($contact, 'address') ?: '暂无公开联系方式。' }}</dd></div>
            <div><dt>服务时间</dt><dd>{{ data_get($contact, 'business_hours') ?: '暂无公开联系方式。' }}</dd></div>
        </dl></section>
    </main>
    @include('theme.zk-blue-tech-028.partials.footer', ['themeData' => $themeData])
@endsection

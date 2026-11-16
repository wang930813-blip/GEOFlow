<?php
/**
 * Created by Codex.
 * @Date: 2026-10-22
 * @Time: 17:05:42
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： contact.blade.php
 * @Description: 仅展示公共数据中已确认的公开联系方式。
 */
?>
@extends('theme.zk-violet-cloud-029.layout')
@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $activeNav = 'contact';
    $pageTitle = $themeData['seo']['contact']['title'];
    $pageDescription = $themeData['seo']['contact']['description'];
    $canonicalUrl = \App\Support\Site\SitePageUrl::to('contact');
    $contactRows = [
        ['电话', $themeData['contact']['phone'] ?? ''],
        ['邮箱', $themeData['contact']['email'] ?? ''],
        ['地址', $themeData['contact']['address'] ?? ''],
        ['营业时间', $themeData['contact']['business_hours'] ?? ''],
    ];
    $hasContactDetails = collect($contactRows)->contains(fn ($row) => filled($row[1]));
@endphp
@section('body')
<section class="page-hero reveal">
    <p class="eyebrow">联系我们</p>
    <h1>{{ $themeData['contact']['title'] }}</h1>
    <p class="page-lead">{{ $themeData['contact']['summary'] }}</p>
</section>
<section class="contact-panel section-shell reveal" aria-labelledby="contact-details-title">
    <div class="contact-panel-heading">
        <span class="contact-mark" aria-hidden="true"><i></i><i></i><i></i></span>
        <div><p class="eyebrow">联系信息</p><h2 id="contact-details-title">公开联系方式</h2></div>
    </div>
    @if($hasContactDetails)
        <dl class="contact-list">
            @foreach($contactRows as [$label, $value])
                @if(filled($value))
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endif
            @endforeach
        </dl>
    @else
        <p class="empty-state contact-empty">暂无公开联系方式。</p>
    @endif
</section>
@endsection

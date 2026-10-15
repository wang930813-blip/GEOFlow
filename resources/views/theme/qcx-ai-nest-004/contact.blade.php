@extends('theme.qcx-ai-nest-004.layout')

@php
    $themeData = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $contact = $themeData['contact'];
    $contactCards = collect([
        ['label' => '电话', 'value' => $contact['phone']],
        ['label' => '邮箱', 'value' => $contact['email']],
        ['label' => '地址', 'value' => $contact['address']],
        ['label' => '营业时间', 'value' => $contact['business_hours']],
    ])->filter(static fn (array $item): bool => trim((string) $item['value']) !== '' && ! str_starts_with((string) $item['value'], '暂无'))->values();
@endphp

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">{{ $contact['title'] }}</p>
        <h1>{{ $themeData['site']['brand_name'] }}</h1>
        <p class="lead">{{ $contact['summary'] }}</p>
    </header>
    <section class="section contact-grid">
        <div class="panel">
            <dl class="contact-list">
                @forelse($contactCards as $item)
                    <div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>
                @empty
                    <div><dt>联系方式</dt><dd>暂无公开联系方式</dd></div>
                @endforelse
            </dl>
        </div>
    </section>
</main>
@endsection

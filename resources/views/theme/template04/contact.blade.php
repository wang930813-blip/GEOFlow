@extends('theme.template04.layout')

@php
    $template04 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $contactItems = collect([
        ['label' => '电话', 'value' => $template04['contact']['phone']],
        ['label' => '邮箱', 'value' => $template04['contact']['email']],
        ['label' => '地址', 'value' => $template04['contact']['address']],
    ])->filter(static fn (array $item): bool => trim((string) $item['value']) !== '' && ! str_starts_with((string) $item['value'], '暂无'));
@endphp

@section('content')
<main class="page-shell">
    <header class="page-heading">
        <p class="eyebrow">{{ $template04['contact']['title'] }}</p>
        <h1>从一个清晰的问题开始</h1>
        <p class="lead">{{ $template04['contact']['summary'] }}</p>
    </header>

    <section class="section contact-grid">
        <div class="panel">
            <dl class="contact-list">
                @forelse($contactItems as $item)
                    <div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>
                @empty
                    <div><dt>联系方式</dt><dd>暂无公开联系方式</dd></div>
                @endforelse
            </dl>
        </div>
    </section>
</main>
@endsection

@extends('theme.template01.layout')

@php
    $template01 = \App\Support\Site\Template01Data::fromView(get_defined_vars());
    $contactCards = collect([
        ['label' => '电话', 'value' => $template01['contact']['phone']],
        ['label' => '邮箱', 'value' => $template01['contact']['email']],
        ['label' => '地址', 'value' => $template01['contact']['address']],
        ['label' => '营业时间', 'value' => $template01['contact']['business_hours']],
    ])->filter(static fn (array $item): bool => trim((string) $item['value']) !== '' && ! str_starts_with((string) $item['value'], '暂无'))->values();
@endphp

@section('body')
    @include('theme.template01.partials.header', ['template01' => $template01, 'activeNav' => 'contact'])

    <main id="main-content">
        <header class="page-hero section-dark" id="top" data-hero>
            <div class="container page-hero-inner">
                <div class="page-hero-copy reveal">
                    <p class="eyebrow"><span class="eyebrow-dot"></span>Contact</p>
                    <h1 data-content="contact.title" data-text-animate>{{ $template01['contact']['title'] }}</h1>
                    <p class="page-lead" data-content="contact.summary">{{ $template01['contact']['summary'] }}</p>
                </div>
            </div>
        </header>

        <section class="section section-light contact-section" aria-label="联系信息">
            <div class="container contact-grid">
                <section class="contact-details contact-details-wide reveal" aria-labelledby="contact-details-title">
                    <p class="eyebrow eyebrow-dark"><span class="eyebrow-dot"></span>公开信息</p>
                    <h2 id="contact-details-title">通过公开渠道与我们联系</h2>
                    @if($contactCards->isNotEmpty())
                        <dl class="contact-info-grid">
                            @foreach($contactCards as $item)
                                <div class="contact-info-card">
                                    <dt>{{ $item['label'] }}</dt>
                                    <dd>{{ $item['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                    <p class="contact-note" data-content="site.brand_tagline">{{ $template01['site']['brand_tagline'] }}</p>
                </section>
            </div>
        </section>
    </main>

    @include('theme.template01.partials.footer', ['template01' => $template01])
@endsection
